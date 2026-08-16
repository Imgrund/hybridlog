#!/usr/bin/env python3
"""Generate 120 days of plausible demo data into one athlete's mirror.

Lets the dashboard be reviewed before the first real fetch, and gives the
README screenshots a source that is not somebody's health record.

The demo replaces the contents of the mirror rather than living in a second
database file the way it did under SQLite. Rows it writes carry
fetched_at='demo', and it refuses to run when the mirror holds anything
else, so a mistyped command cannot wipe real data. Use --force to override,
or point DATABASE_URL at a scratch database to keep both.

--tenant picks whose mirror is filled, the same way fetch.py does, so a
second athlete can be shown the dashboard without being handed the first
one's numbers.

Usage:
    ./venv/bin/python seed_demo.py
    ./venv/bin/python seed_demo.py --tenant 2
    ./venv/bin/python seed_demo.py --force
"""

import argparse
import json
import random
import sys
from datetime import date, datetime, timedelta

from fetch import connect, load_schema, now, upsert

DAYS = 120
rng = random.Random(42)

# Every table seed_demo fills. Listed explicitly rather than discovered from
# the catalog: a future table that this script does not know how to populate
# should not be silently emptied by it.
DEMO_TABLES = (
    "days", "sleep", "hrv", "readiness", "training_status", "activities",
    "strength_sets", "race_predictions", "endurance_score", "body_comp",
    "fitness_age", "fetch_log", "device_sync",
)

# Which fetch_log kinds the demo claims. The names are fetch.py's own, so
# the bookkeeping reads the same whether the rows came from Garmin or from
# here. Per-day kinds match the tables seeded in the day loop; the snapshot
# kinds are not per-day and fetch.py logs them once per run.
LOG_KINDS_DAILY = ("stats", "sleep", "hrv", "training_status")
LOG_KINDS_SNAPSHOT = ("activities", "race_predictions", "endurance_score", "fitness_age")

# Weekly training template: (type_key, name, load_range, duration_min_range)
WEEK_PLAN = {
    0: ("strength_training", "Strength: Lower", (45, 85), (55, 75)),
    1: ("hiit", "Metcon", (90, 150), (35, 55)),
    2: ("running", "Zone-2 Run", (55, 90), (45, 70)),
    3: ("strength_training", "Strength: Upper", (40, 75), (50, 70)),
    4: ("hiit", "Metcon", (85, 145), (35, 55)),
    5: ("running", "Long Run / Hyrox Sim", (120, 200), (70, 110)),
    6: None,  # rest day
}

STRENGTH_EXERCISES = {
    "Lower": [
        ("SQUAT", "BACK_SQUAT", (80, 140)),
        ("DEADLIFT", "DEADLIFT", (100, 160)),
        ("LUNGE", "WALKING_LUNGE", (30, 50)),
        ("HIP_SWING", "KETTLEBELL_SWING", (24, 32)),
        ("CORE", "", (0, 0)),
    ],
    "Upper": [
        ("BENCH_PRESS", "BARBELL_BENCH_PRESS", (70, 100)),
        ("PULL_UP", "PULL_UP", (0, 20)),
        ("SHOULDER_PRESS", "OVERHEAD_PRESS", (40, 60)),
        ("ROW", "BARBELL_ROW", (60, 85)),
        ("OLYMPIC_LIFT", "POWER_CLEAN", (60, 90)),
    ],
}


def grade(percent: float) -> str:
    """Garmin's word for a factor percent, on the thresholds its own
    readiness screen appears to use. Demo data only: the live fetcher takes
    these verbatim from the payload rather than deriving them."""
    if percent >= 80:
        return "VERY_GOOD"
    if percent >= 60:
        return "GOOD"
    if percent >= 40:
        return "MODERATE"
    return "POOR"


