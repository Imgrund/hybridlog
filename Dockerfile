# syntax=docker/dockerfile:1

# One image for the whole installation: the dashboard, the queue worker that
# runs the fetches and the scheduler that starts them. They differ only in
# the command they are given, which is why there is no second Dockerfile and
# no second copy of the application to keep in step.


# --- PHP dependencies --------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app
# The whole tree, not just the two composer files: the install runs
# `artisan package:discover`, and that boots the application.
COPY . .
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

# Compiled Blade output, built here rather than only in the final stage
# because the assets stage below needs it and has no PHP of its own. The
# @source in resources/css/app.css that points at storage/framework/views
# is the reason: a handful of utilities exist nowhere but in a compiled
# vendor view, the MCP consent screen most of all, and against an empty
# directory Tailwind never sees them. The final stage compiles its own
# copy again, which is what actually ships; this one exists to be read.
RUN mkdir -p storage/framework/views && php artisan view:cache


# --- Front-end assets --------------------------------------------------
FROM node:26-bookworm-slim AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# Tailwind reads class names out of the vendor tree as well (@source in
# resources/css/app.css), so the dependencies have to be in place before
# the build rather than after it.
COPY --from=vendor /app/vendor ./vendor
# And the compiled views, for the second @source. Both directories have to
# stand before the build, never after it: Tailwind scans once.
COPY --from=vendor /app/storage/framework/views ./storage/framework/views
RUN npm run build


# --- Runtime -----------------------------------------------------------
# Debian rather than the -alpine variant: psycopg publishes binary wheels
# for glibc only, and building it from source would mean a compiler and the
# libpq headers in the image.
FROM dunglas/frankenphp:php8.4

# Not decoration: this label is how the MCP registry knows the image and the
# server.json in this repository are the same project. A server that ships as
# a container is only accepted once the image itself names the server it
# claims to be, and the name has to match server.json character for character.
# Character for character includes the capital I: the namespace follows the
# GitHub account name, and the registry compares the two case-sensitively.
# Drop this line and the next release stops being publishable.
LABEL io.modelcontextprotocol.server.name="io.github.Imgrund/hybridlog"

# The usual OCI set, so that an image someone pulled a year from now still
# says where it came from and under which licence it may be used.
LABEL org.opencontainers.image.source="https://github.com/Imgrund/hybridlog" \
      org.opencontainers.image.description="Self-hosted MCP server on your own Garmin data, with the dashboard it authenticates against" \
      org.opencontainers.image.licenses="MIT"

# pdo_pgsql to reach the database, pcntl so the queue worker can enforce its
# own timeout and stop when asked, opcache because otherwise every request
# compiles the same files again.
RUN install-php-extensions pdo_pgsql pcntl opcache

# The fetcher is Python and stays Python: python-garminconnect speaks
# Garmin's unofficial web API and has no PHP equivalent. Its virtualenv sits
# outside /app so that a bind-mounted source tree cannot hide it. curl is
# for the health check below, nothing else.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl python3 python3-venv \
    && rm -rf /var/lib/apt/lists/* \
    && python3 -m venv /opt/fetcher
COPY fetcher/requirements.txt /tmp/requirements.txt
RUN /opt/fetcher/bin/pip install --no-cache-dir -r /tmp/requirements.txt \
    && rm /tmp/requirements.txt

WORKDIR /app
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Which service providers exist, written once here from what is actually
# installed rather than by whichever process first needs it at run time.
RUN php artisan package:discover --ansi

RUN mkdir -p storage/framework/cache/data storage/framework/sessions \
             storage/framework/views storage/logs bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && chmod +x docker/boot.sh docker/serve.sh docker/work.sh

# Everything that can be decided before the image ships. Without these the
# framework compiles every Blade template on the request that first needs
# it and rebuilds the route table on every request after that: the first
# page load after a deploy cost 2.8s, each one after it 0.8s.
#
# Only the two that are pure code. The config cache is not baked here on
# purpose: this image is built without the environment it will run in, so
# a config frozen now would carry a missing APP_KEY and an empty
# DATABASE_URL into production. serve.sh writes that one at start-up,
# where the platform's variables exist.
RUN php artisan view:cache \
    && php artisan route:cache

# Plain HTTP, because every platform in front of this terminates TLS itself.
# A host name here would make Caddy try to obtain a certificate for a name
# it does not control, and fail the boot doing it. Where the platform picks
# the port rather than accepting this one, docker/serve.sh rewrites it.
ENV SERVER_NAME=:80

# The image installs the fetcher's requirements system-wide, so the default
# from config/garmin.php (the README's virtualenv) does not apply here.
ENV GARMIN_FETCH_COMMAND="/opt/fetcher/bin/python /app/fetcher/fetch.py"

# The raw JSON archive is for a machine that keeps its disk. Here it would
# fill the layer and be discarded on the next deploy.
ENV GARMIN_RAW_DIR=""

EXPOSE 80

# Off, not absent. The base image checks Caddy's admin endpoint, and the same
# image also runs the worker and the scheduler, neither of which starts Caddy:
# inherited, that check reports two of the four services unhealthy forever.
# compose.yaml declares a real one on the service that actually serves.
HEALTHCHECK NONE

# Cleared so that `command:` in compose is the whole command. The image's
# own entrypoint would otherwise prefix it.
ENTRYPOINT []
CMD ["/app/docker/serve.sh"]
