-- PostgreSQL schema for the Garmin health dashboard.
-- Written by fetch.py (live data) and seed_demo.py (demo data).
-- All *_json columns hold raw JSON fragments for fields the dashboard
-- may want later without a re-fetch. They stay `text` rather than `jsonb`
-- because nothing queries into them; the app hands them to the frontend
-- whole, and jsonb would reorder keys and drop duplicates on the way.
--
-- The mirror lives in its own schema, not in `public`. That is what keeps
-- AI-written SQL away from the application's own tables: the role behind
-- the `garmin` connection is granted USAGE on this schema and SELECT on
-- these tables, and nothing at all on `public`, where users and
-- oauth_access_tokens sit. See database/postgres/roles.sql.
--
-- Which schema that is, is not written here. `{mirror}` is substituted by
-- whoever executes this file with the schema of one tenant, `garmin_t{id}`
-- for user id (App\Garmin\Mirror, fetch.py --tenant), so every athlete on
-- an installation gets these tables once, in a schema of their own. The
-- placeholder is deliberately not valid SQL: a file run through psql by
-- hand should fail at the first table rather than quietly fill `public`.
--
-- Dates are stored as `text` in 'YYYY-MM-DD', not as `date`. Garmin's own
-- payloads are strings, every comparison in the app and in the stored card
-- SQL is a lexicographic string compare, and for an ISO-8601 date that
-- orders identically to the calendar. Converting would buy nothing and
-- would silently change the meaning of every saved card.
--
-- What a column means is written as COMMENT ON rather than as a `--` note
-- beside it. Postgres keeps no DDL text, so a note beside the column would
-- reach nobody but a reader of this file, while a COMMENT is what psql's
-- \d+ prints and what the MCP tool describe-schema hands to the language
-- model before it writes SQL (App\Garmin\MirrorSchema). Notes that explain
-- a decision rather than a value stay `--`: they are for whoever edits
-- this file, not for whoever queries the table.

-- Normally database/postgres/roles.sql has already created both schemas and
-- handed them to the fetcher. This block is for the plain case: one database,
-- one user, no role split (local development, a throwaway demo).
--
-- It is a DO block rather than CREATE SCHEMA IF NOT EXISTS because Postgres
-- checks the CREATE privilege on the database *before* it checks whether the
-- schema is already there. The fetcher role deliberately has no such
-- privilege, so the plain form fails on a database where the schema exists
-- and no creation would have happened anyway.
--
-- garmin_private holds the secrets the read-only role has no USAGE on at
-- all. A missing GRANT would already keep them unreadable, but a separate
-- schema is the stronger statement: nothing here shows up in the mirror's
-- introspection, so a forgotten grant on a future table cannot expose it.
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_namespace WHERE nspname = '{mirror}') THEN
        EXECUTE 'CREATE SCHEMA {mirror}';
    END IF;
    IF NOT EXISTS (SELECT FROM pg_namespace WHERE nspname = 'garmin_private') THEN
        EXECUTE 'CREATE SCHEMA garmin_private';
    END IF;
END
$$;

CREATE TABLE IF NOT EXISTS {mirror}.days (
    date text PRIMARY KEY,
    steps integer,
    distance_m integer,
    floors_up double precision,
    calories_total integer,
    calories_active integer,
    calories_bmr integer,
    resting_hr integer,
    min_hr integer,
    max_hr integer,
    stress_avg integer,
    stress_max integer,
    stress_duration_s integer,
    rest_stress_duration_s integer,
    bb_high integer,
    bb_low integer,
    bb_charged integer,
    bb_drained integer,
    bb_intraday_json text,
    intensity_moderate_min integer,
    intensity_vigorous_min integer,
    sedentary_s integer,
    active_s integer,
    highly_active_s integer,
    respiration_avg double precision,
    respiration_lowest double precision,
    respiration_highest double precision,
    vo2max_running double precision,
    vo2max_cycling double precision,
    sweat_loss_ml double precision,
    hydration_goal_ml double precision,
    hydration_value_ml double precision,
    hill_score integer,
    hill_strength integer,
    hill_endurance integer,
    spo2_avg double precision,
    spo2_lowest double precision,
    fetched_at text
);

