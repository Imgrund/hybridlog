#!/usr/bin/env python3
"""Incremental Garmin Connect fetcher.

Pulls daily health metrics and activities via the unofficial Garmin
Connect web API (python-garminconnect), archives every raw JSON response
under data/raw/ and upserts normalized rows into one athlete's mirror
schema in the project's PostgreSQL database.

Which athlete is what --tenant says. An installation holds one mirror
schema per user, `garmin_t{id}` for the id in the app's users table, and
one Garmin session row per user in garmin_private.garmin_session under
the same id. Everything below is written against unqualified table names
and reaches the right schema through search_path, so the tenant is set
once, in connect(), and never mentioned again.

One thing here does not come from Garmin: the hourly weather for a
configured location, from Open-Meteo. It runs before the login and
independently of it, because heat is half the explanation for a bad
night and a drifting heart rate, and Garmin never hands that over.

Being an unofficial API, every endpoint call is wrapped defensively:
a failing endpoint is logged into fetch_log and skipped, it never
aborts the whole run.

The Garmin session comes from the database as well (garmin_session, put
there by login.py), so this process needs no writable disk of its own.

Configuration (environment, or the project .env for local work):
    GARMIN_FETCH_DSN   connection string for the writing role
                       (postgres://garmin_fetch:...@host:5432/garmin)
    DATABASE_URL       fallback when there is no separate fetch role
    GARMIN_RAW_DIR     where raw JSON is archived; empty disables it
    WEATHER_LAT        fallback location for the weather mirror, decimal
    WEATHER_LON        degrees; both unset and without --lat/--lon, no
                       weather is fetched at all. An athlete who has named
                       their own place is fetched with --lat/--lon instead,
                       which is how two athletes on one installation get
                       two skies.

With none of them set, libpq's own PGHOST/PGUSER/PGDATABASE apply.

Usage:
    ./venv/bin/python fetch.py                       # tenant 1, last 7 days
    ./venv/bin/python fetch.py --tenant 2 --days 30
    ./venv/bin/python fetch.py --backfill 2026-05-01 # from date until today
    ./venv/bin/python fetch.py --schema-only         # build the mirror, fetch nothing
    ./venv/bin/python fetch.py --weather-only        # weather only, no Garmin login
    ./venv/bin/python fetch.py --laps-backfill 200   # lap splits for old activities
"""

import argparse
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request
from datetime import UTC, date, datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

import psycopg
from dotenv import load_dotenv
from garminconnect import Garmin, GarminConnectTooManyRequestsError
from psycopg.types.json import Json

BASE_DIR = Path(__file__).resolve().parent
PROJECT_DIR = BASE_DIR.parent
SCHEMA_PATH = BASE_DIR / "schema.sql"

# Only still here to import an installation that predates garmin_session.
# Nothing writes to it any more.
TOKEN_DIR = BASE_DIR / ".tokens"

# The fetcher runs as its own process, often from cron or a scheduler that
# inherits nothing. Real environment variables win; the .env only fills the
# gaps, so a PaaS deployment never reads a file that is not there anyway.
load_dotenv(PROJECT_DIR / ".env", override=False)

# Raw JSON archive. Useful locally for re-deriving a field without asking
# Garmin again, pointless on a platform whose disk is discarded on every
# deploy - hence GARMIN_RAW_DIR="" to switch it off.
_raw = os.environ.get("GARMIN_RAW_DIR", str(PROJECT_DIR / "data" / "raw"))
RAW_DIR = Path(_raw) if _raw else None

# The athlete's calendar, not the container's. Garmin dates its days in the
# watch's local time and the dashboard reads every stamp this fetcher writes
# as wall-clock time in the app's timezone, so both processes have to agree
# on one zone. A container left on UTC otherwise reported an evening fetch
# two hours early and, past 22:00 local, went looking for yesterday.
APP_TZ = ZoneInfo(os.environ.get("APP_TIMEZONE", "Europe/Berlin"))

CALL_DELAY_S = 0.4  # be gentle with the unofficial API

STRENGTH_TYPE_KEYS = {"strength_training", "indoor_cardio", "hiit", "fitness_equipment"}


# ---------------------------------------------------------------- database

def mirror_schema(tenant: int) -> str:
    """The schema holding one athlete's mirror.

    Kept as a function rather than an f-string at every call site because
    App\\Garmin\\Mirror on the PHP side spells the same rule, and a name
    two languages have to agree on is worth writing down once per language.
    """
    return f"garmin_t{tenant}"


def connect(tenant: int = 1) -> psycopg.Connection:
    """Open the mirror connection, pointed at one tenant's schema.

    autocommit is on deliberately. Postgres aborts a whole transaction on
    the first failing statement, so one unexpected value on day 3 of a
    90-day backfill would take the other 89 days down with it. Committing
    per statement keeps this fetcher's promise that a bad response costs
    exactly the row it belongs to.
    """
    dsn = os.environ.get("GARMIN_FETCH_DSN") or os.environ.get("DATABASE_URL") or ""
    conn = psycopg.connect(dsn, autocommit=True)
    # Not a duplicate of the ALTER ROLE ... SET search_path in
    # database/postgres/roles.sql: that one only applies when the role split
    # was actually set up, and it could not name a tenant anyway. This is
    # also what makes every unqualified table name below reach one athlete's
    # schema and no other. A schema that does not exist yet is not an error,
    # so this is fine before load_schema().
    conn.execute(f"SET search_path = {mirror_schema(tenant)}, garmin_private")
    return conn


def load_schema(conn: psycopg.Connection, tenant: int = 1) -> None:
    """Create whatever is missing. Every statement in schema.sql is
    idempotent, so this runs on every fetch and needs no migration state.

    This replaces the SQLite-era ensure_columns() helper, which existed
    only because CREATE TABLE IF NOT EXISTS never alters an existing
    table and SQLite has no ADD COLUMN IF NOT EXISTS.

    The file names no schema of its own: `{mirror}` is substituted here
    with this tenant's, which is what lets one file build every athlete's
    mirror. garmin_private is spelled out in the file and stays shared.
    """
    conn.execute(SCHEMA_PATH.read_text().replace("{mirror}", mirror_schema(tenant)))


def upsert(conn: psycopg.Connection, table: str, keys: tuple[str, ...], row: dict) -> None:
    """Insert one row, or update the columns given if the key is taken.

    Columns absent from `row` keep the value they already have. That is
    the point: several endpoints write into the same `days` row, and
    SQLite's INSERT OR REPLACE deleted the old row first, so every writer
    had to re-supply columns it knows nothing about. The old code did that
    with a COALESCE sub-select per foreign column, and it only worked
    because the writers ran in the right order within one fetch.

    Table and column names come from this file, never from the API
    payloads, which is why interpolating them is safe. The values are
    bound.
    """
    cols = list(row)
    updates = [c for c in cols if c not in keys]
    sql = (
        f"INSERT INTO {table} ({', '.join(cols)}) "
        f"VALUES ({', '.join(['%s'] * len(cols))}) "
        f"ON CONFLICT ({', '.join(keys)}) "
    )
    sql += (
        "DO UPDATE SET " + ", ".join(f"{c} = EXCLUDED.{c}" for c in updates)
        if updates
        else "DO NOTHING"
    )
    conn.execute(sql, list(row.values()))


# ---------------------------------------------------------------- helpers

def deep_find(obj, key):
    """Return the first value for `key` anywhere in a nested dict/list."""
    if isinstance(obj, dict):
        if key in obj and obj[key] is not None:
            return obj[key]
        for v in obj.values():
            found = deep_find(v, key)
            if found is not None:
                return found
    elif isinstance(obj, list):
        for item in obj:
            found = deep_find(item, key)
            if found is not None:
                return found
    return None


def save_raw(day: str, kind: str, payload) -> None:
    if RAW_DIR is None:
        return
    d = RAW_DIR / day
    d.mkdir(parents=True, exist_ok=True)
    (d / f"{kind}.json").write_text(json.dumps(payload, ensure_ascii=False))


def now() -> str:
    # Written without the offset, the way every stamp before it was: the
    # column is text and `max(fetched_at)` compares it as text, so one row
    # in a different shape would break the ordering rather than improve it.
    return datetime.now(APP_TZ).replace(tzinfo=None).isoformat(timespec="seconds")


