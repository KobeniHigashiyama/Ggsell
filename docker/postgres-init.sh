#!/bin/bash
set -e
# Tests exercise real transactions and locks, so they need a database separate
# from development data.
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    CREATE DATABASE ${POSTGRES_DB}_test OWNER $POSTGRES_USER;
SQL