-- CREATE IF NOT EXISTS leaves an existing table alone, so a column added
-- later needs its own idempotent step for installations from before it.
-- These MUST run before the COMMENTs below: the whole file executes as
-- one implicit transaction, and a COMMENT on a column that does not
-- exist yet kills the entire load. On a fresh database the CREATE
-- above carries the column and the tests stay green, so only a mirror
-- that predates the column ever sees the crash.
ALTER TABLE {mirror}.days ADD COLUMN IF NOT EXISTS sweat_loss_ml double precision;
ALTER TABLE {mirror}.days ADD COLUMN IF NOT EXISTS hydration_goal_ml double precision;
ALTER TABLE {mirror}.days ADD COLUMN IF NOT EXISTS hydration_value_ml double precision;
ALTER TABLE {mirror}.days ADD COLUMN IF NOT EXISTS stress_low_s integer;
ALTER TABLE {mirror}.days ADD COLUMN IF NOT EXISTS stress_medium_s integer;
ALTER TABLE {mirror}.days ADD COLUMN IF NOT EXISTS stress_high_s integer;
ALTER TABLE {mirror}.days ADD COLUMN IF NOT EXISTS stress_activity_s integer;
ALTER TABLE {mirror}.days ADD COLUMN IF NOT EXISTS stress_qualifier text;
ALTER TABLE {mirror}.days ADD COLUMN IF NOT EXISTS bb_at_wake integer;
ALTER TABLE {mirror}.days ADD COLUMN IF NOT EXISTS bb_during_sleep integer;
ALTER TABLE {mirror}.days ADD COLUMN IF NOT EXISTS resting_hr_7d_avg integer;

COMMENT ON COLUMN {mirror}.days.date IS 'YYYY-MM-DD';
COMMENT ON COLUMN {mirror}.days.bb_high IS 'body battery';
COMMENT ON COLUMN {mirror}.days.bb_intraday_json IS '[[ts, level], ...]';
COMMENT ON COLUMN {mirror}.days.sweat_loss_ml IS 'Garmin''s per-day sweat loss estimate';
COMMENT ON COLUMN {mirror}.days.hydration_value_ml IS 'water logged in Garmin Connect that day';
COMMENT ON COLUMN {mirror}.days.hill_score IS 'running hill capacity (overall)';
COMMENT ON COLUMN {mirror}.days.spo2_avg IS
    'nightly pulse ox. Null on days the watch had the sensor switched off,
which is a setting and not a defect: a run of nulls that ends says when it
was turned on, not that the data was lost.';
-- stress_avg says how the day went, these four say what it was made of.
-- Garmin scores every minute it can measure into one of them, so they sum
-- to stress_duration_s plus rest_stress_duration_s, and a day at the same
-- average can be a calm day with one hard hour or an even grind.
COMMENT ON COLUMN {mirror}.days.stress_low_s IS 'seconds Garmin scored as low stress';
COMMENT ON COLUMN {mirror}.days.stress_medium_s IS 'seconds scored as medium stress';
COMMENT ON COLUMN {mirror}.days.stress_high_s IS 'seconds scored as high stress';
COMMENT ON COLUMN {mirror}.days.stress_activity_s IS
    'seconds the elevated reading is explained by training rather than by
strain: the difference between a hard session and a hard day';
COMMENT ON COLUMN {mirror}.days.stress_qualifier IS 'Garmin''s word for the day, e.g. CALM';
COMMENT ON COLUMN {mirror}.days.bb_at_wake IS
    'body battery at wake-up, which is what the night was worth. bb_high can
be reached later in the day and says something else.';
COMMENT ON COLUMN {mirror}.days.bb_during_sleep IS 'body battery recharged while asleep';
COMMENT ON COLUMN {mirror}.days.resting_hr_7d_avg IS
    'Garmin''s own seven-day mean, kept because it is the baseline the watch
compares today against, and recomputing it here would not be the same number';

CREATE TABLE IF NOT EXISTS {mirror}.heart_profile (
    date text PRIMARY KEY,
    max_hr integer,
    resting_hr_used integer,
    lthr_bpm integer,
    lthr_speed_ms double precision,
    zone1_floor integer,
    zone2_floor integer,
    zone3_floor integer,
    zone4_floor integer,
    zone5_floor integer,
    fetched_at text
);

COMMENT ON TABLE {mirror}.heart_profile IS
    'Snapshot of the athlete''s heart configuration: HR zones, max HR and