def local_today() -> date:
    return datetime.now(APP_TZ).date()


# ----------------------------------------------------------- the session

def load_tokens(conn: psycopg.Connection, tenant: int = 1) -> str | None:
    """Return this tenant's stored Garmin token store, or None if there is none.

    An installation from before this table kept the tokens in the directory
    fetcher/.tokens. Sending its owner through another login with an MFA
    code would be a poor way to greet an upgrade, so the first run imports
    that file; from then on the database holds the only copy.

    The legacy import is tenant 1's alone. That directory belonged to the
    single athlete this installation was built for, and handing their Garmin
    session to whoever fetches next would be the worst kind of adoption.
    """
    row = conn.execute(
        "SELECT tokens FROM garmin_private.garmin_session WHERE id = %s", (tenant,)
    ).fetchone()
    if row:
        return row[0]

    legacy = TOKEN_DIR / "garmin_tokens.json"
    if tenant == 1 and legacy.is_file():
        tokens = legacy.read_text()
        save_tokens(conn, tokens, tenant)
        print(f"Imported the token store from {legacy} into the database.")
        return tokens
    return None


def save_tokens(conn: psycopg.Connection, tokens: str, tenant: int = 1) -> None:
    """Store the token store, replacing whatever was there.

    Called after every login, not only after login.py. Garmin's refresh
    endpoint hands back a new refresh token and invalidates the one just
    used, and the library only writes that back when it was given a
    directory to write to. Given a string it keeps the new token in memory
    and forgets it on exit, which would leave the database holding the
    token that no longer works.
    """
    # A real token store is around 2 kB, so anything this short is a fragment
    # rather than a session. The 512 is a leftover: garminconnect used to tell
    # a path from token data by length alone, and that was the line. Since
    # 0.3.10 it decides structurally instead (_looks_like_json: does the string
    # open with a brace or a bracket). The guard is still worth having, only
    # what it prevents has changed shape. A truncated store opens with a brace
    # too, so the library now reads it as token data, fails to parse it, keeps
    # that failure in a debug log and falls through to a credential login this
    # fetcher has no credentials for. What the operator would be told is
    # "Username and password are required", which points at everything except
    # the truncated store.
    if len(tokens) <= 512:
        raise ValueError(
            f"Refusing to store a {len(tokens)}-character token store: "
            "a real one is around 2 kB, so this one arrived truncated."
        )

    upsert(conn, "garmin_private.garmin_session", ("id",), {
        "id": tenant,
        "tokens": tokens,
        "updated_at": now(),
    })


class NotConnected(Exception):
    """No Garmin session has ever been stored.

    Its own class because the dashboard has to tell this apart from a
    session that expired: one is answered by connecting for the first
    time, the other by signing in again. The class name is what survives
    into fetch_log.error, so it is the app's match string.
    """


class RateLimited(Exception):
    """Garmin is throttling this client, by status code or by Cloudflare.

    Its own class because the right reaction inverts this file's usual
    rule: any other endpoint failure is logged and the run moves on, this
    one aborts the whole run, because every further call from a throttled
    client extends the throttling. The endpoint's own error is already in
    fetch_log by the time this is raised, so nothing is lost by leaving.
    """


# What throttling looks like from here. The typed exception is the clean
# signal; the string markers catch the same condition arriving as a bare
# HTTP error, and Cloudflare's challenge page, which answers long before
# python-garminconnect gets to classify anything.
RATE_LIMIT_MARKERS = ("too many requests", "just a moment", "cf-ray", "cloudflare")


def rate_limited(exc: Exception) -> bool:
    if isinstance(exc, GarminConnectTooManyRequestsError):
        return True
    text = f"{type(exc).__name__}: {exc}".lower()
    return any(marker in text for marker in RATE_LIMIT_MARKERS) or re.search(r"\b429\b", text) is not None


def record_login_failure(conn: psycopg.Connection, exc: Exception) -> None:
    """Persist a failed Garmin login into fetch_log (kind='login', ok=0).

    Without this row the dashboard cannot tell "stale because the login
    broke" from "stale because nothing new arrived" - the process used
    to die before writing anything."""
    upsert(conn, "fetch_log", ("date", "kind"), {
        "date": local_today().isoformat(),
        "kind": "login",
        "ok": 0,
        "error": f"{type(exc).__name__}: {exc}",
        "fetched_at": now(),
    })


def local_ts(value) -> str | None:
    """Normalize Garmin's *TimestampLocal fields to 'YYYY-MM-DD HH:MM:SS'.

    Garmin encodes local wall-clock time as a GMT epoch in milliseconds,
    so the value must be formatted as UTC, not shifted to the host zone.
    """
    if value is None or value == "":
        return None
    if isinstance(value, (int, float)):
        return datetime.utcfromtimestamp(value / 1000.0).strftime("%Y-%m-%d %H:%M:%S")
    return str(value)


def tz_offset_ms(payload: dict) -> int | None:
    """Local minus GMT for the day this payload covers, taken from the payload.

    Intraday arrays carry true GMT epochs, unlike the *TimestampLocal scalars
    this API uses elsewhere. Reading the offset off the payload keeps daylight
    saving correct without a timezone database. Returns None when the payload
    does not state it, in which case the caller must skip rather than guess.
    """
    gmt, local = payload.get("startTimestampGMT"), payload.get("startTimestampLocal")
    if not gmt or not local:
        return None
    fmt = "%Y-%m-%dT%H:%M:%S.%f"
    try:
        delta = datetime.strptime(local, fmt) - datetime.strptime(gmt, fmt)
    except (ValueError, TypeError):
        return None
    return int(delta.total_seconds() * 1000)


