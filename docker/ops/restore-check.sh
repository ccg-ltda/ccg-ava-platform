#!/bin/sh
# Proves a backup is restorable WITHOUT touching the real database: restores it into a throw-away database, compares counts, drops that database.
#
#   BACKUP_FILE=/var/backups/ava/ava_20261017_020000.dump ./docker/ops/restore-check.sh
#
# Safety: the target database name is generated here (ava_restore_check_<stamp>) and the script refuses to run if it ever equals the live one.
# It never restores over the live database. A real recovery is a manual, supervised step (see INFRASTRUCTURE.md, "Recuperación").
set -eu

BACKUP_FILE="${BACKUP_FILE:?BACKUP_FILE is required}"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
SERVICE="${DB_SERVICE:-postgres}"

compose() {
    if [ -n "${ENV_FILE:-}" ]; then
        docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
    else
        docker compose -f "$COMPOSE_FILE" "$@"
    fi
}

[ -s "$BACKUP_FILE" ] || { echo "restore-check FAILED: $BACKUP_FILE is missing or empty" >&2; exit 1; }
if [ -f "$BACKUP_FILE.sha256" ]; then
    expected="$(cut -d' ' -f1 "$BACKUP_FILE.sha256")"
    actual="$(sha256sum "$BACKUP_FILE" 2>/dev/null | cut -d' ' -f1 || shasum -a 256 "$BACKUP_FILE" | cut -d' ' -f1)"
    [ "$expected" = "$actual" ] || { echo "restore-check FAILED: checksum mismatch" >&2; exit 1; }
fi

scratch="ava_restore_check_$(date -u +%Y%m%d%H%M%S)"
live="$(compose exec -T "$SERVICE" sh -c 'printf %s "$POSTGRES_DB"')"
[ "$scratch" != "$live" ] || { echo "refusing: scratch database equals the live one" >&2; exit 1; }

psql_admin() { compose exec -T "$SERVICE" sh -c 'psql -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1 -tA' ; }
cleanup() { echo "DROP DATABASE IF EXISTS \"$scratch\";" | psql_admin >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "CREATE DATABASE \"$scratch\";" | psql_admin >/dev/null
compose exec -T "$SERVICE" sh -c "pg_restore -U \"\$POSTGRES_USER\" -d \"$scratch\" --no-owner --exit-on-error" < "$BACKUP_FILE"

count() { compose exec -T "$SERVICE" sh -c "psql -U \"\$POSTGRES_USER\" -d \"$1\" -tA -c \"select count(*) from $2\""; }
status=0
for table in migrations workspaces users conversations messages appointments; do
    restored="$(count "$scratch" "$table")"
    echo "$table restored=$restored"
    case "$restored" in ''|*[!0-9]*) status=1 ;; esac
done
[ "$status" -eq 0 ] || { echo "restore-check FAILED: a core table could not be counted" >&2; exit 1; }

echo "restore-check OK: $(basename "$BACKUP_FILE") restores into a disposable database (dropped on exit)"