lactate threshold. One row per fetch day; the newest row is "current".';
COMMENT ON COLUMN {mirror}.heart_profile.lthr_bpm IS 'lactate threshold heart rate';
COMMENT ON COLUMN {mirror}.heart_profile.lthr_speed_ms IS 'threshold speed in m/s';

CREATE TABLE IF NOT EXISTS {mirror}.sleep (
    date text PRIMARY KEY,
    start_local text,
    end_local text,
    duration_s integer,
    deep_s integer,
    light_s integer,
    rem_s integer,
    awake_s integer,
    nap_s integer,
    score integer,
    score_qualifier text,
    score_components_json text,
    avg_sleep_hrv double precision,
    respiration_avg double precision,
    respiration_lowest double precision,
    respiration_highest double precision,
    fetched_at text
);

ALTER TABLE {mirror}.sleep ADD COLUMN IF NOT EXISTS skin_temp_deviation_c double precision;
ALTER TABLE {mirror}.sleep ADD COLUMN IF NOT EXISTS avg_stress double precision;
ALTER TABLE {mirror}.sleep ADD COLUMN IF NOT EXISTS avg_hr double precision;
ALTER TABLE {mirror}.sleep ADD COLUMN IF NOT EXISTS awake_count integer;
ALTER TABLE {mirror}.sleep ADD COLUMN IF NOT EXISTS restless_moments integer;
ALTER TABLE {mirror}.sleep ADD COLUMN IF NOT EXISTS breathing_disruptions integer;
ALTER TABLE {mirror}.sleep ADD COLUMN IF NOT EXISTS breathing_disruption_severity text;
ALTER TABLE {mirror}.sleep ADD COLUMN IF NOT EXISTS spo2_avg double precision;
ALTER TABLE {mirror}.sleep ADD COLUMN IF NOT EXISTS spo2_lowest integer;
ALTER TABLE {mirror}.sleep ADD COLUMN IF NOT EXISTS body_battery_change integer;
ALTER TABLE {mirror}.sleep ADD COLUMN IF NOT EXISTS need_actual_min integer;
ALTER TABLE {mirror}.sleep ADD COLUMN IF NOT EXISTS need_baseline_min integer;
ALTER TABLE {mirror}.sleep ADD COLUMN IF NOT EXISTS midpoint_min integer;
ALTER TABLE {mirror}.sleep ADD COLUMN IF NOT EXISTS optimal_window_start_min integer;
ALTER TABLE {mirror}.sleep ADD COLUMN IF NOT EXISTS optimal_window_end_min integer;
ALTER TABLE {mirror}.sleep ADD COLUMN IF NOT EXISTS alignment_status text;

COMMENT ON COLUMN {mirror}.sleep.date IS 'calendar date the night belongs to';
COMMENT ON COLUMN {mirror}.sleep.score_components_json IS 'sleepScores object';
COMMENT ON COLUMN {mirror}.sleep.avg_sleep_hrv IS
    'mean HRV across the night. Garmin calls this avgOvernightHrv and it is
not the same number as hrv.last_night_avg, which is the five-minute-window
average the readiness model uses.';
-- The watch measures wrist temperature all night and reports how far it
-- sat from this athlete's own baseline, not an absolute. So it says the
-- body ran warm, never how warm the room was, and it moves for an
-- infection, alcohol or a late session as readily as for a hot night.
-- Useful precisely as the mediator outdoor temperature cannot be: warm
-- outside and flat here means the room stayed cool.
COMMENT ON COLUMN {mirror}.sleep.skin_temp_deviation_c IS
    'degrees Celsius away from the athlete''s own skin-temperature baseline,
which the watch needs about three weeks of nights to establish';
COMMENT ON COLUMN {mirror}.sleep.avg_stress IS 'mean stress score while asleep';
COMMENT ON COLUMN {mirror}.sleep.awake_count IS 'times Garmin scored a wake-up, not counting restlessness';
COMMENT ON COLUMN {mirror}.sleep.restless_moments IS 'movements too brief to count as waking';
COMMENT ON COLUMN {mirror}.sleep.breathing_disruptions IS
    'count of breathing interruptions Garmin flagged that night. A training
metric, not a diagnosis: apnoea is diagnosed in a sleep laboratory.';
COMMENT ON COLUMN {mirror}.sleep.breathing_disruption_severity IS 'NONE / LOW / ... as Garmin grades it';
COMMENT ON COLUMN {mirror}.sleep.spo2_avg IS
    'blood oxygen across the night, from the sleep payload''s own summary
