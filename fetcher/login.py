#!/usr/bin/env python3
"""Garmin Connect login, at a terminal or driven by the dashboard.

Prompts for email, password and (if enabled) the MFA code, then stores the
OAuth token pair in the database (garmin_private.garmin_session), where
fetch.py picks it up. The password itself is never stored, and nothing is
written to disk: on a platform that discards the filesystem on every
deploy, a token store in a file would mean an MFA prompt after every
deploy, on a machine with no terminal attached.

The tokens are valid for roughly a year and refresh themselves while
fetch.py runs, so this is a once-a-year command.

Usage:
    ./venv/bin/python login.py                 # at a terminal, tenant 1
    ./venv/bin/python login.py --tenant 2      # a second athlete's account
    ./venv/bin/python login.py --stdio         # driven by garmin:login

--tenant names whose Garmin account is being signed in to, by their
users.id, and decides which session row the tokens land in.

--stdio exists because Garmin's MFA cannot be answered in one shot: the
code is only sent once the password has been accepted, and the half-open
session that has to receive it lives inside the client object, not in
anything this process could hand to a second one. So the caller keeps
this process alive and talks to it instead: it writes the email and the
password, reads one status line, and writes the code if one is asked for.
Status lines carry a prefix, so a stray line of library output cannot be
mistaken for an answer.
"""

import getpass
import logging
import re
import sys

import psycopg
from garminconnect import Garmin

from fetch import connect, load_schema, save_tokens

# The caller matches on this, so it has to be something no library would
# print by accident.
STATUS = "__GARMIN__"


def enable_library_log() -> None:
    """Let the login library narrate to stderr, which is the worker's log.

    It tries five sign-in strategies in turn and only says which one it
    took at debug level. That mattered the first time a login sat waiting
    for a code that never arrived: whether Garmin was ever asked to send
    one depends entirely on which strategy got through, and without this
    the answer is unknowable after the fact.

    Only this library's logger, deliberately. Turning on the HTTP layers
    underneath would put request headers in the log, and one of those
    carries the password.
    """
    handler = logging.StreamHandler(sys.stderr)
    handler.setFormatter(logging.Formatter("garmin: %(message)s"))

    log = logging.getLogger("garminconnect")
    log.setLevel(logging.DEBUG)
    log.addHandler(handler)


def widget_page_title(client: object) -> str:
    """The heading of the page the widget route stopped on.

    That page is the whole of what the widget route knows. The library
    reads its title and nothing else: a title with "authentication
    application" in it means an emailed code, anything else with "mfa" in
    it is taken for an authenticator app, and the two are handled
    differently from there. When a code never arrives, this title is the
    only evidence of which of the two Garmin actually meant, so it
    travels with the channel instead of staying in a dead process.
    """
    response = getattr(client, "_widget_last_resp", None)
    text = getattr(response, "text", "") or ""
    match = re.search(r"<title[^>]*>(.*?)</title>", text, re.I | re.S)

    if match is None:
        return ""

    # One line, short: this ends up in a database column and a log, and
    # the title is a heading, not a document.
    return " ".join(match.group(1).split())[:80]


def mfa_channel(api: Garmin) -> str:
    """Where Garmin expects the code to come from, as far as it told us.

    Garmin sends the code to whichever second factor the account has set
    up, and an authenticator app is not sent at all. Guessing wrong in the
    interface costs someone a five-minute window spent looking in an inbox
    that was never going to receive anything, so we pass on what the
    library actually learned and nothing more.

    method is empty when the sign-in came through the HTML widget, which
    scrapes a page rather than calling the login API. That path cannot
    confirm Garmin sent anything, which is exactly what the reader of this
    line needs to know, so flow travels with it.
    """
    client = api.client
    method = getattr(client, "_mfa_method", "") or "unknown"
    flow = getattr(client, "_mfa_flow", "") or "unknown"
    detail = f"method={method} flow={flow}"

    if getattr(client, "_mfa_delivery_uncertain", False):
        detail += " delivery=unconfirmed"

    # Only for the widget route: the other routes are told the method by
    # Garmin outright, so there is nothing a page heading could add.
    if flow == "widget":
        title = widget_page_title(client)
        if title:
            detail += f" page={title}"

    return detail


def emit(status: str, detail: str = "") -> None:
    """Send one status line to the caller, right now.

    Flushed on purpose: the caller is blocking on this line, and a status
    left sitting in a buffer is a caller waiting for its timeout.
    """
    print(f"{STATUS} {status} {detail}".rstrip(), flush=True)


