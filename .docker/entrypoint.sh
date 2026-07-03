#!/bin/sh
set -e

composer install --no-interaction

# Build the schema + class/config manifest so silverstan and sapphire tests have
# a ready environment. DB is guaranteed up (compose depends_on: db healthy).
vendor/bin/sake dev/build flush=1

touch /tmp/.app-ready

# No webserver: block so `docker compose exec` can run tests/analysis in this container.
exec tail -f /dev/null