rather than from days.spo2_avg, which covers the whole calendar day';
COMMENT ON COLUMN {mirror}.sleep.body_battery_change IS 'body battery gained across the night';
COMMENT ON COLUMN {mirror}.sleep.need_actual_min IS
    'minutes of sleep Garmin held this athlete needed that night, already
adjusted for recent HRV and sleep history; need_baseline_min is the same
figure before those adjustments';
-- Minutes since midnight, so 1420 is 23:40 and 30 is 00:30. The window can
-- start before midnight and end after it, which is why start can exceed
-- end. Distance between midpoint_min and the middle of the window is the
-- number worth watching: it is social jetlag, measured.
COMMENT ON COLUMN {mirror}.sleep.midpoint_min IS 'middle of the night actually slept, minutes since midnight';
COMMENT ON COLUMN {mirror}.sleep.optimal_window_start_min IS
    'start of the window Garmin derives from this athlete''s own rhythm,
minutes since midnight';
COMMENT ON COLUMN {mirror}.sleep.alignment_status IS
    'ALIGNED and its opposites: whether the night sat inside that window.
Garmin''s verdict, kept because it applies a threshold this mirror would
otherwise have to invent.';

CREATE TABLE IF NOT EXISTS {mirror}.hrv (
    date text PRIMARY KEY,
    last_night_avg double precision,
    weekly_avg double precision,
    status text,
    baseline_low_upper double precision,
    baseline_balanced_low double precision,
    baseline_balanced_upper double precision,
    baseline_marker double precision,
    feedback text,
    fetched_at text
);

COMMENT ON COLUMN {mirror}.hrv.status IS 'BALANCED / UNBALANCED / LOW / POOR';

CREATE TABLE IF NOT EXISTS {mirror}.readiness (
    date text PRIMARY KEY,
    score integer,
    level text,
    feedback_short text,
    sleep_score_factor integer,
    recovery_time_factor integer,
    acwr_factor integer,
    hrv_factor integer,
    sleep_history_factor integer,
    stress_history_factor integer,
    recovery_time_h double precision,
    current_score integer,
    current_level text,
    current_feedback_short text,
    current_sleep_score_factor integer,
    current_recovery_time_factor integer,
    current_acwr_factor integer,
    current_hrv_factor integer,
    current_sleep_history_factor integer,
    current_stress_history_factor integer,
    current_recovery_time_h double precision,
    current_at text,
    fetched_at text
);

ALTER TABLE {mirror}.readiness ADD COLUMN IF NOT EXISTS feedback_long text;
ALTER TABLE {mirror}.readiness ADD COLUMN IF NOT EXISTS sleep_score integer;
ALTER TABLE {mirror}.readiness ADD COLUMN IF NOT EXISTS hrv_weekly_avg integer;
ALTER TABLE {mirror}.readiness ADD COLUMN IF NOT EXISTS acwr_factor_feedback text;
ALTER TABLE {mirror}.readiness ADD COLUMN IF NOT EXISTS hrv_factor_feedback text;
ALTER TABLE {mirror}.readiness ADD COLUMN IF NOT EXISTS sleep_score_factor_feedback text;
ALTER TABLE {mirror}.readiness ADD COLUMN IF NOT EXISTS sleep_history_factor_feedback text;
ALTER TABLE {mirror}.readiness ADD COLUMN IF NOT EXISTS stress_history_factor_feedback text;
ALTER TABLE {mirror}.readiness ADD COLUMN IF NOT EXISTS recovery_time_factor_feedback text;

COMMENT ON COLUMN {mirror}.readiness.score IS 'morning snapshot: Garmin''s canonical daily value';
COMMENT ON COLUMN {mirror}.readiness.sleep_score_factor IS 'factor percents as delivered by Garmin';
-- The factor percents say how much each input moved the score. These say
-- which way, in Garmin's own words, and they are the difference between
-- "readiness 62" and "readiness 62 because HRV is unbalanced while
-- everything else is fine".
COMMENT ON COLUMN {mirror}.readiness.feedback_long IS
    'Garmin''s reason for the score as an enum, e.g. HIGH_HRV_UNBALANCED';
COMMENT ON COLUMN {mirror}.readiness.acwr_factor_feedback IS
    'VERY_GOOD / GOOD / MODERATE / ... for the load ratio, and so for the
