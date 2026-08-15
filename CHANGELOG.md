# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.1] - 2026-08-15

### Changed

- The README leads with the MCP server rather than the dashboard, and the
  tool table moved out of *Connect an AI* into a section of its own, right
  behind *What it is*. The dashboard keeps its place in the description:
  it draws the body map and the load view, and it is the login the
  connector authenticates against.

### Fixed

- The server name in `server.json` and in the image's
  `io.modelcontextprotocol.server.name` label is `io.github.Imgrund/hybridlog`,
  with the capital I of the GitHub account. The registry compares the two
  case-sensitively and refused the lowercase spelling, so 0.1.0 could not
  be published there.

## [0.1.0] - 2026-08-15

First public release. The list below is what an installation does, not the
history that led to it.

### Added

- Python fetcher (`fetcher/`) that mirrors daily metrics and activities from
  Garmin Connect through
  [python-garminconnect](https://github.com/cyberjunky/python-garminconnect):
  three scheduled slots a day (`GARMIN_FETCH_TIMES`), a button in the header,
  a dated backfill (`garmin:fetch --backfill`), lap splits topped up per run,
  and an optional archive of the raw JSON.
- PostgreSQL mirror split by schema in one database: `public` for Laravel,
  `garmin_t{id}` for one athlete's mirror, `garmin_private` for every athlete's
  Garmin session. A reader role per athlete (`garmin_reader_t{id}`) holds
  `USAGE` on one schema and `SELECT` on its tables, and the connection switches
  into it before every read, so reaching another mirror fails in the server.
  `database/postgres/roles.sql` splits the single login role into app, fetch and
  reader roles where the dashboard is reachable from outside;
  `verify_roles.sh` asserts the grants hold.
- Dashboard (Laravel, Alpine, Chart.js) on one page with two areas: a muscle map
  with per-zone freshness and weekly volume per zone, and a training-load view.
  The derived models are computed on the fly in `app/Garmin/` (CTL/ATL/TSB, the
  acute:chronic ratio with a fallback, muscle freshness on a ~28 h half-life
  self-calibrated against 90 days of history).
- MCP server over `laravel/mcp` in two transports: local stdio for Claude Code
  and Claude Desktop, and hosted HTTP at `/mcp/garmin` for claude.ai, ChatGPT and
  anything else that speaks it. The hosted one runs OAuth 2.1 against the
  dashboard's own login (Laravel Passport, authorization code with PKCE `S256`,
  scope `mcp:use`), registers clients dynamically (RFC 7591) and answers the
  discovery documents in both their path-scoped and root form.
- Twelve MCP tools and one prompt: `get-health-summary-tool`,
  `get-insights-tool`, `get-muscle-map-tool`, `get-training-load-tool`,
  `get-strength-progress-tool`, `get-race-splits-tool`, `describe-schema-tool`,
  `query-health-data-tool`, `refresh-data-tool`, `log-symptom-tool`,
  `delete-symptom-tool`, `give-feedback-tool` and the `weekly-report` prompt.
  Each is gated by a switch at `/connect` that takes effect on the next tool
  call. Every call is recorded by `app/Mcp/LoggedTool.php` and read back with
  `php artisan mcp:usage`.
- The four curated read tools return the dashboard's own models rather than
  a second opinion: `get-insights-tool` carries the body-system verdicts and
  the early illness pattern the morning briefing is built on,
  `get-strength-progress-tool` the weekly reps, tonnage and top weights per
  exercise category. Where the numbers are computed in PHP, the chat gets
  them instead of re-deriving them in SQL and disagreeing with the page.
- `get-race-splits-tool` reads one session lap by lap: each lap is running or
  station work depending on the distance it covered, with pace per running
  lap, how far the pace drifted from the first lap to the last, and the time
  the clock ran while nobody moved. Sessions that alternate the two are what
  it is built for, which is the shape of a HYROX race; any lapped activity
  reads the same way.
- `/connect` asks which app is being connected and shows that one set of
  steps: Claude, ChatGPT, Langdock, LM Studio, or the local transport. The
  picker needs no JavaScript, so the instructions are readable before the
  bundle loads and wherever it never arrives.
