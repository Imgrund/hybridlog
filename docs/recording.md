# Recording, weather and notifications

Three things that decide how much the dashboard has to work with: how the
watch records, where the weather comes from, and what reaches the phone.

## Recording rules (data quality)

1. Chest strap (HRM-Pro) for circuit work, intervals and HYROX. Wrist HR
   breaks down under load and the error propagates into load/recovery
   metrics.
2. Record strength parts as *Strength*, circuits as *HIIT*, which keeps
   set data clean and feeds the muscle map.
3. Wear the watch at night (>= 4 nights/week), because the HRV baseline
   and readiness need ~3 weeks of nights.
4. HYROX: lap button at every roxzone, or the ROXFIT Connect IQ app.

### Morning vs. current readiness

Garmin has two readiness truths and the mirror stores both. The morning
endpoint freezes the wake-up state and is the canonical daily value;
history and trends use it. The watch then recomputes score and recovery
time after every workout, and by the evening the two can be a workout
apart (seen in practice: morning 86 with 0 h open, actual 35 with 51 h).
`fetch.py` therefore also stores an intraday snapshot
(`current_*` columns, today's row only), and every reader that makes a
now-statement (the morning briefing, the health alerts and the MCP
summary) prefers it over the frozen morning value.

## Weather (optional: the half Garmin does not hand over)

Four numbers in this mirror cannot be read honestly without the weather.
Deep sleep collapses on muggy nights and the sleep card alone calls that
a bad night. Heart rate drifts upward in heat, so the same work at 30 °C
looks harder than it was and the load model reads it as fatigue. Sweat
loss and the hydration goal are computed by Garmin with a weather input
the dashboard never saw. Daytime heat lifts resting heart rate and
baseline stress on its own.

Set `WEATHER_LAT` and `WEATHER_LON` (decimal degrees, your home location)
and the fetcher mirrors hourly conditions from
[Open-Meteo](https://open-meteo.com) into `weather_hourly`: free,
no key, non-commercial use, 10.000 calls a day. It spends one or two of
them per run whatever the range, because the whole span arrives in a
single request per source, and on its first run it backfills every day
the mirror already holds, so the window starts filling from your history
rather than from the day you switched it on. The archive (ERA5) covers
everything older than a week, the forecast endpoint the days since and
the next two ahead. `fetch.py --weather-only` fetches just this, without
a Garmin session.

Leave both empty and nothing happens: no request is made, no row is
written, and the dashboard shows nothing about it. There is deliberately
no default, because the coordinates are the one thing an installation
sends to a third party. Open-Meteo receives a location and a date range,
never health data, an account or a session.

With more than one athlete on the installation, that pair is the
fallback rather than the answer: an installation has one location and
its athletes may not share it. Each of them can name a town under
**Profile → Where you train**, which is geocoded once through
Open-Meteo's keyless search and stored as coordinates on their profile;
their fetch then runs with `--lat/--lon` and reads their own sky.
Whoever names nothing keeps the environment's location. The fetcher is
handed the pair on the command line rather than looking it up, because
the role it connects as has no rights in the schema the profiles live
in.

The dashboard says three things with it, in the load area: what warm
nights did to deep sleep, what heat did to the pulse in circuit
sessions, and what a hot day ahead has cost before. Everything past
those three is the chat's. `query-health-data` reads `weather_hourly`
like any other table, on the same rule every finding here follows:
co-occurrence, not cause.

Only one location for the whole mirror. Garmin does report activity start
coordinates, but the fetcher does not store them, and a per-session
location would say nothing about the nights, which is where most of the
signal is. Each row keeps the coordinates it was fetched for, so moving
the location leaves the history legible instead of silently
reinterpreting it.

## Notifications (optional: the morning verdict on the phone)

Four things push, all composed from the mirror at the moment they are
shown. The morning briefing after the first fetch of the day: readiness,
the verdict, and up to three focus lines (open recovery time, a load
ratio outside its corridor, the most loaded muscle zone). The health
alerts, when the morning numbers trip the illness pattern. An evening
nudge only while the bedtime window is drifting. And a Sunday reminder
whose tap opens the chat with the weekly-report prompt, because the
report itself is the model's job, not this server's. Entirely optional;
the dashboard says everything without them.

There is no shared key pair: the public half of the pair is the identity
every browser subscription on this installation is bound to. The
variables therefore ship empty and each installation makes its own.

```bash
php artisan push:keys      # prints the two lines to put into the environment
```

`VAPID_SUBJECT` is where a push service can complain about this sender, a
mailto: or https: address as RFC 8292 asks for. With the pair in place
the switch appears at `/connect/notifications` (linked in the header
menu), and every device is allowed separately, on the device itself. Keep
the pair once devices have subscribed to it: replacing it unsubscribes
all of them without telling anybody. On iPhone and iPad the dashboard has
to be on the home screen before iOS offers notifications at all (Share,
"Add to Home Screen", then start it from there), which is Apple's
condition rather than this project's.

When it stays quiet is the more useful half. Each sender fires at most
once a day, and only while it has something to say: no briefing without
today's data, no nudge while the bedtime holds, no alert without the
pattern. A briefing built on yesterday's readiness would be a confident
sentence about the wrong day, so silence is the honest answer then.

The notification itself carries no payload. It wakes the service worker
(`public/sw.js`) empty, which then asks this dashboard what to say, so
the push services of Google, Mozilla and Apple never hold anything about
somebody's health, not even encrypted, and a briefing that expired in
between shows nothing at all. It also means there is no ECDH, no HKDF and no
AES-GCM to get right, which is what a web-push library would have been
for: `app/Push/` signs a VAPID header (ES256 over P-256) and posts an
empty body. Per device the database holds the endpoint and a label,
nothing more, and an endpoint whose push service answers 404 or 410 is
deleted on the spot.