other *_factor_feedback columns beside it';
COMMENT ON COLUMN {mirror}.readiness.current_score IS
    'Intraday snapshot, and so are the other current_* columns: the watch
recomputes readiness after every workout, while the morning columns freeze
the wake-up state. Only today''s row gets these; history keeps the
comparable morning values.';
COMMENT ON COLUMN {mirror}.readiness.current_at IS 'Garmin timestampLocal of the snapshot';

CREATE TABLE IF NOT EXISTS {mirror}.training_status (
    date text PRIMARY KEY,
    status_key text,
    acute_load double precision,
    chronic_load double precision,
    acwr double precision,
    load_focus_json text,
    fetched_at text
);

ALTER TABLE {mirror}.training_status ADD COLUMN IF NOT EXISTS balance_feedback text;
ALTER TABLE {mirror}.training_status ADD COLUMN IF NOT EXISTS fitness_trend integer;
ALTER TABLE {mirror}.training_status ADD COLUMN IF NOT EXISTS fitness_trend_sport text;

COMMENT ON COLUMN {mirror}.training_status.status_key IS
    'Garmin''s phrase, which carries a variant number: PRODUCTIVE_6,
PEAKING_1, OVERREACHING_4, NO_STATUS_2. Match on the prefix, never on
equality, or the same status read twice counts as two.';
COMMENT ON COLUMN {mirror}.training_status.load_focus_json IS 'monthly load split + targets';
COMMENT ON COLUMN {mirror}.training_status.balance_feedback IS
    'what the monthly load split is short of, e.g. AEROBIC_LOW_SHORTAGE.
load_focus_json carries the numbers this sentence is drawn from.';
COMMENT ON COLUMN {mirror}.training_status.fitness_trend IS
    'Garmin''s own direction of travel for fitness_trend_sport, positive for
rising; it is not VO2max and moves on a slower clock than the load ratio';

-- id is bigint, not integer: Garmin activity ids are already past 2^31,
-- so a 4-byte column would reject every row this fetcher writes.
CREATE TABLE IF NOT EXISTS {mirror}.activities (
    id bigint PRIMARY KEY,
    date text,
    start_local text,
    type_key text,
    name text,
    duration_s double precision,
    moving_s double precision,
    distance_m double precision,
    avg_hr integer,
    max_hr integer,
    calories integer,
    aerobic_te double precision,
    anaerobic_te double precision,
    training_load double precision,
    avg_power double precision,
    norm_power double precision,
    avg_speed_mps double precision,
    elevation_gain_m double precision,
    total_sets integer,
    active_sets integer,
    total_reps integer,
    total_volume_g double precision,
    hr_zones_json text,
    fetched_at text
);
CREATE INDEX IF NOT EXISTS idx_activities_date ON {mirror}.activities(date);

COMMENT ON COLUMN {mirror}.activities.id IS 'Garmin activityId';
COMMENT ON COLUMN {mirror}.activities.total_volume_g IS 'tonnage in grams (Garmin unit)';
COMMENT ON COLUMN {mirror}.activities.hr_zones_json IS 'seconds per HR zone';

CREATE TABLE IF NOT EXISTS {mirror}.strength_sets (
    activity_id bigint,
    set_index integer,
    exercise_category text,
    exercise_name text,
    set_type text,
    reps integer,
    weight_g double precision,
    duration_s double precision,
    start_local text,
    PRIMARY KEY (activity_id, set_index)
);

COMMENT ON COLUMN {mirror}.strength_sets.exercise_category IS 'Garmin category enum, e.g. BENCH_PRESS';
COMMENT ON COLUMN {mirror}.strength_sets.exercise_name IS 'specific variant, may be empty';
COMMENT ON COLUMN {mirror}.strength_sets.set_type IS 'ACTIVE / REST';

-- One extra API call per activity fills this (get_activity_splits), so
-- rows arrive through the capped backfill in fetch.py rather than with
-- the activity list. Verified against live responses 2026-08-02: every
-- probed activity type answers with at least one lap, values arrive as
-- floats (averageHR 125.0), lapIndex is 1-based, and distance is 0.0
-- where nothing was covered (stations, HIIT), which is data, not a gap.
CREATE TABLE IF NOT EXISTS {mirror}.activity_laps (
    activity_id bigint,
    lap_index integer,
    start_gmt text,
    duration_s double precision,
    moving_s double precision,
    distance_m double precision,
    avg_hr integer,
    max_hr integer,
    avg_speed_mps double precision,
    fetched_at text,
    PRIMARY KEY (activity_id, lap_index)
);