def store_and_verify(conn: psycopg.Connection, api: Garmin, tenant: int = 1) -> str:
    """Store the session, prove it works, return the account name.

    The session is read back the way fetch.py will read it and then used
    to log in. A stored value that logs in is the only proof that counts;
    anything short of it leaves the next unattended run to find out.
    """
    save_tokens(conn, api.client.dumps(), tenant)

    check = Garmin()
    check.login(conn.execute(
        "SELECT tokens FROM garmin_private.garmin_session WHERE id = %s", (tenant,)
    ).fetchone()[0])

    return check.get_full_name()


def run_interactive(conn: psycopg.Connection, tenant: int = 1) -> int:
    print("Garmin Connect login (the session is stored in the database)")
    email = input("Email: ").strip()
    password = getpass.getpass("Password (hidden): ")

    # No token store path is passed anywhere in here, so the library has
    # nowhere to write a file even in passing.
    api = Garmin(
        email=email,
        password=password,
        # api is bound by the time the library calls this, so the prompt
        # can name the place the code is actually coming from.
        prompt_mfa=lambda: input(f"MFA code [{mfa_channel(api)}]: ").strip(),
    )
    try:
        api.login()
    except Exception as exc:  # noqa: BLE001 - the reason belongs on screen, not in a traceback
        print(f"Login failed: {type(exc).__name__}: {exc}", file=sys.stderr)
        return 3

    print(f"Login OK: {store_and_verify(conn, api, tenant)}. The session is stored, fetch.py can run.")

    return 0


def run_stdio(conn: psycopg.Connection, tenant: int = 1) -> int:
    """Log in under the caller's control, one status line at a time."""
    email = sys.stdin.readline().strip()
    password = sys.stdin.readline().rstrip("\n")

    if not email or not password:
        emit("FAILED", "MissingCredentials: email and password are both required")
        return 2

    # return_on_mfa hands the code prompt back to the caller rather than
    # to a terminal that is not there.
    api = Garmin(email=email, password=password, return_on_mfa=True)
    try:
        mfa_status, _ = api.login()
    except Exception as exc:  # noqa: BLE001 - the caller has to show this to the athlete
        emit("FAILED", f"{type(exc).__name__}: {exc}")
        return 3

    if mfa_status == "needs_mfa":
        emit("MFA_REQUIRED", mfa_channel(api))
        code = sys.stdin.readline().strip()
        if not code:
            emit("FAILED", "MissingMfaCode: no code was supplied")
            return 2
        try:
            # The client state in the signature is held inside the client
            # itself; the library ignores the argument.
            api.resume_login({}, code)
        except Exception as exc:  # noqa: BLE001
            emit("FAILED", f"{type(exc).__name__}: {exc}")
            return 3

    try:
        name = store_and_verify(conn, api, tenant)
    except Exception as exc:  # noqa: BLE001 - a session that cannot be reused is a failed login
        emit("FAILED", f"{type(exc).__name__}: {exc}")
        return 3

    emit("OK", name)
    return 0


def tenant_from_argv(argv: list[str]) -> int:
    """Read --tenant N, defaulting to the installation's first athlete.

    Parsed by hand like --stdio above rather than through argparse: this
    process talks to the dashboard over a line protocol on stdin and stdout,
    and argparse's own error handling writes to stderr and exits, which that
    protocol has no room for.
    """
    if "--tenant" not in argv:
        return 1

    try:
        return max(1, int(argv[argv.index("--tenant") + 1]))
    except (IndexError, ValueError):
        return 1


def main() -> int:
    stdio = "--stdio" in sys.argv[1:]
    tenant = tenant_from_argv(sys.argv[1:])
    enable_library_log()

    try:
        conn = connect(tenant)
    except psycopg.Error as exc:
        if stdio:
            emit("FAILED", f"DatabaseUnavailable: {exc}")
            return 4
        print(f"Database connection failed: {exc}", file=sys.stderr)
        print("Set GARMIN_FETCH_DSN (or DATABASE_URL) to the mirror database.",
              file=sys.stderr)
        return 4
    load_schema(conn, tenant)

    try:
        return run_stdio(conn, tenant) if stdio else run_interactive(conn, tenant)
    finally:
        conn.close()


if __name__ == "__main__":
    sys.exit(main())
