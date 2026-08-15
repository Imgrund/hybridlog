#!/bin/sh
# How the dashboard is started inside the image.
#
# It does one thing, and that one thing is the reason the file exists: a
# platform that assigns the port hands it over in PORT, and the server has
# to be told before it binds. Railway works this way. A container that
# ignores it listens on 80 while the router talks to a port nobody is on,
# which surfaces as a 502 with an empty log on both sides.
#
# Fly.io and compose name the port themselves and set no PORT, so there
# this is a passthrough and the image's own SERVER_NAME stands.

set -eu

if [ -n "${PORT:-}" ]; then
    # Only an address without a host name is rewritten. Where SERVER_NAME
    # carries one, this container is the thing on the internet and holds
    # its own certificate for that name; replacing it would drop TLS with
    # it, on the assumption that a platform is in front. It might not be.
    case "${SERVER_NAME:-}" in
        '' | :*)
            SERVER_NAME=":$PORT"
            export SERVER_NAME
            ;;
    esac
fi

# The config cache, written here rather than into the image, because the
# platform's variables only exist now. Without it every request re-reads
# and re-merges every file under config/.
#
# Not fatal if it fails: the application reads the files directly then, so
# it serves correctly and only slower. Saying so beats a container that
# refuses to start over a cache. Nothing in this codebase calls env()
# outside config/, which is the one thing a config cache breaks.
if ! php /app/artisan config:cache --no-interaction; then
    echo "serve: config cache failed, serving uncached" >&2
fi

exec frankenphp run --config /app/docker/Caddyfile