class Fetcher:
    def __init__(self, api: Garmin, conn: psycopg.Connection):
        self.api = api
        self.conn = conn

    def upsert(self, table: str, keys: tuple[str, ...], row: dict) -> None:
        upsert(self.conn, table, keys, row)

    def call(self, day: str, kind: str, fn, *args, **kwargs):
        """Call one endpoint defensively; archive raw JSON; log outcome."""
        time.sleep(CALL_DELAY_S)
        try:
            payload = fn(*args, **kwargs)
            if payload is not None:
                save_raw(day, kind, payload)
                self.keep_raw(day, kind, payload)
            self.log(day, kind, True, None)
            return payload
        except Exception as exc:  # noqa: BLE001 - unofficial API, log and move on
            self.log(day, kind, False, f"{type(exc).__name__}: {exc}")
            print(f"  ! {day} {kind}: {type(exc).__name__}: {exc}", file=sys.stderr)
            if rate_limited(exc):
                raise RateLimited(f"{kind}: {exc}") from exc
            return None

    def keep_raw(self, day: str, kind: str, payload) -> None:
        """Keep Garmin's answer whole, next to the reading of it.

        Sits beside save_raw rather than replacing it: the file archive is
        for a developer at a shell, this is for the database the models and
        the language model read. A platform that discards its disk on every
        deploy keeps only this one.

        Deliberately no failure path of its own. An endpoint that answers
        with something psycopg cannot adapt must not cost the day the
        columns that parsed fine, so the miss is logged and the fetch goes
        on; fetch_log still records the call as the success it was.
        """
        try:
            self.upsert("raw_payload", ("date", "kind"), {
                "date": day,
                "kind": kind,
                "payload": Json(payload),
                "fetched_at": now(),
            })
        except Exception as exc:  # noqa: BLE001 - never worth losing a day over
            print(f"  ~ {day} {kind}: raw not kept ({type(exc).__name__})", file=sys.stderr)

    def log(self, day: str, kind: str, ok: bool, error: str | None) -> None:
        self.upsert("fetch_log", ("date", "kind"), {
            "date": day,
            "kind": kind,
            "ok": int(ok),
            "error": error,
            "fetched_at": now(),
        })

    # ------------------------------------------------------------ per day

    def fetch_day(self, d: date) -> None:
        day = d.isoformat()
        print(f"- {day}")

        # stats first, and the dashboard leans on that: it counts this
        # run's fetch_log rows of kind "stats" as days begun, which is the
        # "day 34 of 90" a first-connect backfill prints while it walks
        # (App\Garmin\GarminData::fetchProgress). Reorder or rename it and
        # that line stops moving.
        self.day_stats(day)
        self.day_sleep(day)
        self.day_hrv(day)
        self.day_readiness(day)
        self.day_training_status(day)
        self.day_body_battery(day)
        self.day_intraday(day)
        self.day_respiration(day)
        self.day_spo2(day)
        self.day_vo2max(day)
        self.day_endurance(day)
        self.day_hydration(day)
        self.day_hill_score(day)

    def day_stats(self, day: str) -> None:
        s = self.call(day, "stats", self.api.get_stats, day)
        if not s:
            return
        # Everything this endpoint owns. bb_intraday_json, respiration_*,
        # vo2max_*, sweat_loss_ml, hill_* and spo2_* belong to the other
        # endpoints below and are left alone by being absent here.
        self.upsert("days", ("date",), {
            "date": day,
            "steps": s.get("totalSteps"),
            "distance_m": s.get("totalDistanceMeters"),
            "floors_up": s.get("floorsAscended"),
            "calories_total": s.get("totalKilocalories"),
            "calories_active": s.get("activeKilocalories"),
            "calories_bmr": s.get("bmrKilocalories"),
            "resting_hr": s.get("restingHeartRate"),
            "min_hr": s.get("minHeartRate"),
            "max_hr": s.get("maxHeartRate"),
            "stress_avg": s.get("averageStressLevel"),
            "stress_max": s.get("maxStressLevel"),
            "stress_duration_s": s.get("stressDuration"),
            "rest_stress_duration_s": s.get("restStressDuration"),
            "bb_high": s.get("bodyBatteryHighestValue"),
            "bb_low": s.get("bodyBatteryLowestValue"),
            "bb_charged": s.get("bodyBatteryChargedValue"),
            "bb_drained": s.get("bodyBatteryDrainedValue"),
            "intensity_moderate_min": s.get("moderateIntensityMinutes"),
            "intensity_vigorous_min": s.get("vigorousIntensityMinutes"),
            "sedentary_s": s.get("sedentarySeconds"),
            "active_s": s.get("activeSeconds"),
            "highly_active_s": s.get("highlyActiveSeconds"),
            # stress_avg is a mean and hides its own shape. These four are
            # what it was made of, and activity stress is the one that
            # separates a hard session from a hard day.
            "stress_low_s": s.get("lowStressDuration"),
            "stress_medium_s": s.get("mediumStressDuration"),
            "stress_high_s": s.get("highStressDuration"),
            "stress_activity_s": s.get("activityStressDuration"),
            "stress_qualifier": s.get("stressQualifier"),
            "bb_at_wake": s.get("bodyBatteryAtWakeTime"),
            "bb_during_sleep": s.get("bodyBatteryDuringSleep"),
            "resting_hr_7d_avg": s.get("lastSevenDaysAvgRestingHeartRate"),
            "fetched_at": now(),
        })

    def day_sleep(self, day: str) -> None:
        payload = self.call(day, "sleep", self.api.get_sleep_data, day)
        if not payload:
            return
        dto = payload.get("dailySleepDTO") or {}
        if not dto.get("sleepTimeSeconds"):
            return
        scores = dto.get("sleepScores") or {}
        overall = (scores.get("overall") or {})
        need = dto.get("sleepNeed") or {}
        alignment = dto.get("sleepAlignment") or {}
        spo2 = payload.get("wellnessSpO2SleepSummaryDTO") or {}
        # A list of intervals, one per interruption. Only the count is kept:
        # the intervals themselves are in raw_payload for anyone who wants
        # to know when in the night they clustered.
        disruptions = payload.get("breathingDisruptionData")
        self.upsert("sleep", ("date",), {
            "date": day,
            "start_local": local_ts(dto.get("sleepStartTimestampLocal")),
            "end_local": local_ts(dto.get("sleepEndTimestampLocal")),
            "duration_s": dto.get("sleepTimeSeconds"),
            "deep_s": dto.get("deepSleepSeconds"),
            "light_s": dto.get("lightSleepSeconds"),
            "rem_s": dto.get("remSleepSeconds"),
            "awake_s": dto.get("awakeSleepSeconds"),
            "nap_s": dto.get("napTimeSeconds"),
            "score": overall.get("value"),
            "score_qualifier": overall.get("qualifierKey"),
            "score_components_json": json.dumps(scores, ensure_ascii=False),
            # Garmin's name for this is avgOvernightHrv, at the top level.
            # The two spellings below it were guesses and matched nothing,
            # which is why the column stood empty from the first fetch to
            # 2026-08-16 while the number sat in every payload.
            "avg_sleep_hrv": payload.get("avgOvernightHrv"),
            "respiration_avg": dto.get("averageRespirationValue"),
            "respiration_lowest": dto.get("lowestRespirationValue"),
            "respiration_highest": dto.get("highestRespirationValue"),
            "skin_temp_deviation_c": payload.get("avgSkinTempDeviationC"),
            "avg_stress": dto.get("avgSleepStress"),
            "avg_hr": dto.get("avgHeartRate"),
            "awake_count": dto.get("awakeCount"),
            "restless_moments": payload.get("restlessMomentsCount"),
            "breathing_disruptions": len(disruptions) if isinstance(disruptions, list) else None,
            "breathing_disruption_severity": dto.get("breathingDisruptionSeverity"),
            "spo2_avg": spo2.get("averageSPO2"),
            "spo2_lowest": spo2.get("lowestSPO2"),
            "body_battery_change": payload.get("bodyBatteryChange"),
            "need_actual_min": need.get("actual"),
            "need_baseline_min": need.get("baseline"),
            "midpoint_min": alignment.get("lastSleepMidpointMins"),
            "optimal_window_start_min": alignment.get("optimalSleepWindowStartMins"),
            "optimal_window_end_min": alignment.get("optimalSleepWindowEndMins"),
            "alignment_status": alignment.get("status"),
            "fetched_at": now(),
        })

    def day_hrv(self, day: str) -> None:
        payload = self.call(day, "hrv", self.api.get_hrv_data, day)
        if not payload:
            return
        summary = payload.get("hrvSummary") or {}
        baseline = summary.get("baseline") or {}
        if summary.get("lastNightAvg") is None:
            return
        self.upsert("hrv", ("date",), {
            "date": day,
            "last_night_avg": summary.get("lastNightAvg"),
            "weekly_avg": summary.get("weeklyAvg"),
            "status": summary.get("status"),
            "baseline_low_upper": baseline.get("lowUpper"),
            "baseline_balanced_low": baseline.get("balancedLow"),
            "baseline_balanced_upper": baseline.get("balancedUpper"),
            "baseline_marker": baseline.get("markerValue"),
            "feedback": summary.get("feedbackPhrase"),
            "fetched_at": now(),
        })

    def day_readiness(self, day: str) -> None:
        payload = self.call(
            day, "readiness", self.api.get_morning_training_readiness, day
        )
        if not payload:
            return
        r = payload if isinstance(payload, dict) else (payload[0] if payload else None)
        if not r or r.get("score") is None:
            return
        # The current_* columns belong to current_readiness() below and
        # survive this write untouched.
        self.upsert("readiness", ("date",), {
            "date": day,
            "score": r.get("score"),
            "level": r.get("level"),
            "feedback_short": r.get("feedbackShort"),
            "sleep_score_factor": r.get("sleepScoreFactorPercent"),
            "recovery_time_factor": r.get("recoveryTimeFactorPercent"),
            "acwr_factor": r.get("acwrFactorPercent"),
            "hrv_factor": r.get("hrvFactorPercent"),
            "sleep_history_factor": r.get("sleepHistoryFactorPercent"),
            "stress_history_factor": r.get("stressHistoryFactorPercent"),
            "recovery_time_h": (r.get("recoveryTime") / 60.0) if r.get("recoveryTime") else None,
            # The percents above say how much each input weighed, these say
            # which way it leaned. Together they turn a bare score into the
            # sentence Garmin itself would write about it.
            "feedback_long": r.get("feedbackLong"),
            "sleep_score": r.get("sleepScore"),
            "hrv_weekly_avg": r.get("hrvWeeklyAverage"),
            "acwr_factor_feedback": r.get("acwrFactorFeedback"),
            "hrv_factor_feedback": r.get("hrvFactorFeedback"),
            "sleep_score_factor_feedback": r.get("sleepScoreFactorFeedback"),
            "sleep_history_factor_feedback": r.get("sleepHistoryFactorFeedback"),
            "stress_history_factor_feedback": r.get("stressHistoryFactorFeedback"),
            "recovery_time_factor_feedback": r.get("recoveryTimeFactorFeedback"),
            "fetched_at": now(),
        })

    def current_readiness(self) -> None:
        """Intraday readiness for today. The morning endpoint freezes the
        wake-up state, but the watch recomputes score and recovery time
        after every workout - by the evening the two can be a workout
        apart (seen 2026-07-26: morning 86 / 0 h open, actual 35 / 51 h).
        History stays on the morning values; only today's row carries this.
        """
        today = local_today().isoformat()
        payload = self.call(
            today, "readiness_current", self.api.get_training_readiness, today
        )
        if not payload:
            return
        entries = payload if isinstance(payload, list) else [payload]
        entries = [e for e in entries if isinstance(e, dict) and e.get("score") is not None]
        if not entries:
            return
        # Garmin may return several snapshots for the day; the newest wins.
        r = max(entries, key=lambda e: e.get("timestamp") or "")
        self.upsert("readiness", ("date",), {
            "date": today,
            "current_score": r.get("score"),
            "current_level": r.get("level"),
            "current_feedback_short": r.get("feedbackShort"),
            "current_sleep_score_factor": r.get("sleepScoreFactorPercent"),
            "current_recovery_time_factor": r.get("recoveryTimeFactorPercent"),
            "current_acwr_factor": r.get("acwrFactorPercent"),
            "current_hrv_factor": r.get("hrvFactorPercent"),
            "current_sleep_history_factor": r.get("sleepHistoryFactorPercent"),
            "current_stress_history_factor": r.get("stressHistoryFactorPercent"),
            "current_recovery_time_h": (r.get("recoveryTime") / 60.0) if r.get("recoveryTime") else None,
            "current_at": r.get("timestampLocal") or r.get("timestamp"),
            "fetched_at": now(),
        })

    def device_sync(self) -> None:
        """Last watch-to-Garmin upload. The dashboard shows it beside the
        fetch stamp: a fetch can only mirror what the watch has already
        uploaded, so this is the honest answer to "why is nothing new".
        Unlike the *TimestampLocal fields this epoch is true GMT."""
        today = local_today().isoformat()
        payload = self.call(today, "device_last_used", self.api.get_device_last_used)
        if not isinstance(payload, dict):
            return
        upload_ms = payload.get("lastUsedDeviceUploadTime")
        if not upload_ms:
            return
        self.upsert("device_sync", ("device_key",), {
            "device_key": payload.get("lastUsedDeviceApplicationKey") or "unknown",
            "device_name": payload.get("lastUsedDeviceName"),
            "last_sync_utc": datetime.fromtimestamp(
                upload_ms / 1000.0, UTC
            ).strftime("%Y-%m-%d %H:%M:%S"),
            "fetched_at": now(),
        })

    def day_hydration(self, day: str) -> None:
        """Sweat loss matters even without logged drinks: Garmin estimates
        it per activity, and a 1.7 l hard-session day is worth surfacing. The drunk
        value comes back too, so entries made in Garmin's own app (or
        pushed there by the dashboard) stay visible to the mirror."""
        h = self.call(day, "hydration", self.api.get_hydration_data, day)
        if not h or not isinstance(h, dict):
            return
        self.conn.execute(
            "UPDATE days SET sweat_loss_ml=%s, hydration_goal_ml=%s, hydration_value_ml=%s WHERE date=%s",
            (h.get("sweatLossInML"), h.get("goalInML"), h.get("valueInML"), day),
        )

    def day_hill_score(self, day: str) -> None:
        """Hill score, and the VO2max that rides along with it.

        This endpoint carries vo2Max on days the max_metrics endpoint
        answers with an empty list, which on the account this was traced on
        (2026-08-16) is every day: 30 of 30 hill payloads had the value,
        9 of 139 days had it in the column. So the write below happens even
        when there is no hill score, and it leaves vo2max_running alone
        when this payload has none rather than blanking what day_vo2max
        may already have written.
        """
        payload = self.call(day, "hill_score", self.api.get_hill_score, day)
        if not payload or not isinstance(payload, dict):
            return
        vo2 = payload.get("vo2MaxPreciseValue") or payload.get("vo2Max")
        if payload.get("overallScore") is None and vo2 is None:
            return
        self.conn.execute(
            "UPDATE days SET hill_score=COALESCE(%s, hill_score), "
            "hill_strength=COALESCE(%s, hill_strength), "
            "hill_endurance=COALESCE(%s, hill_endurance), "
            "vo2max_running=COALESCE(%s, vo2max_running) WHERE date=%s",
            (
                payload.get("overallScore"),
                payload.get("strengthScore"),
                payload.get("enduranceScore"),
                vo2,
                day,
            ),
        )

    def day_training_status(self, day: str) -> None:
        payload = self.call(day, "training_status", self.api.get_training_status, day)
        if not payload:
            return
        acute = deep_find(payload, "dailyTrainingLoadAcute")
        chronic = deep_find(payload, "dailyTrainingLoadChronic")
        acwr = deep_find(payload, "dailyAcuteChronicWorkloadRatio")
        status_key = deep_find(payload, "trainingStatusFeedbackPhrase") or deep_find(
            payload, "trainingStatus"
        )
        balance = deep_find(payload, "mostRecentTrainingLoadBalance")
        if acute is None and chronic is None and status_key is None:
            return
        self.upsert("training_status", ("date",), {
            "date": day,
            "status_key": str(status_key) if status_key is not None else None,
            "acute_load": acute,
            "chronic_load": chronic,
            "acwr": acwr,
            "load_focus_json": json.dumps(balance, ensure_ascii=False) if balance else None,
            # Both sit under a device id in the payload, which is why they
            # are dug out rather than read off a path: the id changes with
            # the watch and a path would break on the next one.
            "balance_feedback": deep_find(payload, "trainingBalanceFeedbackPhrase"),
            "fitness_trend": deep_find(payload, "fitnessTrend"),
            "fitness_trend_sport": deep_find(payload, "fitnessTrendSport"),
            "fetched_at": now(),
        })

    def day_body_battery(self, day: str) -> None:
        payload = self.call(day, "body_battery", self.api.get_body_battery, day)
        if not payload:
            return
        entry = payload[0] if isinstance(payload, list) and payload else payload
        arr = entry.get("bodyBatteryValuesArray") if isinstance(entry, dict) else None
        if arr:
            self.conn.execute(
                "UPDATE days SET bb_intraday_json = %s WHERE date = %s",
                (json.dumps(arr), day),
            )

    def day_intraday(self, day: str) -> None:
        """Stress, body battery and heart rate at their native resolution.

        `days` holds one stress average per day, which can say a day was hard
        but never what happened around a given hour. Two endpoints carry that:
        dailyStress ships stress and body battery on a 3 minute grid, heart
        rate arrives separately on a 2 minute grid. Both are merged into one
        row per timestamp so a query can ask for a time window directly.

        Rows are upserted, never replaced. A run where one endpoint fails must
        not blank out what an earlier run already stored.
        """
        samples: dict[str, dict] = {}

        def slot(ts_ms: int, offset_ms: int) -> dict:
            ts = datetime.fromtimestamp((ts_ms + offset_ms) / 1000.0, tz=UTC)
            return samples.setdefault(ts.strftime("%Y-%m-%d %H:%M:%S"), {})

        stress = self.call(day, "intraday_stress", self.api.get_all_day_stress, day)
        offset = tz_offset_ms(stress) if isinstance(stress, dict) else None
        if offset is not None:
            for point in stress.get("stressValuesArray") or []:
                if len(point) < 2 or point[1] is None:
                    continue
                row, level = slot(point[0], offset), point[1]
                # -1 means not measurable, -2 means too much motion. Both are
                # information about the gap, so keep the reason and null the value.
                if level < 0:
                    row["stress_marker"] = "motion" if level == -2 else "unmeasurable"
                else:
                    row["stress"] = level
            for point in stress.get("bodyBatteryValuesArray") or []:
                if len(point) < 3 or point[2] is None:
                    continue
                row = slot(point[0], offset)
                row["bb_status"], row["body_battery"] = point[1], point[2]

        hr = self.call(day, "intraday_hr", self.api.get_heart_rates, day)
        # Same calendar day, so the stress payload's offset applies if this one
        # does not state its own. Without either, skip rather than guess.
        hr_offset = tz_offset_ms(hr) if isinstance(hr, dict) else None
        if hr_offset is None and isinstance(hr, dict):
            hr_offset = offset
        if hr_offset is not None:
            for point in hr.get("heartRateValues") or []:
                if len(point) < 2 or point[1] is None:
                    continue
                slot(point[0], hr_offset)["heart_rate"] = point[1]

        if not samples:
            return

        stamp = now()
        # The one write in this file that is not `upsert()`: that helper
        # overwrites every column it is given, and here a NULL from an
        # endpoint that failed must not erase what the other one stored.
        with self.conn.cursor() as cur:
            cur.executemany(
                """INSERT INTO intraday
                   (ts_local, date, stress, stress_marker, body_battery,
                    bb_status, heart_rate, fetched_at)
                   VALUES (%s,%s,%s,%s,%s,%s,%s,%s)
                   ON CONFLICT (ts_local) DO UPDATE SET
                     stress = CASE
                         WHEN EXCLUDED.stress IS NULL AND EXCLUDED.stress_marker IS NULL
                         THEN intraday.stress ELSE EXCLUDED.stress END,
                     stress_marker = CASE
                         WHEN EXCLUDED.stress IS NULL AND EXCLUDED.stress_marker IS NULL
                         THEN intraday.stress_marker ELSE EXCLUDED.stress_marker END,
                     body_battery = COALESCE(EXCLUDED.body_battery, intraday.body_battery),
                     bb_status    = COALESCE(EXCLUDED.bb_status, intraday.bb_status),
                     heart_rate   = COALESCE(EXCLUDED.heart_rate, intraday.heart_rate),
                     fetched_at   = EXCLUDED.fetched_at""",
                [
                    (ts, day, r.get("stress"), r.get("stress_marker"),
                     r.get("body_battery"), r.get("bb_status"), r.get("heart_rate"), stamp)
                    for ts, r in sorted(samples.items())
                ],
            )

    def day_respiration(self, day: str) -> None:
        payload = self.call(day, "respiration", self.api.get_respiration_data, day)
        if not payload:
            return
        self.conn.execute(
            "UPDATE days SET respiration_avg = %s, respiration_lowest = %s, "
            "respiration_highest = %s WHERE date = %s",
            (
                payload.get("avgSleepRespirationValue")
                or payload.get("avgWakingRespirationValue"),
                payload.get("lowestRespirationValue"),
                payload.get("highestRespirationValue"),
                day,
            ),
        )

    def day_spo2(self, day: str) -> None:
        """Nightly pulse ox. The endpoint answers with null values while
        the pulse oximeter is disabled on the watch (probed 2026-07-27);
        the row then simply keeps its nulls and the dashboard explains
        how to enable the sensor instead of pretending zeros."""
        payload = self.call(day, "spo2", self.api.get_spo2_data, day)
        if not payload or not isinstance(payload, dict):
            return
        avg = deep_find(payload, "averageSpO2")
        lowest = deep_find(payload, "lowestSpO2")
        if avg is None and lowest is None:
            return
        self.conn.execute(
            "UPDATE days SET spo2_avg = %s, spo2_lowest = %s WHERE date = %s",
            (avg, lowest, day),
        )

    def day_vo2max(self, day: str) -> None:
        payload = self.call(day, "max_metrics", self.api.get_max_metrics, day)
        if not payload:
            return
        entry = payload[0] if isinstance(payload, list) and payload else payload
        generic = deep_find(entry, "generic") or {}
        cycling = entry.get("cycling") if isinstance(entry, dict) else None
        vo2_run = (
            generic.get("vo2MaxPreciseValue") or generic.get("vo2MaxValue")
            if isinstance(generic, dict)
            else None
        )
        vo2_cyc = (
            cycling.get("vo2MaxPreciseValue") or cycling.get("vo2MaxValue")
            if isinstance(cycling, dict)
            else None
        )
        if vo2_run is None and vo2_cyc is None:
            return
        self.conn.execute(
            "UPDATE days SET vo2max_running = %s, vo2max_cycling = %s WHERE date = %s",
            (vo2_run, vo2_cyc, day),
        )

    def day_endurance(self, day: str) -> None:
        payload = self.call(day, "endurance_score", self.api.get_endurance_score, day)
        if not payload:
            return
        score = deep_find(payload, "overallScore") or deep_find(payload, "score")
        classification = deep_find(payload, "classification") or deep_find(
            payload, "classificationKey"
        )
        if score is None:
            return
        self.upsert("endurance_score", ("date",), {
            "date": day,
            "score": score,
            "classification": str(classification) if classification else None,
            "fetched_at": now(),
        })

    # ------------------------------------------------------- range fetches

    def fetch_activities(self, start: date, end: date) -> None:
        day = end.isoformat()
        acts = self.call(
            day,
            "activities",
            self.api.get_activities_by_date,
            start.isoformat(),
            end.isoformat(),
        )
        if not acts:
            return
        print(f"  {len(acts)} activities")
        for a in acts:
            act_id = a.get("activityId")
            if not act_id:
                continue
            type_key = ((a.get("activityType") or {}).get("typeKey")) or ""
            start_local = a.get("startTimeLocal") or ""
            act_date = start_local[:10] if start_local else day
            # hr_zones_json is written by backfill_hr_zones and absent here so that
            # a re-fetch of the activity list does not drop it.
            self.upsert("activities", ("id",), {
                "id": act_id,
                "date": act_date,
                "start_local": start_local,
                "type_key": type_key,
                "name": a.get("activityName"),
                "duration_s": a.get("duration"),
                "moving_s": a.get("movingDuration"),
                "distance_m": a.get("distance"),
                "avg_hr": a.get("averageHR"),
                "max_hr": a.get("maxHR"),
                "calories": a.get("calories"),
                "aerobic_te": a.get("aerobicTrainingEffect"),
                "anaerobic_te": a.get("anaerobicTrainingEffect"),
                "training_load": a.get("activityTrainingLoad"),
                "avg_power": a.get("avgPower"),
                "norm_power": a.get("normPower"),
                "avg_speed_mps": a.get("averageSpeed"),
                "elevation_gain_m": a.get("elevationGain"),
                "total_sets": a.get("totalSets"),
                "active_sets": a.get("activeSets"),
                "total_reps": a.get("totalReps"),
                # Garmin exposes set volume only per summarized category
                "total_volume_g": sum(
                    s.get("volume") or 0
                    for s in (a.get("summarizedExerciseSets") or [])
                ) or None,
                "fetched_at": now(),
            })
            save_raw(act_date, f"activity_{act_id}", a)

            if a.get("totalSets") or type_key in STRENGTH_TYPE_KEYS:
                self.fetch_exercise_sets(act_date, act_id)

    def backfill_hr_zones(self, cap: int = 50) -> None:
        """Fill hr_zones_json where it is missing, newest activities first.

        One extra request per activity, so a cap keeps the first run over
        months of history from doubling the whole fetch; the schedule's
        three runs a day drain that backlog within days, and from then on
        a run only ever sees the week's handful of new activities.
        """
        rows = self.conn.execute(
            "SELECT id, date FROM activities WHERE hr_zones_json IS NULL "
            "ORDER BY date DESC LIMIT %s",
            (cap,),
        ).fetchall()
        for act_id, act_date in rows:
            self.fetch_hr_zones(act_date, act_id)

    def fetch_hr_zones(self, act_date: str, act_id: int) -> None:
        row = self.conn.execute(
            "SELECT hr_zones_json FROM activities WHERE id = %s", (act_id,)
        ).fetchone()
        if row is None or row[0]:
            return
        payload = self.call(
            act_date, f"hr_zones_{act_id}", self.api.get_activity_hr_in_timezones, act_id
        )
        if not payload:
            return
        # The column promises "seconds per HR zone" and gets exactly that:
        # {"1": 62.0, ..., "5": 118.0}. Garmin sends zone boundaries too,
        # but those follow the athlete's current zone setup, so storing
        # them next to historic seconds would fake a precision the data
        # does not have.
        # Only numeric seconds make it in: if Garmin ever renames the
        # field, the dict stays empty, the column stays null and the next
        # run tries again, with the raw payload archived for inspection.
        zones = {
            str(z["zoneNumber"]): z["secsInZone"]
            for z in payload
            if isinstance(z, dict)
            and z.get("zoneNumber") is not None
            and isinstance(z.get("secsInZone"), (int, float))
        }
        if not zones:
            return
        self.upsert("activities", ("id",), {
            "id": act_id,
            "hr_zones_json": json.dumps(zones),
        })

    def backfill_laps(self, cap: int = 10) -> None:
        """Fill activity_laps where an activity has none, newest first.

        Same budget shape as backfill_hr_zones: one extra request per
        activity, capped so a normal run never doubles itself, and the
        three scheduled runs a day drain a fresh backlog on their own.
        The default cap is smaller because laps only pay off on race-style
        sessions, while HR zones feed every load model.

        An activity keeps being retried while it has no rows, which is
        safe here: every probed activity type answers with at least one
        lap (verified 2026-08-02), so a retry loop only occurs if Garmin
        changes the payload shape, and then the raw archive shows how.
        """
        rows = self.conn.execute(
            "SELECT a.id, a.date FROM activities a "
            "WHERE NOT EXISTS (SELECT 1 FROM activity_laps l WHERE l.activity_id = a.id) "
            "ORDER BY a.date DESC LIMIT %s",
            (cap,),
        ).fetchall()
        for act_id, act_date in rows:
            self.fetch_activity_laps(act_date, act_id)

    def fetch_activity_laps(self, act_date: str, act_id: int) -> None:
        existing = self.conn.execute(
            "SELECT COUNT(*) FROM activity_laps WHERE activity_id = %s", (act_id,)
        ).fetchone()[0]
        if existing:
            return
        payload = self.call(
            act_date, f"laps_{act_id}", self.api.get_activity_splits, act_id
        )
        if not isinstance(payload, dict):
            return
        for lap in payload.get("lapDTOs") or []:
            idx = lap.get("lapIndex")
            if idx is None:
                continue
            # "2026-07-26T08:02:35.0" -> "2026-07-26 08:02:35". GMT, and
            # stored as such: the splits payload has no local variant.
            start = str(lap.get("startTimeGMT") or "")
            start_gmt = start.replace("T", " ").split(".")[0] or None
            avg_hr = lap.get("averageHR")
            max_hr = lap.get("maxHR")
            self.upsert("activity_laps", ("activity_id", "lap_index"), {
                "activity_id": act_id,
                "lap_index": int(idx),
                "start_gmt": start_gmt,
                "duration_s": lap.get("duration"),
                "moving_s": lap.get("movingDuration"),
                "distance_m": lap.get("distance"),
                # Delivered as floats that are whole numbers (125.0).
                "avg_hr": int(round(avg_hr)) if avg_hr is not None else None,
                "max_hr": int(round(max_hr)) if max_hr is not None else None,
                "avg_speed_mps": lap.get("averageSpeed"),
                "fetched_at": now(),
            })

    def fetch_exercise_sets(self, act_date: str, act_id: int) -> None:
        existing = self.conn.execute(
            "SELECT COUNT(*) FROM strength_sets WHERE activity_id = %s", (act_id,)
        ).fetchone()[0]
        if existing:
            return
        payload = self.call(
            act_date, f"sets_{act_id}", self.api.get_activity_exercise_sets, act_id
        )
        if not payload:
            return
        sets = payload.get("exerciseSets") if isinstance(payload, dict) else payload
        if not sets:
            return
        for i, s in enumerate(sets):
            exercises = s.get("exercises") or []
            first = exercises[0] if exercises else {}
            self.upsert("strength_sets", ("activity_id", "set_index"), {
                "activity_id": act_id,
                "set_index": i,
                "exercise_category": first.get("category"),
                "exercise_name": first.get("name"),
                "set_type": s.get("setType"),
                "reps": s.get("repetitionCount"),
                "weight_g": s.get("weight"),
                "duration_s": s.get("duration"),
                "start_local": s.get("startTime"),
            })

    def fetch_snapshots(self, start: date, end: date) -> None:
        today = end.isoformat()

        rp = self.call(today, "race_predictions", self.api.get_race_predictions)
        if rp:
            entry = rp[0] if isinstance(rp, list) and rp else rp
            mapping = {
                "5K": deep_find(entry, "time5K"),
                "10K": deep_find(entry, "time10K"),
                "HALF": deep_find(entry, "timeHalfMarathon"),
                "MARATHON": deep_find(entry, "timeMarathon"),
            }
            for dist, seconds in mapping.items():
                if seconds:
                    self.upsert("race_predictions", ("date", "distance"), {
                        "date": today,
                        "distance": dist,
                        "seconds": int(seconds),
                    })

        fa = self.call(today, "fitness_age", self.api.get_fitnessage_data, today)
        if fa:
            self.upsert("fitness_age", ("date",), {
                "date": today,
                "chronological_age": deep_find(fa, "chronologicalAge"),
                "fitness_age": deep_find(fa, "fitnessAge") or deep_find(fa, "achievableFitnessAge"),
                "achievable_age": deep_find(fa, "achievableFitnessAge"),
                "fetched_at": now(),
            })

        # Heart configuration: zone floors plus lactate threshold. Both are
        # slow-moving settings/estimates, one snapshot row per fetch day.
        zones = self.call(today, "heart_rate_zones", self.api.get_heart_rate_zones)
        lt = self.call(today, "lactate_threshold", self.api.get_lactate_threshold)
        z = None
        if isinstance(zones, list) and zones:
            z = next((e for e in zones if e.get("trainingMethod") == "HR_MAX"), zones[0])
        elif isinstance(zones, dict):
            z = zones
        lt_hr = deep_find(lt, "heartRate") if lt else None
        lt_speed = deep_find(lt, "speed") if lt else None
        if z or lt_hr:
            self.upsert("heart_profile", ("date",), {
                "date": today,
                "max_hr": (z or {}).get("maxHeartRateUsed"),
                "resting_hr_used": (z or {}).get("restingHeartRateUsed"),
                "lthr_bpm": lt_hr or (z or {}).get("lactateThresholdHeartRateUsed"),
                # Garmin delivers tenths of m/s here (0.3667 -> 3.667 m/s).
                "lthr_speed_ms": (lt_speed * 10.0) if lt_speed else None,
                "zone1_floor": (z or {}).get("zone1Floor"),
                "zone2_floor": (z or {}).get("zone2Floor"),
                "zone3_floor": (z or {}).get("zone3Floor"),
                "zone4_floor": (z or {}).get("zone4Floor"),
                "zone5_floor": (z or {}).get("zone5Floor"),
                "fetched_at": now(),
            })

        wi = self.call(
            today, "weigh_ins", self.api.get_weigh_ins, start.isoformat(), end.isoformat()
        )
        if wi:
            for dws in deep_find(wi, "dailyWeightSummaries") or []:
                for m in dws.get("allWeightMetrics") or []:
                    ts = m.get("timestampGMT") or m.get("date")
                    if not ts:
                        continue
                    self.upsert("body_comp", ("ts",), {
                        "ts": str(ts),
                        "date": dws.get("summaryDate"),
                        "weight_g": m.get("weight"),
                        "bmi": m.get("bmi"),
                        "body_fat_pct": m.get("bodyFat"),
                        "muscle_mass_g": m.get("muscleMass"),
                        "body_water_pct": m.get("bodyWater"),
                        "bone_mass_g": m.get("boneMass"),
                        "source": m.get("sourceType"),
                    })


