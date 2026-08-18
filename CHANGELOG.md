# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **A fetch without a Garmin session is turned down by name instead of
  failing the queue job.** The Quickstart's exact state: seeded data, an
  account, no Garmin sign-in yet. The header button and the refresh-data
  tool both launched fetch.py anyway, and its certain failure became a
  RuntimeException with a stacktrace and a failed_jobs row, for a state
  every installation is in until its first sign-in. Every door now
  refuses with the sentence the header's status line already uses: the
  page shows it as a flash with the sign-in button next to it, the MCP
  answer carries the sign-in URL, and a job that lost its session
  between dispatch and pickup records the reason for the waiting page
  instead of dying. Real failures, a stored session that stopped working
  or a timeout, land in failed_jobs unchanged.

## [0.1.7] - 2026-08-17

### Fixed

- **Mistral's connector debugger can register now.** Le Chat and Studio
  take delivery on `callback.mistral.ai`, which the allowlist has carried
  since the day Le Chat was let in, but the debugger in the console comes
  back to a host of its own,
  `console.mistral.ai/build/connectors/debugger/oauth-callback`. It was
  refused at step 6 of its own twelve with `redirect_uris.0 is not a
  permitted redirect domain`, so the one tool built to diagnose a
  connection could not reach the connection it was pointed at, while Le
  Chat next to it worked. Both hosts are listed now and both are pinned in
  the transport test under the client names they arrive with, so the next
  reader can tell them apart.

## [0.1.6] - 2026-08-16

### Fixed

- **`round(expr, 1)` now simply works in `query-health-data`.** Nearly
  every measurement column is double precision, and Postgres defines the
  two-argument `round()` only for numeric, so the single most natural
  line of SQL a model writes against this mirror died with an error and
  spent its retry on a cast. The mirror now ships that overload in its
  own schema (`fetcher/schema.sql`, so every fetch carries it to
  existing installations); the explicit `round((expr)::numeric, 1)`
  keeps resolving to Postgres' own function as before. The schema notes
  and the query error only mention the cast on a mirror the fetcher has
  not touched since — asked of the mirror, not assumed.
- **A column that does not exist now gets answered with the ones that
  do.** An invented column name usually recombines real fragments
  (`sleep_factor_feedback` for `sleep_score_factor`), which is exactly
  the case Postgres' own "Perhaps you meant" stays silent on, so the
  model's retry was another guess. The error now names the closest
  existing columns, ranked by shared name fragments, with tables the
  athlete has switched off hidden from the list exactly as
  `describe-schema` hides them.

## [0.1.5] - 2026-08-16

### Changed

- **The README now says what the mirror actually is.** It kept describing
  a set of readings when the thing underneath is a database that holds
  Garmin's answers whole: a field nobody has built a column for is still
  one question away, and a day Garmin Connect no longer serves is still
  here. The two tools that make that reachable, `describe-schema` and
  `query-health-data`, are named as what they are rather than listed as
  two more rows. "No hosted instance" is now a sentence anyone can check:
  the Garmin session is in a schema of your own database that no reader
  role can reach, and past Garmin the only things that reach out are the
  weather, on coordinates you type in yourself, and a push that wakes a
  device with an empty POST.
- **Tagging a release now lists it in the MCP registry.** That was a hand
  step after the tag, and a hand step after the tag gets skipped: 0.1.4
  shipped its image while the registry went on describing 0.1.3. The
  release workflow does it, authenticating over GitHub Actions OIDC rather
  than a stored secret, and refuses to publish when `server.json` names a
  version other than the tag being released.

### Fixed

- **The size of `raw_payload` was quoted too high.** Measured over one
  day it read as 25 payloads and 50 KB; over eighty it is 14 payloads and
  45 KB, so a year of one athlete is about 16 MB rather than under 20 MB.
  The schema comment and `docs/recording.md` carried the first number.
- **The weather documentation claimed more than the code computes.** It
  said deep sleep collapses on muggy nights, where `Weather.php` only
  contrasts the athlete's own nights by dew point and the card says "went
  with". It now reads as the co-occurrence it is, and says out loud that a
  cooled bedroom or an indoor session breaks the chain, since all three
  weather findings read the sky outside.

## [0.1.4] - 2026-08-16

### Added

