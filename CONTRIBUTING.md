# Contributing

This is a personal project, published because it may be useful to someone
else with the same watch and the same training. That sets the terms
honestly: issues and pull requests are welcome, nothing is promised about
response time, and a change that does not fit the project's shape may be
declined even when it is well built. Reading the two lists at the bottom
first will save the most time.

## Running it

The quickest way to a working copy is the Docker quickstart in the
[README](README.md#quickstart-docker-demo-data-no-garmin-account): it
seeds 120 days of generated data, so the whole dashboard is visible
without a Garmin account. Nothing here needs real health data, and none
of it should be committed.

## Before opening a pull request

CI runs exactly three things, and they are cheap to run first:

```bash
npm run build          # the dashboard's JS carries translatable strings
vendor/bin/pint --test # formatting, the same rules CI enforces
php artisan test       # the suite
```

The suite needs a real PostgreSQL. It asserts against Postgres behaviour
rather than SQLite's, so it does not run on a file database:

```bash
createdb garmin_test
```

CI uses PHP 8.4, Node 22 and PostgreSQL 16. See
[.github/workflows/tests.yml](.github/workflows/tests.yml) for the exact
setup.

## What the tests will hold you to

- **English is the source language.** User-facing strings sit inline in
  `__()`, `trans_choice()` or the JS helper `T()`, never in a language
  file keyed by identifier. `lang/de.json` is the German translation of
  exactly those strings, and `TranslationCoverageTest` checks both
  directions: a new string without its German line fails, and so does a
  German line whose string no longer exists.
- **Formatting is not a matter of taste.** `vendor/bin/pint` settles it;
  run it before pushing rather than arguing with the diff.

## Conventions worth knowing

- Comments explain *why*, not what. The code says what it does; a comment
  earns its place by carrying the reason, the constraint or the failure it
  prevents. Several files read like this already, which is the standard to
  match rather than an accident.
- Derived metrics live in `app/Garmin/` and are computed on the fly, not
  stored. A new model belongs there, with a test that pins its behaviour
  against known input. The exceptions are the two things derived from the
  calendar, and both say why in their migration: appointments are deleted
  after a fortnight on purpose, so `calendar_days` and the meeting columns
  on `stressor_log` are written while their input still exists, because a
  season to find anything in is longer than a fortnight.
- The mirror's schema (`garmin`) is owned by the Python fetcher, not by
  Laravel's migrations, because the fetcher must be able to create it
  without a PHP runtime present. Changes to it go in
  `fetcher/`, changes to the app's own tables go in `database/migrations/`.
- The dashboard reads the mirror through a connection that may only read
  it. Anything that writes to the mirror is the fetcher's job.

## Likely to be accepted

- A Garmin metric the fetcher does not pull yet, with the dashboard or MCP
  surface that makes it useful.
- Fixes for what Garmin changed. The web API is unofficial and moves; a
  pull request that repairs a broken endpoint is the most valuable thing
  this repository can receive.
- Sharper models: better calibration for training load, muscle freshness
  or readiness, argued with data rather than preference.
- Specifics of a sport the models handle only generically. Race formats
  that alternate running with station work (HYROX above all) are the ones
  already thought about; anything else is the part where a second
  athlete's experience is worth more than any amount of solo polish.
- Translations of the English source strings into a third language, if you
  are willing to keep them current.
- Documentation that removes a step from someone's first hour.

## Deliberately out of scope

These are decisions, not gaps. A pull request that adds one will be
declined regardless of how well it is written:

- **A second data source.** Depth on Garmin is the whole argument. A
  normalised multi-vendor layer is a different project, and a good one
  already exists.
- **Billing.** Multiple athletes on one installation already work, each
  with their own mirror schema and reader role. Charging them is the
  line: no plans, no seats, no metering.
- **Apple Health.** Considered and rejected.
- **A hosted service.** Self-hosting is the model.
- **Medical claims.** Nothing here diagnoses anything, and no wording
  should suggest otherwise.

## Reporting a bug

What helps most: what you did, what happened, what you expected, and
whether the fetcher or the dashboard produced it. Fetch failures are
recorded in the `fetch_log` table and surfaced on the dashboard, which is
usually the fastest way to tell a Garmin outage from a bug here.

Please do not attach real health data. Generated data from
`fetcher/seed_demo.py` reproduces most display bugs.

Security problems do not belong in an issue. See
[SECURITY.md](SECURITY.md).

## Licence

Contributions are accepted under the [MIT licence](LICENSE), the same one
the project ships under.
