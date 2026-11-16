#!/bin/sh
set -e

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
