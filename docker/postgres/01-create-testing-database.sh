#!/bin/sh
set -e

# Runs only on a fresh volume. Creates the database used by the test suite.
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    CREATE DATABASE "${POSTGRES_DB}_testing" OWNER "$POSTGRES_USER";
SQL
