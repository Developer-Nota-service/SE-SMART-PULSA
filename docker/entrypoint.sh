#!/bin/sh
# Container entrypoint only starts the requested process.
# Database migrations/seeding run once in the dedicated Compose "migrate" job,
# never independently in web, worker, or scheduler replicas.
set -eu

if [ "$#" -eq 0 ]; then
    set -- apache2-foreground
fi

exec "$@"