- Free-form SQL from a model runs through `app/Garmin/ReadOnlyGarminQuery`: a
  single `SELECT` or `WITH`, a keyword blocklist, a read-only transaction and a
  500-row cap, on a connection already dropped into the athlete's reader role,
  and a refusal outright when that switch did not happen. Symptoms are the one
  thing the chat may write, and they go to the app's own schema, never into the
  mirror.
- Several athletes per installation, accounts created by whoever runs it
  (`php artisan app:create-user`, no sign-up page). Each keeps their own
  profile, connector permissions, guidelines, symptom log, devices, Garmin
  sign-in, notifications and mirror, and sees none of anybody else's; creating
  an account provisions its mirror there and then. `app:invite` and
  `/invite/{token}` hand out a one-time link, good for seven days (`--days`),
  so an invited person chooses their own password and the owner never learns
  it. Still not a sign-up: the link is issued for one address, spent on
  redemption, stored only as a hash, closed on a public demo, and a token
  nobody issued gets a 404 rather than a hint.
- Garmin sign-in at `/connect/garmin` or through `fetcher/login.py`, with the
  MFA code where Garmin asks for one. It stores an OAuth token pair and never
  the password, runs on a queue worker, and a first sign-in backfills ninety days
  so the dashboard is not empty until the next scheduled fetch. The waiting
  line under the header counts that first fetch in as it walks its ninety days
  ("day 34 of 90") and says when the daily values are in and the activities
  and their details are what remains; the fetch status endpoint carries the
  same numbers for anything else that polls it.
- Web push from four senders: the morning briefing, the health alerts, a
  bedtime-drift nudge and a Sunday reminder for the weekly report. The
  notification carries no payload. It wakes the service worker, which then asks
  the dashboard what to say, so no push service holds anything about somebody's
  health. `php artisan push:keys` makes the VAPID pair each installation signs
  with.
- Optional weather enrichment from [Open-Meteo](https://open-meteo.com) into
  `weather_hourly`, archive and forecast, backfilled across the history the
  mirror already holds. Nothing is requested until `WEATHER_LAT` and
  `WEATHER_LON` are set. The dashboard says three things with it in the load
  area (warm nights against deep sleep, heat against the session pulse, what
  a hot day ahead has cost before); the rest is read in the chat. Each athlete
  can name their own town under *Profile*, geocoded
  once through Open-Meteo's keyless search and passed to the fetcher as
  `--lat/--lon`, so two athletes on one installation are read under two
  skies; the environment stays the fallback for whoever names nothing.
- A public `/setup` page to hand an invited athlete: the four steps from the
  account they were given to a working chat connector, including the prefilled
  claude.ai dialog, the address to paste and the warning that the OAuth fields
  under *Advanced settings* stay empty. Linked from the guide at `/`.
- Demo seed `fetcher/seed_demo.py`: 120 days of generated data for a first look
  without a Garmin account, marked by a badge in the header, and it refuses to
  overwrite rows that are not its own.
- `DEMO_MODE` for an installation put on the open internet as a showcase: the
  Garmin sign-in, the connector and OAuth flows, the notifications and the
  manual fetch are closed behind one middleware and answer an explanation
  rather than an error, while everything that only reads stays open.
  `php artisan demo:reset` puts the account, the symptom log and the mirror
  back the way a visitor found them, nightly, and refuses to run at all where
  the switch is off.
- Docker Compose for the whole set (PostgreSQL, dashboard, queue worker,
  scheduler) and one image for a platform, started as either web or worker, with
  worked examples for Railway (`railway.toml`, `railway.worker.toml`) and Fly
  (`fly.toml`). `docker/boot.sh` is the release command and is safe to run on
  every deploy. A tag builds that image for amd64 and arm64 and publishes it as
  `ghcr.io/imgrund/hybridlog`, which is what Compose pulls before it falls back
  to building the tree in front of it.
- English interface with a German translation, following the browser language
  unless the profile says otherwise.

[Unreleased]: https://github.com/Imgrund/hybridlog/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/Imgrund/hybridlog/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/Imgrund/hybridlog/releases/tag/v0.1.0
