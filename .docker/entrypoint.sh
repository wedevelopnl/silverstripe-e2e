#!/bin/sh
set -e

composer install --no-interaction

# FrankenPHP ships a default phpinfo() index.php. Replace it with the SilverStripe
# bootstrap once composer install has made the recipe available.
cp -f vendor/silverstripe/recipe-core/public/index.php /app/public/index.php

# Ensure all vendor package resources are exposed. composer install skips the
# vendor-expose step when the named Docker volume already has packages from a
# previous run (no post-install event fires).
composer vendor-expose

vendor/bin/sake dev/build flush=1

touch /tmp/.app-ready

exec frankenphp run --config /etc/caddy/Caddyfile