COMMENT ON TABLE {mirror}.activity_laps IS
    'One row per lap (split) of an activity, from the lap button or auto-lap.
The race surface reads these for split tables and pace degradation; an
activity the fetcher has not visited yet simply has no rows here.';
COMMENT ON COLUMN {mirror}.activity_laps.lap_index IS 'Garmin lapIndex, 1-based';
COMMENT ON COLUMN {mirror}.activity_laps.start_gmt IS
    '''YYYY-MM-DD HH:MM:SS'' in GMT: the splits payload carries no local
timestamp, so like device_sync.last_sync_utc this column keeps the honest
zone instead of faking a local one. Order within an activity is lap_index.';
COMMENT ON COLUMN {mirror}.activity_laps.duration_s IS 'elapsed lap time, the race-honest one';
COMMENT ON COLUMN {mirror}.activity_laps.moving_s IS 'time in motion; 0.0 on station work';
COMMENT ON COLUMN {mirror}.activity_laps.distance_m IS '0.0 means no distance covered, not unknown';
COMMENT ON COLUMN {mirror}.activity_laps.avg_speed_mps IS
    'distance over elapsed lap time as Garmin reports it; pace is derived from
this, never stored';

CREATE TABLE IF NOT EXISTS {mirror}.race_predictions (
    date text,
    distance text,
    seconds integer,
    PRIMARY KEY (date, distance)
);

COMMENT ON COLUMN {mirror}.race_predictions.distance IS '5K / 10K / HALF / MARATHON';

CREATE TABLE IF NOT EXISTS {mirror}.endurance_score (
    date text PRIMARY KEY,
    score integer,
    classification text,
    fetched_at text
);

CREATE TABLE IF NOT EXISTS {mirror}.body_comp (
    ts text PRIMARY KEY,
    date text,
    weight_g double precision,
    bmi double precision,
    body_fat_pct double precision,
    muscle_mass_g double precision,
    body_water_pct double precision,
    bone_mass_g double precision,
    source text
);

COMMENT ON COLUMN {mirror}.body_comp.ts IS 'measurement timestamp';
COMMENT ON COLUMN {mirror}.body_comp.source IS 'index_scale / manual';

CREATE TABLE IF NOT EXISTS {mirror}.fitness_age (
    date text PRIMARY KEY,
    chronological_age double precision,
    fitness_age double precision,
    achievable_age double precision,
    fetched_at text
);

-- `ok` stays an integer rather than becoming a boolean: the dashboard reads
-- it as 0/1 in several places, and a type change there would be a silent
-- behaviour change for no gain.
CREATE TABLE IF NOT EXISTS {mirror}.fetch_log (
    date text,
    kind text,
    ok integer,
    error text,
    fetched_at text,
    PRIMARY KEY (date, kind)
);

COMMENT ON TABLE {mirror}.fetch_log IS
    'Bookkeeping: which day/kind combinations were fetched when (debugging aid).';

-- Everything Garmin answered, before this fetcher decided what mattered.
--
-- The tables above are a reading of the payloads: a column exists because
-- someone found a use for the field. That reading is always behind, and
-- being behind used to mean the data was gone, because a field nobody had
-- thought of was dropped on the floor and the only way back was to ask
-- Garmin again for a day it no longer serves. This table is the floor.
--
-- Written by call(), so it catches every endpoint the fetcher has, plus
-- every one it grows later, without anyone remembering to wire it up.
-- jsonb and not text, unlike the *_json columns elsewhere in this file:
-- those are handed to the frontend whole, this one exists to be queried
-- into. Key order and duplicate keys are lost in the conversion, which
-- costs nothing here and buys operators like ->> and @> for a model
-- writing its own SQL.
--
-- About 300 KB of JSON a day, which TOAST stores as roughly 50 KB
-- (measured 2026-08-16, 25 payloads), so a year of one athlete is under
-- 20 MB. Deleting old rows is safe: it costs history nobody has asked for
-- yet, never a column above.
CREATE TABLE IF NOT EXISTS {mirror}.raw_payload (
    date text,
    kind text,
    payload jsonb,
    fetched_at text,
    PRIMARY KEY (date, kind)
);

COMMENT ON TABLE {mirror}.raw_payload IS
    'Garmin''s untouched answer per day and endpoint, kept so that a field
no column covers is still a question this mirror can answer. `kind` matches
fetch_log.kind (stats, sleep, hrv, readiness, training_status, spo2, ...),
plus one row per activity for its sets, laps and heart-rate zones.
Start at: SELECT jsonb_object_keys(payload) FROM raw_payload WHERE kind = ''sleep'' LIMIT 1
One trap: write jsonb_exists(payload, ''key''), never payload ? ''key''. The
question mark is read as a bind placeholder before PostgreSQL sees it, and
the error that comes back names $1 and not the operator.';

CREATE TABLE IF NOT EXISTS {mirror}.device_sync (
    device_key text PRIMARY KEY,
    device_name text,
    last_sync_utc text,
    fetched_at text
);

COMMENT ON TABLE {mirror}.device_sync IS
    'Last known watch-to-Garmin-Connect upload per device. The dashboard shows
it beside the fetch stamp: a fetch can only mirror what the watch has already
uploaded, so when a fetch brings nothing new this is the honest explanation
(watch not synced to the Connect app).';
COMMENT ON COLUMN {mirror}.device_sync.device_key IS 'e.g. forerunner970';
COMMENT ON COLUMN {mirror}.device_sync.last_sync_utc IS
    '''YYYY-MM-DD HH:MM:SS'' in UTC (true GMT epoch, unlike *TimestampLocal)';