# --------------------------------------------------------------- weather

# What the body was working against. Not a Garmin endpoint: Open-Meteo,
# which asks for no key, serves the ERA5 archive and the forecast from the
# same shape of response, and is free for non-commercial use (verified
# 2026-08-01: 10.000 calls a day, no key parameter). This fetcher spends
# one or two of those per run whatever the range, because the whole span
# arrives in a single request per source.
WEATHER_ARCHIVE_URL = "https://archive-api.open-meteo.com/v1/archive"
WEATHER_FORECAST_URL = "https://api.open-meteo.com/v1/forecast"

# The archive is a reanalysis and runs about five days behind. Seven days
# of margin, and the forecast endpoint covers the rest.
WEATHER_ARCHIVE_LAG_DAYS = 7

# How far back the forecast endpoint will look. Anything older is the
# archive's job anyway.
WEATHER_FORECAST_PAST_DAYS = 92

# How far ahead to mirror, counting today. Three days is what a training
# week is actually planned over; the endpoint would hand over sixteen,
# and a forecast that far out says less than the athlete's own habits do.
# Every one of these hours is later overwritten by the archive, which is
# why the source column exists.
WEATHER_FORECAST_AHEAD_DAYS = 3

WEATHER_VARS = [
    "temperature_2m",
    "apparent_temperature",
    "relative_humidity_2m",
    "dew_point_2m",
    "wind_speed_10m",
    "precipitation",
    "surface_pressure",
    "cloud_cover",
]