def holds_real_data(conn) -> bool:
    """True if any mirror table carries a row this script did not write."""
    for table in ("days", "activities", "sleep"):
        n = conn.execute(
            f"SELECT COUNT(*) FROM {table} "  # noqa: S608 - names are constants above
            "WHERE fetched_at IS DISTINCT FROM 'demo'"
        ).fetchone()[0]
        if n:
            return True
    return False


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--force", action="store_true",
        help="replace the mirror even if it holds data from a real fetch",
    )
    parser.add_argument(
        "--tenant", type=int, default=1, metavar="ID",
        help="the athlete whose mirror is filled: their users.id, which "
             "selects the schema garmin_t{ID}. Defaults to 1.",
    )
    args = parser.parse_args()

    conn = connect(args.tenant)
    load_schema(conn, args.tenant)

    if holds_real_data(conn) and not args.force:
        print(
            "The mirror holds data from a real fetch. Seeding would replace it.\n"
            "Point DATABASE_URL at a scratch database, or pass --force.",
            file=sys.stderr,
        )
        return 1

    # One transaction for the whole seed: either the demo data is complete
    # or the mirror is left exactly as it was.
    with conn.transaction():
        conn.execute(f"TRUNCATE {', '.join(DEMO_TABLES)}")
        seed(conn)

    n_days = conn.execute("SELECT COUNT(*) FROM days").fetchone()[0]
    n_acts = conn.execute("SELECT COUNT(*) FROM activities").fetchone()[0]
    n_sets = conn.execute("SELECT COUNT(*) FROM strength_sets").fetchone()[0]
    conn.close()
    print(f"Demo data written: {n_days} days, {n_acts} activities, {n_sets} strength sets")
    return 0


