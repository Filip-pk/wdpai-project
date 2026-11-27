#!/bin/sh
# Prints a plain SQL dump of a database built only from database/*.sql: the schema,
# the six starter anime and the demo accounts, without any local or personal data.
# Run it in the db container; README.md ("Eksport i import bazy") shows the command.
# It lives in a subdirectory because PostgreSQL runs top-level *.sh files on first start.
set -eu

user="${POSTGRES_USER:?POSTGRES_USER is not set}"
name="anivibe_export_$$"

psql -v ON_ERROR_STOP=1 -q -X -U "$user" -d postgres -c "CREATE DATABASE $name" >&2
trap 'psql -q -X -U "$user" -d postgres -c "DROP DATABASE IF EXISTS $name" >&2' EXIT

for file in /docker-entrypoint-initdb.d/*.sql; do
    psql -v ON_ERROR_STOP=1 -q -X -U "$user" -d "$name" -f "$file" >&2
done

pg_dump -U "$user" -d "$name" --no-owner --no-privileges