# ERA5 carries no UV index, so it is asked for only where it exists. A
# missing column comes back absent rather than as an error.
WEATHER_FORECAST_VARS = WEATHER_VARS + ["uv_index"]

WEATHER_COLUMNS = {
    "temperature_2m": "temperature_c",
    "apparent_temperature": "apparent_c",
    "relative_humidity_2m": "relative_humidity",
    "dew_point_2m": "dewpoint_c",
    "wind_speed_10m": "wind_speed_kmh",
    "precipitation": "precipitation_mm",
    "uv_index": "uv_index",
    "surface_pressure": "surface_pressure_hpa",
    "cloud_cover": "cloud_cover",
}


def weather_location(
    lat_arg: float | None = None, lon_arg: float | None = None
) -> tuple[float, float] | None:
    """Where to read the weather, or None when nowhere is configured.

    The athlete's own place wins when the caller passes one: with two
    athletes on one installation, the environment can only be right for
    one of them. It comes down as --lat/--lon because the profile lives
    in the public schema, which the fetching role has no rights in.

    The environment stays as the fallback, so a single-athlete
    installation that set WEATHER_LAT/WEATHER_LON keeps working with
    nothing to change.

    Deliberately without a default beyond that. Coordinates are the one
    thing here that leaves the installation, and a hard-coded fallback
    would send someone else's location to a third party because they
    never read this part of the .env.
    """
    if lat_arg is not None and lon_arg is not None:
        return lat_arg, lon_arg

    lat = os.environ.get("WEATHER_LAT", "").strip()
    lon = os.environ.get("WEATHER_LON", "").strip()
    if not lat or not lon:
        return None
    try:
        return float(lat), float(lon)
    except ValueError:
        print(f"  ! WEATHER_LAT/WEATHER_LON are not numbers: {lat!r}, {lon!r}",
              file=sys.stderr)
        return None


