#!/bin/sh
# LOCAL LOOP: Meta (driver) -> Ava -> n8n (double) -> Ava tools -> Graph API (double), over real HTTP, inside the development `app` container.
#
#   sh docker/ops/local-loop/run.sh            # reset, run every scenario, print the evidence, drop the database
#
# What it proves: Ava's own code paths (signature, routing, queue, retries, tools, persistence, traces) with real HTTP and a real queue worker.
# What it does NOT prove: that Meta, n8n or the company's workflow behave the same. The doubles in this folder are NOT those services.
# Safety: everything runs against the disposable database `ccg_ava_e2e` (created and dropped here, never `ccg_ava`); every PHP file refuses to run
# if DB_DATABASE does not end with `_e2e`; contacts are fake numbers; nothing leaves the container; it is for development stacks only.
set -eu

DIR="$(cd "$(dirname "$0")" && pwd)"
COMPOSE="${COMPOSE:-docker compose}"
DB=ccg_ava_e2e
BASE="-e DB_DATABASE=$DB -e APP_ENV=local -e APP_DEBUG=false -e APP_URL=http://127.0.0.1:8099 -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e BROADCAST_CONNECTION=null -e SESSION_DRIVER=array"
LIVE="$BASE -e INTEGRATIONS_ALLOW_PRIVATE_HOSTS=true -e META_APP_SECRET=local-meta-secret -e META_WEBHOOK_VERIFY_TOKEN=local-verify -e N8N_EXECUTION_URL=http://127.0.0.1:9101/webhook -e N8N_EXECUTION_SECRET=local-n8n-secret -e N8N_EXECUTION_TIMEOUT=4 -e WHATSAPP_GRAPH_URL=http://127.0.0.1:9102 -e LOG_CHANNEL=single -e ALERT_LOG_PATH=/tmp/e2e/alerts.log -e PHP_CLI_SERVER_WORKERS=8"

$COMPOSE exec -T app sh -c '[ "$APP_ENV" = local ]' || { echo "refusing: the app container is not APP_ENV=local" >&2; exit 1; }

stop() {
    $COMPOSE exec -T app sh -c 'pkill -9 -f "php -S" ; pkill -9 -f worker.php ; true' >/dev/null 2>&1 || true
    sleep 2
    $COMPOSE exec -T postgres psql -U ccg -d postgres -qtA -c "select pg_terminate_backend(pid) from pg_stat_activity where datname='$DB'" -c "DROP DATABASE IF EXISTS $DB" >/dev/null 2>&1 || true
}
trap stop EXIT
stop

$COMPOSE exec -T app mkdir -p /tmp/e2e
for f in router n8n_double graph_double setup worker driver; do $COMPOSE exec -T app sh -c "cat > /tmp/e2e/$f.php" < "$DIR/$f.php"; done
$COMPOSE exec -T app sh -c 'rm -f /tmp/e2e/*.log /tmp/e2e/graph_seq /tmp/e2e/healthy'
$COMPOSE exec -T postgres psql -U ccg -d postgres -qtA -c "CREATE DATABASE $DB"
$COMPOSE exec -T $BASE app php artisan migrate --force >/dev/null
$COMPOSE exec -T $BASE app php artisan db:seed --class=RolesAndPermissionsSeeder --force >/dev/null
$COMPOSE exec -T $BASE app php artisan db:seed --class=ModuleCatalogSeeder --force >/dev/null
$COMPOSE exec -T $BASE app php /tmp/e2e/setup.php

$COMPOSE exec -d $LIVE app php -S 127.0.0.1:8099 /tmp/e2e/router.php
$COMPOSE exec -d $LIVE app php -S 127.0.0.1:9101 /tmp/e2e/n8n_double.php
$COMPOSE exec -d $LIVE app php -S 127.0.0.1:9102 /tmp/e2e/graph_double.php
$COMPOSE exec -d $LIVE app php /tmp/e2e/worker.php
sleep 5

$COMPOSE exec -T $BASE app php /tmp/e2e/driver.php all
echo
# Worker crash in the middle of an execution (SIGKILL), then recovery: nothing is repeated before the threshold, and after it ONE reply goes out.
$COMPOSE exec -T $BASE app php /tmp/e2e/driver.php crash1
$COMPOSE exec -T app sh -c 'pkill -9 -f worker.php ; true' >/dev/null 2>&1 || true
$COMPOSE exec -T $BASE app php /tmp/e2e/driver.php crash_early
$COMPOSE exec -d $LIVE app php /tmp/e2e/worker.php
sleep 3
$COMPOSE exec -T $BASE app php /tmp/e2e/driver.php crash_final
echo
$COMPOSE exec -T $BASE app php /tmp/e2e/driver.php summary
