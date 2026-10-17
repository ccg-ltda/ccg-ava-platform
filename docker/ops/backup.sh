#!/bin/sh
# Scheduled backup of the Ava database (PostgreSQL custom format) with evidence.
#
#   BACKUP_DIR=/var/backups/ava ./docker/ops/backup.sh
#   COMPOSE_FILE=docker-compose.prod.yml ENV_FILE=prod.env BACKUP_DIR=/var/backups/ava ./docker/ops/backup.sh
#
# It only READS the database (pg_dump). It never drops, truncates or restores anything.
# Evidence per run: <dir>/ava_<stamp>.dump, a SHA-256 file, a `pg_restore --list` check, and a line in <dir>/backup.log.
# Exit code is not 0 if the dump failed, is empty, or its table of contents cannot be read: wire that to your scheduler's alerting.
# Files and Spaces/MinIO objects are NOT covered here (use the provider's bucket versioning or snapshots).
set -eu

BACKUP_DIR="${BACKUP_DIR:?BACKUP_DIR is required (a folder OUTSIDE the repository)}"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
KEEP_DAYS="${KEEP_DAYS:-14}"
SERVICE="${DB_SERVICE:-postgres}"

compose() {
    if [ -n "${ENV_FILE:-}" ]; then
        docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
    else
        docker compose -f "$COMPOSE_FILE" "$@"
    fi
}

mkdir -p "$BACKUP_DIR"
stamp="$(date -u +%Y%m%d_%H%M%S)"
file="$BACKUP_DIR/ava_${stamp}.dump"
log="$BACKUP_DIR/backup.log"

fail() {
    echo "$(date -u +%FT%TZ) FAILED $1" >> "$log"
    echo "backup FAILED: $1" >&2
    rm -f "$file"
    exit 1
}

# The credentials come from the container's own environment: nothing secret is read, printed or stored here.
compose exec -T "$SERVICE" sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc --no-owner' > "$file" || fail "pg_dump exited with an error"

[ -s "$file" ] || fail "the dump is empty"
size="$(wc -c < "$file" | tr -d ' ')"

# The table of contents must be readable and list the core tables: a truncated or corrupt dump fails here.
toc="$(compose exec -T "$SERVICE" pg_restore --list < "$file")" || fail "pg_restore cannot read the dump"
echo "$toc" | grep -q "TABLE DATA public conversations" || fail "the dump has no conversations table data"
echo "$toc" | grep -q "TABLE DATA public migrations" || fail "the dump has no migrations table data"
tables="$(echo "$toc" | grep -c "TABLE DATA public")"

sha256sum "$file" > "$file.sha256" 2>/dev/null || shasum -a 256 "$file" > "$file.sha256"
echo "$(date -u +%FT%TZ) OK $(basename "$file") bytes=$size tables_with_data=$tables" >> "$log"
echo "$(basename "$file")" > "$BACKUP_DIR/LATEST.txt"

# Retention: only this tool's own files, older than KEEP_DAYS.
find "$BACKUP_DIR" -maxdepth 1 -name 'ava_*.dump*' -mtime "+$KEEP_DAYS" -delete

echo "backup OK: $file ($size bytes, $tables tables with data)"