def weather_request(url: str, params: dict) -> dict:
    """One Open-Meteo call. urllib rather than requests, so the weather
    path adds no dependency to a fetcher that otherwise needs none."""
    query = urllib.parse.urlencode(params, doseq=True)
    req = urllib.request.Request(
        f"{url}?{query}",
        headers={"User-Agent": "hybridlog-fetcher (https://github.com/Imgrund/hybridlog)"},
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read().decode())


def weather_rows(payload: dict, lat: float, lon: float, source: str) -> list[dict]:
    """Flatten one response into rows. Open-Meteo answers with parallel
    arrays: one `time` list and one list per variable, aligned by index."""
    hourly = payload.get("hourly") or {}
    times = hourly.get("time") or []
    stamp = now()
    rows = []
    for i, t in enumerate(times):
        # "2026-07-20T23:00" in the location's own wall clock, because the
        # request asks for timezone=auto. The mirror's other tables store
        # local time the same way, so the two can be compared directly.
        ts_local = t.replace("T", " ")
        if len(ts_local) == 16:
            ts_local += ":00"
        row = {
            "ts_local": ts_local,
            "date": ts_local[:10],
            "latitude": lat,
            "longitude": lon,
            "source": source,
            "fetched_at": stamp,
        }
        empty = True
        for api_name, column in WEATHER_COLUMNS.items():
            values = hourly.get(api_name)
            value = values[i] if values and i < len(values) else None
            row[column] = value
            if value is not None:
                empty = False
        # A future hour the forecast has not filled, or a gap in the
        # archive. Storing it would look like a measured calm.
        if not empty:
            rows.append(row)
    return rows


