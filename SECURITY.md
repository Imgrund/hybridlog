# Security

This project holds the health data of every athlete on the installation
and, in a hosted one, the credentials that reach their Garmin accounts.
Both deserve a private reporting path, so please do not open a public
issue for a security problem.

## Reporting

Use GitHub's private vulnerability reporting: the **Security** tab of this
repository, then *Report a vulnerability*. It creates a private thread
only you and the maintainer can read.

If that button is not available to you, open a normal issue that says a
security report is waiting and asks for a private channel, **without any
detail about the problem itself**. A one-line placeholder is enough.

What makes a report actionable: the version you are running (a release
tag or a commit hash), how the installation is exposed (local only,
behind a reverse proxy, publicly reachable), and the smallest sequence
that reproduces it.

## What to expect

A personal project, so: best effort and no fixed timeline. You will get an
acknowledgement, an honest answer about whether it is a real problem, and
credit in the fix unless you prefer otherwise. There is no bounty.

Releases are tagged. Supported are the newest release and `main`: a
security fix lands in `main` and goes out with the next tag, and
installations update by pulling or by moving to that tag. There are no
version branches, and nothing is backported to an older release.

## In scope

- The hosted surface: OAuth 2.1 and dynamic client registration, the MCP
  endpoint at `/mcp/garmin`, the session login, `TrustHosts` and
  `TrustProxies`, the rate limits, token lifetimes.
- The Garmin credential path: `/connect/garmin` takes a password, hands it
  to a queue worker inside an encrypted job payload and never writes it to
  the database. Anything that causes it to come to rest, appear in a log
  or reach a failed job is a vulnerability, not a bug.
- The stored Garmin sessions in the `garmin_private` schema, one per
  athlete, each a bearer credential for a whole Garmin account.
- The database privilege split: the dashboard reads a mirror through a
  role that may only read that one athlete's, so that SQL written by a
  language model cannot reach the users table, the OAuth tokens or
  somebody else's numbers. A way around that boundary is in scope.
- The boundary between athletes. An installation carries several, each
  with their own mirror schema, reader role, settings, symptom log, push
  devices and connector scope. Anything that serves one athlete's data
  to another is in scope: a request that resolves the wrong tenant, a
  scheduled sender composing from the wrong mirror, a tool call answered
  from a mirror that is not the caller's.
- Anything that lets one installation's data leave it, including through
  an MCP tool.

## Out of scope

- The unofficial Garmin Connect web API itself. Problems in it belong to
  [python-garminconnect](https://github.com/cyberjunky/python-garminconnect)
  or to Garmin.
- An installation that is publicly reachable without `TRUSTED_HOSTS`,
  without HTTPS or with `APP_DEBUG=true`. The README says what a public
  deployment needs; skipping it is a configuration mistake, and one the
  documentation should prevent, so a documentation gap is worth reporting
  as an ordinary issue.
- The demo seed. `fetcher/seed_demo.py` generates data and is meant to be
  visible.
- Dependency advisories with no path to exploitation here. A pull request
  that bumps the dependency is more useful than a report.