CREATE TABLE IF NOT EXISTS {mirror}.weather_hourly (
    ts_local text PRIMARY KEY,
    date text NOT NULL,
    temperature_c double precision,
    apparent_c double precision,
    relative_humidity integer,
    dewpoint_c double precision,
    wind_speed_kmh double precision,
    precipitation_mm double precision,
    uv_index double precision,
    surface_pressure_hpa double precision,
    cloud_cover integer,
    latitude double precision,
    longitude double precision,
    source text,
    fetched_at text
);
CREATE INDEX IF NOT EXISTS idx_weather_hourly_date ON {mirror}.weather_hourly(date);

COMMENT ON TABLE {mirror}.weather_hourly IS
    'The conditions the athlete''s body was working against, hour by hour, for
one fixed location. Not from Garmin: from Open-Meteo, which needs no key and
serves both the historical archive and the current forecast from one interface.

The point of the table is that four questions in this mirror cannot be answered
without it. Deep sleep collapses on warm nights and the sleep table alone reads
that as a bad night. Heart rate drifts upward in heat, so a session in 30 C
looks harder than the mechanical work was and the load model calls it fatigue.
Sweat loss and the hydration goal in `days` are computed by Garmin with a
weather input the dashboard never saw, so it showed the result without the
cause. Daytime heat raises resting heart rate and baseline stress on its own.

Hourly rows and nothing else. Every window this feeds (a night from
sleep.start_local to sleep.end_local, a session from its start over its
duration, a calendar day) is cut from these rows when it is asked for, never
stored, because the windows move and the hours do not.

One location for the whole mirror, from WEATHER_LAT/WEATHER_LON. Garmin does
report activity start coordinates, but the fetcher does not store them, and a
per-session location would say nothing about the nights, which is where most
of the signal is. latitude/longitude travel with each row anyway, so a moved
location stays legible in the history instead of silently reinterpreting it.';
COMMENT ON COLUMN {mirror}.weather_hourly.ts_local IS
    '''YYYY-MM-DD HH:MM:SS'' local wall clock at the configured location,
matching sleep.start_local and activities.start_local so windows can be cut
by plain string comparison.';
COMMENT ON COLUMN {mirror}.weather_hourly.apparent_c IS
    'what the air feels like once humidity, wind and radiation are counted in.
The honest number for a session: 28 C at 80 % humidity is not 28 C of work.';
COMMENT ON COLUMN {mirror}.weather_hourly.dewpoint_c IS
    'the sleep number. Absolute humidity, so unlike relative humidity it does
not fall just because the room warmed up; above roughly 16 C the body loses
its evaporative route to cooling and deep sleep goes with it.';
COMMENT ON COLUMN {mirror}.weather_hourly.source IS
    '''archive'' (ERA5 reanalysis, about five days behind) or ''forecast''