def weather_store(conn: psycopg.Connection, rows: list[dict]) -> None:
    """Write a whole response at once. A year of hours is 8.760 rows, and
    one statement each would turn a backfill into a minutes-long parade of
    round trips for data that is a few hundred kilobytes."""
    if not rows:
        return
    cols = list(rows[0])
    updates = [c for c in cols if c != "ts_local"]
    sql = (
        f"INSERT INTO weather_hourly ({', '.join(cols)}) "
        f"VALUES ({', '.join(['%s'] * len(cols))}) "
        "ON CONFLICT (ts_local) DO UPDATE SET "
        + ", ".join(f"{c} = EXCLUDED.{c}" for c in updates)
    )
    with conn.cursor() as cur:
        cur.executemany(sql, [list(r.values()) for r in rows])


def weather_backfill_start(conn: psycopg.Connection) -> date | None:
    """The oldest day the mirror already knows about.

    Weather is worth having for every day that carries a night or a
    session, not just from the day this feature was installed: the whole
    point is a window long enough to say anything, and the archive hands
    over a year as cheaply as a week.
    """
    row = conn.execute(
        "SELECT min(d) FROM ("
        "  SELECT min(date) AS d FROM days"
        "  UNION ALL SELECT min(date) FROM sleep"
        "  UNION ALL SELECT min(date) FROM activities"
        ") AS m"
    ).fetchone()
    if not row or not row[0]:
        return None
    try:
        return date.fromisoformat(row[0])
    except ValueError:
        return None