- **Nothing Garmin sends is dropped any more.** Every endpoint answer is
  kept whole in a new `raw_payload` table, written by the one function
  every call already passes through, so endpoints added later are covered
  without anyone wiring them up. It is `jsonb` rather than text because
  this one exists to be queried into: a field no column covers is now a
  question the mirror can still answer, which until now meant asking
  Garmin again for a day it no longer serves. One day is 25 payloads,
  about 50 KB stored, so a year of one athlete is under 20 MB.
- **Thirty-six columns for what the payloads were already carrying.**
  The night gains skin temperature against the athlete's own baseline,
  stress and heart rate while asleep, wake-ups, restless moments,
  breathing interruptions, blood oxygen, the battery it recharged,
  Garmin's own sleep need, and the optimal window with the midpoint
  actually slept, which is social jetlag measured rather than felt. The
  day gains its stress split (low, medium, high, and the part explained by
  training), body battery at wake-up and across the night, and Garmin's
  seven-day resting heart rate. Readiness gains the reasons beside the
  percentages, so a score of 62 can say it was HRV and not sleep. Training
  status gains what the monthly load is short of, in Garmin's words.

### Fixed

- **A table added to the schema could stay invisible to the reader.** The
  mirror check asked only whether the tenant's reader held `usage` on the
  schema, which a new table says nothing about. Where the fetcher and the
  app connect as different roles, the default privileges in `tenant.sql` do
  not cover it, and the symptom is a table that exists, is listed by
  `describe-schema`, and refuses every select. The check now asks after the
  tables too, so the next request repairs it.
- **HRV across the night was never stored.** The column read `avgSleepHRV`
  and `avgOvernightHrv` is what Garmin sends, so `sleep.avg_sleep_hrv` was
  null in every row since the first fetch while the number sat in every
  payload. Demo data filled the column, which is why nothing looked wrong.
- **VO2max arrived on nine days out of 139.** It was read from
  `max_metrics`, an endpoint that answers `[]` on this account, while the
  hill-score payload carries `vo2Max` daily and was discarded whenever the
  hill score itself was missing. Both now write, and neither overwrites
  the other with a null.
- The comment on `days.spo2_avg` said the sensor was switched off. It
  reports values again, so the column now describes what a run of nulls
  means rather than asserting a cause.

## [0.1.3] - 2026-08-16

### Added

- Mistral connects, from Le Chat and from Studio alike. Their custom MCP
  connectors speak streamable HTTP and OAuth 2.1 with dynamic client
  registration, which is what the hosted transport already served, so the
  whole of it is one callback host in `config/mcp.php` next to the steps
  at `/connect` and in the guide. That host is `callback.mistral.ai`,
  which Mistral documents nowhere and which is not the `chat.mistral.ai`
  of the address bar, so the suite pins both the spelling that works and
  the one that must keep failing. Verified against a real connector: the
  registration goes through, the twelve tools arrive, and a connector
  added in either place is connected in both. Custom connectors carry no
  prompt templates yet, so the `weekly-report` prompt is absent there
  while the tools are not.

### Changed

- The client is named *Le Chat / Vibe* wherever it is offered. Mistral
  renamed the product to Vibe while their own documentation still says Le
  Chat, and somebody holding one of the two names has to find it under
  the other.

## [0.1.2] - 2026-08-16

### Fixed

- The release workflow set `io.modelcontextprotocol.server.name` itself, in
  the old spelling, and what it sets wins over the Dockerfile's `LABEL`. So
  0.1.1 shipped an image that still carried the lowercase name and the
  registry refused it a second time. All four places that name the server
  now agree, and `ServerManifestTest` fails the suite when they drift:
  nothing else notices, because the image builds and pushes either way and
  the refusal only arrives at publishing time, by which point the label is
  baked into a released image.

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

[Unreleased]: https://github.com/Imgrund/hybridlog/compare/v0.1.7...HEAD
[0.1.7]: https://github.com/Imgrund/hybridlog/compare/v0.1.6...v0.1.7
[0.1.6]: https://github.com/Imgrund/hybridlog/compare/v0.1.5...v0.1.6
[0.1.5]: https://github.com/Imgrund/hybridlog/compare/v0.1.4...v0.1.5
[0.1.4]: https://github.com/Imgrund/hybridlog/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/Imgrund/hybridlog/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/Imgrund/hybridlog/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/Imgrund/hybridlog/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/Imgrund/hybridlog/releases/tag/v0.1.0