(the recent past and today). The same hour can arrive from both, and the
archive is the better measurement, so it overwrites.';

CREATE TABLE IF NOT EXISTS {mirror}.intraday (
    ts_local text PRIMARY KEY,
    date text NOT NULL,
    stress integer,
    stress_marker text,
    body_battery integer,
    bb_status text,
    heart_rate integer,
    fetched_at text
);
CREATE INDEX IF NOT EXISTS idx_intraday_date ON {mirror}.intraday(date);

COMMENT ON TABLE {mirror}.intraday IS
    'Stress, body battery and heart rate at the resolution the watch records
them. The daily aggregates in `days` can say a day was hard, they cannot say
what happened around 18:00, which is what this table exists for (crossing
calendar events against physiological response).

Garmin delivers stress and body battery on a 3 minute grid (480 samples per
day) and heart rate on a 2 minute grid (720 samples). The grids coincide only
every 6 minutes, so most rows carry either stress plus body battery or heart
rate, not all three. That is the shape of the source, not a gap.

Known limit, verified 2026-07-28: the 3 minute stress series is smoothed. Its
average matches days.stress_avg within a point, but its maximum falls short of
days.stress_max (measured gaps of 1, 5 and 20 points on three consecutive
days), because short spikes are averaged away. Use this table for courses and
window comparisons, and days.stress_max for peaks.

Second limit, inherent to the source: Garmin stops scoring stress during
movement, so an active window is mostly stress_marker rows with a NULL stress.
Heart rate keeps recording throughout and is the usable signal there.';
COMMENT ON COLUMN {mirror}.intraday.ts_local IS
    '''YYYY-MM-DD HH:MM:SS'' local wall clock. Timestamps in these arrays are
true GMT epochs, unlike the *TimestampLocal scalars elsewhere in this API; the
fetcher converts them with the offset the payload itself reports for that day,
which keeps daylight saving correct without a timezone database.';
COMMENT ON COLUMN {mirror}.intraday.date IS
    'calendar day Garmin assigns the sample to';
COMMENT ON COLUMN {mirror}.intraday.stress IS
    '0..100, NULL when no usable value was reported';
COMMENT ON COLUMN {mirror}.intraday.stress_marker IS
    'why stress is NULL: ''unmeasurable'' (-1) or ''motion'' (-2)';
COMMENT ON COLUMN {mirror}.intraday.body_battery IS '0..100';
COMMENT ON COLUMN {mirror}.intraday.bb_status IS 'e.g. MEASURED';
COMMENT ON COLUMN {mirror}.intraday.heart_rate IS 'bpm';

-- The Garmin OAuth session, previously the file tree fetcher/.tokens.
-- A PaaS filesystem does not survive a deploy, and losing this row means
-- an interactive login with an MFA code on a machine that has no terminal
-- open. One row per athlete, its id the tenant's user id, written by
-- login.py and read by fetch.py.
--
-- garmin_private is the one schema that is not per tenant. The rows in it
-- are, and they are reached by tenant id rather than by schema, because a
-- role that may read any of them is a role nobody has: the mirror's reader
-- roles get USAGE on their own schema and nothing here at all.
--
-- Deliberately in garmin_private, not in the mirror: these tokens are full
-- access to the Garmin account. Model-written SQL runs against the mirror,
-- and "SELECT tokens FROM garmin_session" would be a plain read if this
-- table sat one schema over.
CREATE TABLE IF NOT EXISTS garmin_private.garmin_session (
    id integer PRIMARY KEY,
    tokens text NOT NULL,
    updated_at text
);

-- The single-row era, undone where it already happened. The table above is
-- created with neither, but an installation from before multi-tenancy has
-- both, and CREATE TABLE IF NOT EXISTS never alters a table that is there.
ALTER TABLE garmin_private.garmin_session DROP CONSTRAINT IF EXISTS garmin_session_single_row;
ALTER TABLE garmin_private.garmin_session ALTER COLUMN id DROP DEFAULT;

COMMENT ON COLUMN garmin_private.garmin_session.id IS
    'the tenant this session belongs to: the users.id of the athlete whose '
    'Garmin account it signs in to, and the {id} of their mirror schema';
COMMENT ON COLUMN garmin_private.garmin_session.tokens IS
    'the token store as JSON, exactly as garminconnect emits it';