def seed(conn) -> None:
    today = date.today()
    start = today - timedelta(days=DAYS - 1)
    act_id = 10_000_000

    hrv_baseline = 58.0
    vo2 = 52.0
    endurance = 6900
    rhr_base = 47.0
    loads: list[tuple[date, float]] = []

    d = start
    while d <= today:
        dow = d.weekday()
        stamp = d.isoformat()

        # --- sleep: bedtime jitter creates realistic (ir)regularity
        bed_h = 22.8 + rng.gauss(0, 0.55) + (0.8 if dow in (4, 5) else 0)
        wake_h = 6.6 + rng.gauss(0, 0.35) + (0.9 if dow in (5, 6) else 0)
        sleep_start = datetime.combine(d - timedelta(days=1), datetime.min.time()) + timedelta(hours=bed_h)
        sleep_end = datetime.combine(d, datetime.min.time()) + timedelta(hours=wake_h)
        dur = int((sleep_end - sleep_start).total_seconds())
        deep = int(dur * rng.uniform(0.14, 0.22))
        rem = int(dur * rng.uniform(0.18, 0.26))
        awake = int(dur * rng.uniform(0.02, 0.07))
        light = dur - deep - rem - awake
        short_night = dur < 6.7 * 3600
        score = max(40, min(96, int(78 + (dur / 3600 - 7.3) * 9 - awake / 300 + rng.gauss(0, 5))))
        hrv_night = max(35.0, hrv_baseline + rng.gauss(0, 6) - (6 if short_night else 0))

        # The night as the watch describes it beyond its stages. Everything
        # here hangs off the length of the night and the jitter above, so a
        # short night shows up as warmer skin, more waking and less battery
        # rather than as an unrelated set of numbers.
        midpoint = sleep_start + (sleep_end - sleep_start) / 2
        midpoint_min = midpoint.hour * 60 + midpoint.minute
        window_start, window_end = 30, 470
        skin_temp = round(rng.gauss(0, 0.35) + (0.4 if short_night else 0), 1)
        awake_count = max(0, int(rng.gauss(1.1, 0.9)) + (1 if short_night else 0))

        conn.execute(
            """INSERT INTO sleep (date, start_local, end_local, duration_s, deep_s,
               light_s, rem_s, awake_s, nap_s, score, score_qualifier,
               score_components_json, avg_sleep_hrv, respiration_avg,
               respiration_lowest, respiration_highest, skin_temp_deviation_c,
               avg_stress, avg_hr, awake_count, restless_moments,
               breathing_disruptions, breathing_disruption_severity, spo2_avg,
               spo2_lowest, body_battery_change, need_actual_min,
               need_baseline_min, midpoint_min, optimal_window_start_min,
               optimal_window_end_min, alignment_status, fetched_at)
               VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,
                       %s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
            (
                stamp, sleep_start.isoformat(sep=" "), sleep_end.isoformat(sep=" "),
                dur, deep, light, rem, awake, 0, score,
                "EXCELLENT" if score >= 90 else "GOOD" if score >= 80 else "FAIR" if score >= 60 else "POOR",
                json.dumps({"overall": {"value": score}}),
                round(hrv_night, 1),
                round(rng.uniform(13.2, 15.4), 1),
                round(rng.uniform(11.0, 12.8), 1),
                round(rng.uniform(16.0, 18.5), 1),
                skin_temp,
                round(rng.uniform(11.0, 21.0), 1),
                round(rng.uniform(43.0, 51.0), 1),
                awake_count,
                int(rng.uniform(22, 46)),
                max(0, int(rng.gauss(6, 5))),
                "NONE",
                round(rng.uniform(94.5, 98.0), 1),
                int(rng.uniform(85, 92)),
                max(20, int(70 - (14 if short_night else 0) + rng.gauss(0, 6))),
                420, 440,
                midpoint_min, window_start, window_end,
                "ALIGNED" if window_start <= midpoint_min <= window_end else "LATE",
                "demo",
            ),
        )

        # --- hrv status
        week_vals = [hrv_night]
        rows = conn.execute(
            "SELECT avg_sleep_hrv FROM sleep WHERE date > %s AND date < %s",
            ((d - timedelta(days=7)).isoformat(), stamp),
        ).fetchall()
        week_vals += [r[0] for r in rows if r[0]]
        weekly = sum(week_vals) / len(week_vals)
        status = (
            "BALANCED" if hrv_baseline - 5 <= weekly <= hrv_baseline + 7
            else ("LOW" if weekly < hrv_baseline - 9 else "UNBALANCED")
        )
        conn.execute(
            """INSERT INTO hrv (date, last_night_avg, weekly_avg, status,
               baseline_low_upper, baseline_balanced_low, baseline_balanced_upper,
               baseline_marker, feedback, fetched_at)
               VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
            (stamp, round(hrv_night, 1), round(weekly, 1), status,
             round(hrv_baseline - 9, 1), round(hrv_baseline - 5, 1),
             round(hrv_baseline + 7, 1), round(hrv_baseline, 1), None, "demo"),
        )

        # --- activity of the day
        plan = WEEK_PLAN[dow]
        day_load = 0.0
        if plan and rng.random() > 0.12:  # occasionally skip a session
            type_key, name, load_r, dur_r = plan
            load = rng.uniform(*load_r)
            day_load = load
            dur_min = rng.uniform(*dur_r)
            act_id += 1
            avg_hr = int(rng.uniform(128, 152)) if type_key != "strength_training" else int(rng.uniform(105, 125))
            distance = (
                rng.uniform(8500, 14000) if type_key == "running"
                else (rng.uniform(1000, 2500) if type_key == "hiit" else 0)
            )
            is_strength = type_key == "strength_training"
            variant = "Lower" if "Lower" in name else "Upper"
            sets = rng.randint(14, 22) if is_strength else 0
            reps = rng.randint(55, 110) if is_strength else 0

            conn.execute(
                """INSERT INTO activities (id, date, start_local, type_key, name,
                   duration_s, moving_s, distance_m, avg_hr, max_hr, calories,
                   aerobic_te, anaerobic_te, training_load, avg_power, norm_power,
                   avg_speed_mps, elevation_gain_m, total_sets, active_sets,
                   total_reps, total_volume_g, hr_zones_json, fetched_at)
                   VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
                (
                    act_id, stamp, f"{stamp} {17 + rng.randint(0, 2)}:{rng.randint(10, 55):02d}:00",
                    type_key, name, dur_min * 60, dur_min * 57, distance,
                    avg_hr, avg_hr + rng.randint(18, 35),
                    int(dur_min * rng.uniform(8, 13)),
                    round(rng.uniform(2.0, 3.8), 1),
                    round(rng.uniform(0.3, 2.8), 1) if type_key == "hiit" else round(rng.uniform(0.0, 0.8), 1),
                    round(load, 1), None, None,
                    round(distance / (dur_min * 60), 2) if distance else None,
                    rng.uniform(20, 120) if type_key == "running" else 0,
                    sets or None, sets or None, reps or None, None, None, "demo",
                ),
            )

            if is_strength:
                total_volume = 0.0
                idx = 0
                for cat, ex_name, kg_range in STRENGTH_EXERCISES[variant]:
                    for _ in range(rng.randint(3, 4)):
                        set_reps = rng.randint(3, 12)
                        kg = rng.uniform(*kg_range) if kg_range[1] else 0
                        total_volume += kg * set_reps * 1000
                        conn.execute(
                            """INSERT INTO strength_sets (activity_id, set_index,
                               exercise_category, exercise_name, set_type, reps,
                               weight_g, duration_s, start_local)
                               VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
                            (act_id, idx, cat, ex_name, "ACTIVE", set_reps,
                             kg * 1000 or None, rng.uniform(25, 55), None),
                        )
                        idx += 1
                conn.execute(
                    "UPDATE activities SET total_volume_g = %s WHERE id = %s",
                    (total_volume, act_id),
                )

        loads.append((d, day_load))

        # --- training status (EWMA-ish acute/chronic)
        acute = sum(value for dd, value in loads if dd > d - timedelta(days=7))
        chronic_window = [value for dd, value in loads if dd > d - timedelta(days=28)]
        chronic = sum(chronic_window) / max(1, len(chronic_window)) * 7
        acwr = round(acute / chronic, 2) if chronic > 0 else None
        # The variant number is part of how Garmin writes this, and the
        # column's comment tells readers to match on the prefix. Demo data
        # that said plain "PRODUCTIVE" would make that advice look wrong.
        conn.execute(
            """INSERT INTO training_status (date, status_key, acute_load,
               chronic_load, acwr, load_focus_json, balance_feedback,
               fitness_trend, fitness_trend_sport, fetched_at)
               VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
            (
                stamp,
                "OVERREACHING_4" if (acwr or 1.0) > 1.5
                else "MAINTAINING_1" if (acwr or 1.0) < 0.8
                else f"PRODUCTIVE_{rng.randint(1, 6)}",
                round(acute), round(chronic), acwr, None,
                "AEROBIC_LOW_SHORTAGE" if rng.random() < 0.4 else "BALANCED",
                rng.randint(-1, 4), "RUNNING", "demo",
            ),
        )

        # --- readiness (simple plausible model)
        readiness = int(
            0.45 * score
            + 0.35 * max(0, min(100, (hrv_night - hrv_baseline + 12) * 6))
            + 0.2 * max(0, 100 - (acwr or 1.0) * 55)
        )
        readiness = max(8, min(97, readiness + rng.randint(-4, 4)))
        acwr_factor = int(max(10, 100 - (acwr or 1.0) * 40))
        hrv_factor = int(max(5, min(100, (hrv_night - hrv_baseline + 12) * 6)))
        recovery_factor = rng.randint(55, 100)
        sleep_history = rng.randint(55, 95)
        stress_history = rng.randint(50, 95)
        conn.execute(
            """INSERT INTO readiness (date, score, level, feedback_short,
               sleep_score_factor, recovery_time_factor, acwr_factor, hrv_factor,
               sleep_history_factor, stress_history_factor, recovery_time_h,
               feedback_long, sleep_score, hrv_weekly_avg, acwr_factor_feedback,
               hrv_factor_feedback, sleep_score_factor_feedback,
               sleep_history_factor_feedback, stress_history_factor_feedback,
               recovery_time_factor_feedback, fetched_at)
               VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
            (
                stamp, readiness,
                "PRIME" if readiness >= 95
                else "HIGH" if readiness >= 75
                else "MODERATE" if readiness >= 50
                else "LOW" if readiness >= 25
                else "POOR",
                None, score, recovery_factor,
                acwr_factor, hrv_factor, sleep_history, stress_history,
                round(max(0.0, day_load * rng.uniform(0.25, 0.5)), 1),
                # The reason names the weakest input, which is how Garmin
                # picks it: a score is explained by what dragged it down.
                "LOW_HRV" if hrv_factor < 40
                else "HIGH_ACWR" if acwr_factor < 40
                else "LOW_SLEEP_SCORE" if score < 60
                else "GOOD_RECOVERY",
                score, round(weekly, 1),
                grade(acwr_factor), grade(hrv_factor), grade(score),
                grade(sleep_history), grade(stress_history), grade(recovery_factor),
                "demo",
            ),
        )

        # --- daily summary
        rhr = rhr_base + rng.gauss(0, 1.3) + (1.5 if short_night else 0)
        steps = int(rng.uniform(6000, 9500) + (4500 if plan and day_load else 0))
        bb_high = int(min(100, 60 + score * 0.4 + rng.uniform(-5, 8)))
        bb_low = int(max(5, bb_high - rng.uniform(45, 70)))
        # The stress split has to add up to the total above it, or a reader
        # comparing the two finds a contradiction the real mirror never has.
        stress_dur = rng.randint(10000, 26000)
        rest_stress = rng.randint(20000, 40000)
        stress_high = int(stress_dur * rng.uniform(0.02, 0.10))
        stress_medium = int(stress_dur * rng.uniform(0.15, 0.30))
        stress_low = stress_dur - stress_high - stress_medium
        conn.execute(
            """INSERT INTO days (date, steps, distance_m, floors_up, calories_total,
               calories_active, calories_bmr, resting_hr, min_hr, max_hr, stress_avg,
               stress_max, stress_duration_s, rest_stress_duration_s, bb_high, bb_low,
               bb_charged, bb_drained, bb_intraday_json, intensity_moderate_min,
               intensity_vigorous_min, sedentary_s, active_s, highly_active_s,
               respiration_avg, respiration_lowest, respiration_highest,
               vo2max_running, vo2max_cycling, stress_low_s, stress_medium_s,
               stress_high_s, stress_activity_s, stress_qualifier, bb_at_wake,
               bb_during_sleep, resting_hr_7d_avg, fetched_at)
               VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,
                       %s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
            (
                stamp, steps, int(steps * 0.75), rng.randint(4, 18),
                int(2400 + day_load * 3 + rng.uniform(-150, 200)),
                int(500 + day_load * 3), 1900,
                int(rhr), int(rhr - rng.uniform(1, 4)), int(120 + day_load * 0.4),
                int(rng.uniform(22, 42)), rng.randint(70, 96),
                stress_dur, rest_stress,
                bb_high, bb_low, rng.randint(30, 60), rng.randint(40, 75), None,
                rng.randint(15, 60) + (30 if day_load else 0),
                (int(day_load * 0.45) if day_load else rng.randint(0, 10)),
                rng.randint(28000, 40000), rng.randint(5000, 12000), rng.randint(1200, 5000),
                round(rng.uniform(13.5, 15.5), 1), round(rng.uniform(11, 13), 1),
                round(rng.uniform(16, 19), 1),
                round(vo2, 1), None,
                stress_low, stress_medium, stress_high,
                (int(day_load * 60) if day_load else 0),
                "CALM" if stress_high < stress_dur * 0.05 else "BALANCED",
                # Body battery at wake-up is the top of the day, so it sits
                # at bb_high rather than near it, and what the night added
                # is the climb from the previous evening's low.
                bb_high, int(min(bb_high - 5, rng.uniform(45, 75))),
                int(rhr + rng.uniform(-1, 1)),
                "demo",
            ),
        )

        # slow drifts
        vo2 += rng.uniform(-0.01, 0.035)
        hrv_baseline += rng.uniform(-0.06, 0.09)
        rhr_base += rng.uniform(-0.04, 0.025)
        endurance += rng.uniform(-2, 5)

        if dow == 0:  # weekly endurance score point
            upsert(conn, "endurance_score", ("date",), {
                "date": stamp,
                "score": int(endurance),
                "classification": "TRAINED",
                "fetched_at": "demo",
            })

        d += timedelta(days=1)

    # race predictions: improving 5K over the period
    for weeks_back in range(0, 17, 2):
        dd = today - timedelta(weeks=weeks_back)
        base5k = 1230 + weeks_back * 6  # ~20:30 improving from ~22:12
        for dist, factor in (("5K", 1), ("10K", 2.08), ("HALF", 4.6), ("MARATHON", 9.9)):
            upsert(conn, "race_predictions", ("date", "distance"), {
                "date": dd.isoformat(),
                "distance": dist,
                "seconds": int(base5k * factor),
            })

    upsert(conn, "fitness_age", ("date",), {
        "date": today.isoformat(),
        "chronological_age": 38.0,
        "fitness_age": 27.5,
        "achievable_age": 25.0,
        "fetched_at": "demo",
    })

    # --- the mirror's own bookkeeping
    #
    # Without it the dashboard reads the demo as "never fetched" and puts a
    # stale-data warning over data that is complete: DataStatus derives its
    # verdict from the newest successful fetch_log row, not from the days
    # table. Seeding is a fetch as far as that question goes, so it is
    # logged like one, with the timestamp of this run.
    #
    # It is not a connection, though, and these rows say nothing about
    # whether anybody ever signed in to Garmin. A fresh install used to
    # read them as one and put "Garmin connected" over an account that did
    # not exist. The fetched_at="demo" mark on the days above is what tells
    # the two apart (app/Garmin/GarminData::isDemo, read by dataStatus),
    # so keep it on every seeded day: without it these log rows become the
    # only account of where the numbers came from, and they are the wrong
    # one.
    #
    # device_sync stays empty on purpose. It records the last watch-to-Garmin
    # upload and goes stale after three hours, so a fabricated row would put
    # a "your watch has not synced" banner on a demo that has no watch. An
    # absent row is the honest answer and the dashboard handles it.
    fetched_at = now()
    log_day = start
    while log_day <= today:
        for kind in LOG_KINDS_DAILY:
            upsert(conn, "fetch_log", ("date", "kind"), {
                "date": log_day.isoformat(), "kind": kind,
                "ok": 1, "error": None, "fetched_at": fetched_at,
            })
        log_day += timedelta(days=1)

    for kind in LOG_KINDS_SNAPSHOT:
        upsert(conn, "fetch_log", ("date", "kind"), {
            "date": today.isoformat(), "kind": kind,
            "ok": 1, "error": None, "fetched_at": fetched_at,
        })

    # a few manual weigh-ins (no smart scale in demo)
    for weeks_back in range(0, 16, 2):
        dd = today - timedelta(weeks=weeks_back)
        upsert(conn, "body_comp", ("ts",), {
            "ts": dd.isoformat() + "T07:00:00",
            "date": dd.isoformat(),
            "weight_g": 82000 + rng.uniform(-800, 800),
            "bmi": 24.1,
            "body_fat_pct": None,
            "muscle_mass_g": None,
            "body_water_pct": None,
            "bone_mass_g": None,
            "source": "manual",
        })


if __name__ == "__main__":
    sys.exit(main())