def fetch_weather(
    conn: psycopg.Connection,
    start: date,
    end: date,
    lat_arg: float | None = None,
    lon_arg: float | None = None,
) -> None:
    """Mirror the hours between two dates for this athlete's location.

    Runs before the Garmin login and independently of it: the weather does
    not need a session, and an expired Garmin token should not also cost
    the context that explains the nights already in the mirror.
    """
    location = weather_location(lat_arg, lon_arg)
    if location is None:
        print("- weather: no location configured (--lat/--lon or "
              "WEATHER_LAT/WEATHER_LON), skipped")
        return
    lat, lon = location

    # Nothing mirrored yet: reach back over everything the mirror holds,
    # so the window starts filling from the athlete's history rather than
    # from today.
    have = conn.execute("SELECT count(*) FROM weather_hourly").fetchone()
    if have and not have[0]:
        oldest = weather_backfill_start(conn)
        if oldest and oldest < start:
            print(f"- weather: first run, backfilling from {oldest}")
            start = oldest

    today = local_today()
    end = min(end, today)
    archive_end = min(end, today - timedelta(days=WEATHER_ARCHIVE_LAG_DAYS))
    # The forecast half runs past today on purpose: what tomorrow is going
    # to be is the only weather anyone can still act on.
    ahead = today + timedelta(days=WEATHER_FORECAST_AHEAD_DAYS - 1)
    base = {"latitude": lat, "longitude": lon, "timezone": "auto"}

    if start <= archive_end:
        try:
            payload = weather_request(WEATHER_ARCHIVE_URL, base | {
                "start_date": start.isoformat(),
                "end_date": archive_end.isoformat(),
                "hourly": ",".join(WEATHER_VARS),
            })
            rows = weather_rows(payload, lat, lon, "archive")
            weather_store(conn, rows)
            print(f"- weather: {len(rows)} archive hours, {start} .. {archive_end}")
            upsert(conn, "fetch_log", ("date", "kind"), {
                "date": end.isoformat(), "kind": "weather_archive",
                "ok": 1, "error": None, "fetched_at": now(),
            })
        except Exception as exc:  # noqa: BLE001 - a third party, log and move on
            print(f"  ! weather archive: {type(exc).__name__}: {exc}", file=sys.stderr)
            upsert(conn, "fetch_log", ("date", "kind"), {
                "date": end.isoformat(), "kind": "weather_archive",
                "ok": 0, "error": f"{type(exc).__name__}: {exc}", "fetched_at": now(),
            })

    recent_start = max(start, archive_end + timedelta(days=1))
    if recent_start <= ahead:
        past_days = min(max((today - recent_start).days, 0), WEATHER_FORECAST_PAST_DAYS)
        try:
            payload = weather_request(WEATHER_FORECAST_URL, base | {
                "past_days": past_days,
                "forecast_days": WEATHER_FORECAST_AHEAD_DAYS,
                "hourly": ",".join(WEATHER_FORECAST_VARS),
            })
            # The forecast endpoint counts whole days back from today, so
            # it hands over more than was asked for. Trimming here keeps
            # an archive hour from being overwritten by a modelled one.
            rows = [r for r in weather_rows(payload, lat, lon, "forecast")
                    if r["date"] >= recent_start.isoformat()]
            weather_store(conn, rows)
            print(f"- weather: {len(rows)} recent hours, {recent_start} .. {ahead}")
            upsert(conn, "fetch_log", ("date", "kind"), {
                "date": end.isoformat(), "kind": "weather",
                "ok": 1, "error": None, "fetched_at": now(),
            })
        except Exception as exc:  # noqa: BLE001 - a third party, log and move on
            print(f"  ! weather: {type(exc).__name__}: {exc}", file=sys.stderr)
            upsert(conn, "fetch_log", ("date", "kind"), {
                "date": end.isoformat(), "kind": "weather",
                "ok": 0, "error": f"{type(exc).__name__}: {exc}", "fetched_at": now(),
            })


# ------------------------------------------------------------------ main

def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--days", type=int, default=7, help="fetch the last N days")
    parser.add_argument("--backfill", type=str, help="fetch from YYYY-MM-DD until today")
    parser.add_argument(
        "--intraday-only",
        action="store_true",
        help="fetch only the intraday series (stress, body battery, heart rate). "
             "Backfilling those over months without re-pulling every other endpoint.",
    )
    parser.add_argument(
        "--laps-backfill", type=int, metavar="N",
        help="fetch lap splits for up to N activities that have none yet, "
             "newest first, and do nothing else. A normal run already tops "
             "up the newest 10; this drains a long history in one go.",
    )
    parser.add_argument(
        "--schema-only", action="store_true",
        help="create or update the mirror's tables, then stop",
    )
    parser.add_argument(
        "--weather-only", action="store_true",
        help="fetch only the weather. Needs no Garmin session, so it also "
             "works while the login is expired.",
    )
    parser.add_argument(
        "--tenant", type=int, default=1, metavar="ID",
        help="the athlete to fetch for: their users.id, which selects the "
             "mirror schema garmin_t{ID} and the Garmin session row of the "
             "same id. Defaults to 1, the installation's first athlete.",
    )
    parser.add_argument(
        "--lat", type=float, metavar="DEG",
        help="latitude to read the weather at, decimal degrees. Overrides "
             "WEATHER_LAT, which can only be right for one athlete once an "
             "installation has two. The dashboard passes the athlete's own "
             "place here; the environment stays the fallback.",
    )
    parser.add_argument(
        "--lon", type=float, metavar="DEG",
        help="longitude to read the weather at. Only takes effect together "
             "with --lat.",
    )
    args = parser.parse_args()

    if args.tenant < 1:
        print("--tenant takes a user id, which is never below 1.", file=sys.stderr)
        return 5

    # Half a coordinate is not a place. Silently keeping the other half
    # from the environment would mix two locations into one point that is
    # neither, so this stops rather than guesses.
    if (args.lat is None) != (args.lon is None):
        print("--lat and --lon go together; one without the other is not a "
              "location.", file=sys.stderr)
        return 5

    if args.lat is not None and not (-90 <= args.lat <= 90 and -180 <= args.lon <= 180):
        print(f"--lat/--lon are out of range: {args.lat}, {args.lon}", file=sys.stderr)
        return 5

    end = local_today()
    if args.backfill:
        start = date.fromisoformat(args.backfill)
    else:
        start = end - timedelta(days=args.days - 1)

    # The database comes first: it holds the Garmin session, and a login
    # failure has to be written somewhere the dashboard can see it.
    try:
        conn = connect(args.tenant)
    except psycopg.Error as exc:
        print(f"Database connection failed: {exc}", file=sys.stderr)
        print("Set GARMIN_FETCH_DSN (or DATABASE_URL) to the mirror database.",
              file=sys.stderr)
        return 4
    load_schema(conn, args.tenant)

    # What a first boot needs and all it needs: with the tables in place
    # every dashboard query returns nothing, without them it raises. The
    # Garmin login can then happen whenever the athlete gets to it, and on
    # a container that is a different moment from the deploy.
    if args.schema_only:
        conn.close()
        print(f"Mirror schema {mirror_schema(args.tenant)} is up to date.")
        return 0

    # Before the login, and outside the single-purpose shortcuts: the
    # weather belongs to no Garmin endpoint, and it is the one part of a
    # run that can still succeed when the Garmin session has expired.
    if not args.intraday_only and not args.laps_backfill:
        fetch_weather(conn, start, end, args.lat, args.lon)

    if args.weather_only:
        conn.close()
        print("Done.")
        return 0

    tokens = load_tokens(conn, args.tenant)
    if tokens is None:
        # Recorded, not merely printed. This exit used to be the one
        # failure the dashboard could not see: the job died here with
        # nothing written, so the page kept saying "no fetch yet" and
        # waited for data that was never going to arrive.
        record_login_failure(conn, NotConnected(
            "No Garmin session stored. Connect the dashboard to Garmin once."
        ))
        conn.close()
        print("No Garmin session stored. Connect to Garmin first.", file=sys.stderr)
        return 2

    api = Garmin()
    try:
        api.login(tokens)
    except Exception as exc:  # noqa: BLE001 - any auth failure must become visible
        record_login_failure(conn, exc)
        print(f"Garmin login failed: {type(exc).__name__}: {exc}", file=sys.stderr)
        print("The stored Garmin session is likely expired - re-run login.py.", file=sys.stderr)
        return 3

    # Whatever the login did to the tokens, the database gets to keep it.
    save_tokens(conn, api.client.dumps(), args.tenant)
    print(f"Logged in as {api.get_full_name()}, fetching {start} .. {end}")

    fetcher = Fetcher(api, conn)

    # Everything below talks to Garmin, and a throttled client must stop
    # talking: see RateLimited. The schema and the weather ran above this
    # block on purpose, they involve no Garmin endpoint. Exit 6 is the
    # contract with garmin:fetch-all, which stops its remaining athletes
    # on it: the throttle belongs to the source address, so the next
    # athlete's run from the same address would only feed it.
    try:
        if args.laps_backfill:
            fetcher.backfill_laps(args.laps_backfill)
            conn.close()
            print("Done.")
            return 0

        d = start
        while d <= end:
            if args.intraday_only:
                print(f"- {d.isoformat()} (intraday)")
                fetcher.day_intraday(d.isoformat())
            else:
                fetcher.fetch_day(d)
            d += timedelta(days=1)

        if not args.intraday_only:
            fetcher.fetch_activities(start, end)
            fetcher.backfill_hr_zones()
            fetcher.backfill_laps()
            fetcher.fetch_snapshots(start, end)
            fetcher.current_readiness()
            fetcher.device_sync()
    except RateLimited as exc:
        print(f"Garmin is throttling this client ({exc}).", file=sys.stderr)
        print(
            "Stopping at once instead of retrying: every further call while "
            "throttled extends the throttle. The next scheduled run resumes "
            "where this one left off.",
            file=sys.stderr,
        )
        conn.close()
        return 6

    conn.close()
    print("Done.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
