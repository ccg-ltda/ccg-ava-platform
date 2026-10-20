# Infraestructura (Docker)

Una sola imagen PHP (`Dockerfile`, multi-stage) para todos los roles; solo cambia el comando.

| Servicio | Comando | Notas |
|---|---|---|
| `app` | nginx + php-fpm (supervisord) | Sin estado, escalable con réplicas |
| `worker` | `php artisan horizon` | Colas en Redis |
| `scheduler` | `php artisan schedule:work` | **Siempre 1 réplica** |
| `reverb` | `php artisan reverb:start` | Websockets, escalado vía Redis |
| `postgres` / `redis` | | Con healthcheck; los demás esperan por salud |
| `node` | `npm run dev` | Solo desarrollo (Vite) |
| `minio` | | Solo desarrollo (S3 local) |
| `proxy` | nginx | Solo producción: balancea `app` y reenvía websockets |

## Desarrollo

```bash
cp .env.example .env        # PowerShell: Copy-Item .env.example .env
# APP_KEY (una vez):  PowerShell:  "APP_KEY=base64:" + [Convert]::ToBase64String((1..32 | % { Get-Random -Max 256 }) -as [byte[]])
#                     bash:        echo "APP_KEY=base64:$(openssl rand -base64 32)"   (pégalo en .env)
docker compose up -d --build
docker compose exec app php artisan db:seed     # opcional: usuarios y Workspaces de prueba
```

Las migraciones corren solas al arrancar `app` (solo desarrollo, `RUN_MIGRATIONS=true`).

| URL | Qué |
|---|---|
| http://localhost:8000 | App (redirige a `/pre-login`) |
| http://localhost:5173 | Vite (HMR) |
| ws://localhost:8080 | Reverb |
| http://localhost:9000 | API S3 de MinIO (`minioadmin` / `minioadmin`, bucket `ccg-ava`). No hay consola web |
| localhost:5433 | Postgres (`ccg` / `secret`, base `ccg_ava`) |

## Comandos

`make` no viene con Windows; los equivalentes funcionan en cualquier shell.

| Makefile | docker compose |
|---|---|
| `make up` | `docker compose up -d --build` |
| `make down` | `docker compose down` |
| `make logs s=app` | `docker compose logs -f --tail=100 app` |
| `make ps` | `docker compose ps` |
| `make shell` | `docker compose exec app sh` |
| `make migrate` | `docker compose exec app php artisan migrate --force` |
| `make fresh` | `docker compose exec app php artisan migrate:fresh --seed --force` (**borra la base de desarrollo**) |
| `make test` | ver abajo |
| `make prod-up` / `prod-migrate` | `docker compose -f docker-compose.prod.yml up -d --build` / `... exec app php artisan migrate --force` |

**Tests:** los contenedores llevan el entorno de desarrollo (pgsql/redis) y las variables reales ganan a `phpunit.xml`; sin forzarlas, `RefreshDatabase` vaciaría la base de desarrollo. Usa siempre `make test` o:

```bash
docker compose exec -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array \
  -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e BROADCAST_CONNECTION=null app php artisan test
```

**Extensión GD (solo imagen de desarrollo):** 8 pruebas (avatar de asistente, logo de Workspace y avatar del widget) generan imágenes falsas con `UploadedFile::fake()->image()`, que necesita `ext-gd`. La etapa `development` del `Dockerfile` la instala (PNG, JPEG, WebP y FreeType); la imagen de producción no, porque la aplicación no procesa imágenes. Una imagen de desarrollo construida antes de ese cambio falla esas 8 pruebas con `GD extension is not installed`: reconstruye con `docker compose build app && docker compose up -d` (recrea los contenedores de desarrollo; los volúmenes y la base se conservan). Comprobación: `docker compose exec app php -m | grep -i '^gd$'`. El contenedor `app` de este entorno ya está reconstruido con GD (2026-10-17); `worker`, `scheduler` y `reverb` siguen sobre la imagen anterior hasta que se recreen (no la necesitan).

Red de seguridad: `tests/TestCase.php` aborta cualquier test si la conexión no es SQLite en memoria o una base cuyo nombre acaba en `_test`/`_testing` (`tests/Support/TestDatabaseGuard.php`). Un `php artisan test` a pelo dentro del contenedor falla con "Refusing to run tests..." antes de tocar la base de desarrollo.

**Validaciones en navegador (Playwright) contra la base de desarrollo:** no uses ni modifiques cuentas, membresías ni roles reales. Crea datos aislados y bórralos siempre al terminar, también si la validación falla:

```bash
make e2e-setup      # php artisan e2e:setup --users=3 --json  -> Workspace E2E_WS y usuarios *@e2e.ccg.test (contraseñas aleatorias en la salida)
make e2e-cleanup    # php artisan e2e:cleanup                 -> borra SOLO lo etiquetado; `--dry-run` para ver qué borraría
```

Convención: usuarios `*@e2e.ccg.test`, Workspace `E2E_*` (organización `E2E Org`) y roles `e2e_*`. Los comandos se niegan a ejecutarse en producción, muestran la base de datos usada y no tocan datos reales (un rol `e2e_*` asignado a un usuario real no se elimina).

## Variables importantes

Todo sale de `.env` (ver `.env.example`). El cableado de arquitectura (pgsql, drivers Redis, disco `s3`, hosts de servicio) está fijo en el compose.

| Variable | Uso |
|---|---|
| `APP_KEY` | Obligatoria. Compartida por todos los contenedores |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Postgres |
| `REDIS_PASSWORD` | Obligatoria en producción |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_DEFAULT_REGION`, `AWS_URL`, `AWS_USE_PATH_STYLE_ENDPOINT` | Almacenamiento S3 (MinIO o Spaces) |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Websockets |
| `TRUSTED_PROXIES` | Lista de IPs o rangos CIDR de los proxies confiables. Por defecto en producción: los rangos privados `10.0.0.0/8,172.16.0.0/12,192.168.0.0/16` (red de Docker y VPC). No uses `*`: confía en `X-Forwarded-For` de cualquiera y permite falsificar la IP de los límites de tasa (comprobado) |
| `APP_PORT`, `VITE_PORT`, `DB_FORWARD_PORT`, `MINIO_PORT` | Puertos del host (desarrollo) |
| `META_APP_SECRET`, `META_WEBHOOK_VERIFY_TOKEN`, `N8N_EXECUTION_URL`, `N8N_EXECUTION_SECRET`, `N8N_EXECUTION_TIMEOUT` | Integración con Meta y con el n8n de la plataforma (vacío = no configurado) |
| `BOOKER_STAGING_URL`, `BOOKER_PRODUCTION_URL`, `BOOKER_SCOPE`, `BOOKER_MERCHANT_VERIFIED` | Booker: hosts por entorno, scope del token y el interruptor que habilita horarios y reserva en producción (`false` hasta verificarlos en Staging). Las credenciales de Booker son de cada Workspace (cifradas en la base), no variables de entorno |
| `ALERT_LOG_CHANNELS`, `LOG_SLACK_WEBHOOK_URL` | Dónde se escribe, además de `storage/logs/alerts.log`, la alerta de un fallo definitivo de la IA (el compose de producción usa `stderr`). Sin un canal externo la alerta no sale del servidor |
| `CHATBOT_FAILURE_NOTICE` | Único texto que recibe el contacto cuando la IA falla y la conversación pasa a una persona. Debe estar aprobado por el negocio; vacío = no se envía nada |

El bucket de MinIO es **privado** (`MINIO_DEFAULT_BUCKETS` sin `:public`): los archivos (por ejemplo el logo de cada Workspace) solo salen por rutas autenticadas de Laravel, nunca por una URL directa del objeto. Si tu volumen de MinIO es anterior a este cambio y el bucket sigue público, ciérralo sin perder datos: `docker compose exec minio sh -c 'mc alias set local http://localhost:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" && mc anonymous set none local/ccg-ava'`.

## Producción / DigitalOcean

`docker-compose.prod.yml` es una base: sin bind mounts, sin node ni minio, sin puertos de postgres/redis en el host, `APP_DEBUG=false`, caches de Laravel en el arranque, **sin migraciones automáticas** y con `worker`, `scheduler` y `reverb` corriendo como `www-data`.

1. Crea un Droplet con Docker, un bucket de Spaces y (opcional) Postgres y Redis gestionados. Con gestionados, borra los servicios `postgres` y `redis` del compose y apunta `DB_HOST` / `REDIS_HOST` a ellos.
2. Crea un archivo de variables (no lo subas al repo) con: `APP_KEY`, `APP_URL`, `DB_PASSWORD`, `REDIS_PASSWORD`, `REVERB_APP_*`, y para Spaces:
   ```
   AWS_ACCESS_KEY_ID=...  AWS_SECRET_ACCESS_KEY=...  AWS_BUCKET=mi-bucket
   AWS_ENDPOINT=https://nyc3.digitaloceanspaces.com  AWS_DEFAULT_REGION=nyc3
   AWS_URL=https://mi-bucket.nyc3.digitaloceanspaces.com   # o la URL del CDN
   AWS_USE_PATH_STYLE_ENDPOINT=false
   ```
   Pasar de MinIO a Spaces solo cambia estas variables.
3. Despliega y migra (explícito):
   ```bash
   docker compose --env-file prod.env -f docker-compose.prod.yml up -d --build --scale app=3 --scale worker=2
   docker compose --env-file prod.env -f docker-compose.prod.yml exec app php artisan migrate --force
   ```
   Repite `--scale` en cada `up`; si no, Compose vuelve a 1 réplica. `scheduler` debe quedarse en 1.
4. TLS: pon un DigitalOcean Load Balancer (o Caddy/Traefik) delante del servicio `proxy` y deja `TRUSTED_PROXIES` con su valor por defecto (rangos privados) si el balanceador te llega por la red privada, o con la lista exacta de sus IPs si no. Nunca `*`. El `proxy` conserva `X-Forwarded-Proto`.
5. Tras cada despliegue: `docker compose ... exec worker php artisan horizon:terminate` para que Horizon cargue el código nuevo.
6. Integraciones reales: el compose reenvía `META_APP_SECRET`, `META_WEBHOOK_VERIFY_TOKEN`, `N8N_EXECUTION_URL`, `N8N_EXECUTION_SECRET` (y `N8N_EXECUTION_TIMEOUT`, `WHATSAPP_GRAPH_URL`, `WHATSAPP_GRAPH_VERSION`, `WORKSPACE_ADMIN_CODE`, `ADMIN_*`) desde tu archivo de variables; la imagen no trae `.env`, así que una variable que no esté ahí no existe en producción. El scheduler escribe un latido cada minuto: `integrations:diagnose` lo lee (y consulta Horizon y la cola), y en producción lo bloquea si falta. Siembra roles y catálogo (`db:seed --class=RolesAndPermissionsSeeder`, `--class=ModuleCatalogSeeder`) y comprueba con `exec app php artisan integrations:diagnose`. Ejecuciones fallidas: `exec app php artisan executions:list --failed`. Detalle y pasos en `RECOMENDACIONES.md` (§3.2 y §4).

### Horizon

`/horizon` está abierto en `APP_ENV=local`. Fuera de local, el gate `viewHorizon` (`app/Providers/HorizonServiceProvider.php`) deja entrar solo a las cuentas **activas** cuyo correo esté en `HORIZON_ALLOWED_EMAILS` (separados por comas, sin distinguir mayúsculas). Con la variable vacía —el valor por defecto— **nadie entra**. Quién figura en la lista es una decisión del responsable (ver `pendientes.md`).

## Operación del piloto (copia, supervisión, despliegue y marcha atrás)

Procedimientos verificados en el entorno de desarrollo; contra producción NO se han ejecutado (no hay entorno de producción). Todo lo marcado «responsable» necesita una persona asignada antes del piloto.

### Copia de seguridad y comprobación de restauración

```bash
# Copia (solo lee la base; guarda .dump + .sha256 + backup.log; falla con código ≠ 0 si el volcado está vacío o ilegible)
BACKUP_DIR=/var/backups/ava COMPOSE_FILE=docker-compose.prod.yml ENV_FILE=prod.env sh docker/ops/backup.sh
# Prueba de restauración en una base DESECHABLE (nunca sobre la real; se elimina sola)
BACKUP_FILE=/var/backups/ava/ava_<fecha>.dump COMPOSE_FILE=docker-compose.prod.yml ENV_FILE=prod.env sh docker/ops/restore-check.sh
```

- Programar la copia con `cron` del servidor (p. ej. diaria a las 02:00 UTC) y enviar el código de salida y `backup.log` a la alerta del responsable. Guardar los volcados FUERA del repositorio y fuera del servidor de la aplicación (otro volumen o Space con acceso restringido); `KEEP_DAYS` (14 por defecto) borra solo los archivos `ava_*.dump*` de la propia herramienta.
- Con PostgreSQL gestionado de DigitalOcean, activar además sus copias automáticas con recuperación a un punto en el tiempo; este script sigue sirviendo como copia propia y portátil.
- NO cubre los archivos (Spaces/MinIO): activar el versionado del bucket. NO cubre Redis (colas y caché; se reconstruyen, las ejecuciones fallidas se recuperan con `executions:recover`).
- **Evidencia (2026-10-17, desarrollo, `ccg_ava`):** `backup.sh` generó un volcado de 228 909 bytes con 56 tablas con datos; `restore-check.sh` lo restauró en una base desechable con los mismos conteos (`migrations` 27, `workspaces` 3, `users` 5, `conversations` 11, `messages` 76, `appointments` 1) y la eliminó; un volcado truncado se rechazó con código 1 y un fallo de `pg_dump` dejó `FAILED` en `backup.log` sin dejar archivo. La restauración sobre una base real NO se ha probado.
- **Recuperación real (manual, supervisada):** poner la aplicación en mantenimiento (`php artisan down`), crear una base NUEVA, `pg_restore --no-owner -d <nueva> <archivo>`, apuntar `DB_DATABASE` a ella, `php artisan up`, `integrations:diagnose`. No sobrescribir la base dañada hasta confirmar la nueva.

### Supervisión mínima (qué mirar y cuándo)

| Qué | Cómo | Frecuencia / alerta |
|---|---|---|
| Salud general y bloqueos | `docker compose ... exec app php artisan integrations:diagnose` (código ≠ 0 en producción si hay bloqueos: scheduler sin latido, un comando programado que FALLA o NO SE EJECUTA, cola/Horizon, secretos de Meta o n8n) | A diario y tras cada despliegue; automatizable con `cron` y aviso por correo |
| Comandos programados | `diagnose` informa por comando (`executions:recover`, `appointments:notify`, `agent:tools:reconcile`) el último éxito, las fallas de 24 h y «FALLA ahora» / «NO SE EJECUTA». La causa de una falla queda en `storage/logs/scheduler-failures.log` (dentro del contenedor del scheduler) y la última salida en `storage/logs/scheduler-last-<comando>.log` | A diario; mirar el archivo solo en el servidor (no se muestra en la interfaz) |
| Ejecuciones de IA fallidas | `php artisan executions:list --failed`; `php artisan executions:recover` | A diario durante el piloto |
| Resultados inciertos de herramientas | `php artisan agent:tools:reconcile` (el scheduler lo ejecuta cada 5 min); en cada conversación, el panel de operaciones muestra la traza | A diario |
| Contenedores | `docker compose ... ps` (todos `healthy`) y `logs --tail` de `app`, `worker`, `scheduler`, `reverb` | A diario; alerta externa de reinicios |
| Horizon | Cerrado salvo en local y para los correos de `HORIZON_ALLOWED_EMAILS` (vacío por defecto = nadie; el responsable decide la lista). Sin lista, `php artisan horizon:status` y `queue:failed` | A diario |
| Webhooks de Meta | Recepción: `integrations:diagnose` y registros `whatsapp_*`; sin latido de mensajes durante un horario normal = revisar suscripción y firma | Durante el piloto, cada mañana |

### Alertas operativas y fallo de la IA (D-FALLO)

Cuando la IA falla de forma definitiva (n8n inaccesible tras 3 intentos, respuesta que no cumple el contrato, 401/403 de n8n, error interno repetido, mensaje demasiado viejo porque la cola estuvo detenida), Ava pasa la conversación a «Pendiente de agente» (solo si la IA todavía la tenía), escribe una línea `ai_failure_handed_to_human` en el log `alerts` (identificadores, código fijo, tipo de fallo y causa; nunca texto ni número del contacto) y, si `CHATBOT_FAILURE_NOTICE` está definida, envía ese único texto al contacto. Qué hay y qué no:

- **Existe y está probado:** conversación pendiente, auditoría del Workspace, línea de alerta en `storage/logs/alerts.log` del contenedor que la generó (el `worker`) y, con el compose de producción, en `docker compose logs worker` (`ALERT_LOG_CHANNELS=stderr`). `integrations:diagnose` cuenta las conversaciones pasadas a una persona por un fallo en las últimas 24 h y `executions:recover` completa un traspaso que una caída dejó a medias.
- **Requiere configuración externa (no hecha):** un destino que avise a una persona (por ejemplo `ALERT_LOG_CHANNELS=stderr,slack` con `LOG_SLACK_WEBHOOK_URL`, o un agente de logs que vigile `ai_failure_handed_to_human`). Hasta entonces nadie recibe un aviso fuera del servidor: durante el piloto el responsable debe mirar la bandeja «Pendientes» y `diagnose` a diario.
- Revisión: `docker compose ... logs worker | grep ai_failure_handed_to_human`, `executions:list --failed`, bandeja de Conversaciones (filtro pendientes).

### Despliegue, migración y marcha atrás

1. **Antes:** `backup.sh` + `restore-check.sh` con éxito; leer las migraciones nuevas y `migrate --pretend` en una copia restaurada de la base; confirmar variables obligatorias; tener la imagen anterior etiquetada (`APP_IMAGE=ccg-ava-platform:<etiqueta>`).
2. **Despliegue:** `docker compose --env-file prod.env -f docker-compose.prod.yml up -d --build --scale app=N --scale worker=M`, luego `exec app php artisan migrate --force` (explícito, nunca automático) y `exec worker php artisan horizon:terminate`.
3. **Después:** `integrations:diagnose` sin bloqueos; una conversación de prueba interna de extremo a extremo.
4. **Marcha atrás del código:** volver a levantar con la etiqueta anterior de `APP_IMAGE`. Las migraciones son aditivas (columnas nulables, tablas y restricciones nuevas) y el código anterior las tolera; `migrate:rollback` solo se usa en una copia, nunca como primera opción. **Marcha atrás de datos:** restaurar la copia previa en una base nueva (procedimiento de arriba); se pierde lo ocurrido desde la copia, por eso se hace en una ventana acordada.
5. **Nunca** `migrate:fresh`, `db:wipe`, `migrate:refresh` ni `db:seed` completo en producción. Las semillas permitidas son las idempotentes de roles y catálogo de módulos.

### Acceso, aislamiento y secretos

- Administración de plataforma: solo `Desarrollo_DEV` y superusuarios; no entran a los datos de un cliente sin acceso temporal justificado y auditado (D-P1 pendiente). Un cliente solo ve su Workspace.
- Los secretos (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `META_*`, `N8N_*`, `AWS_*`, `REVERB_*`) viven en el archivo de variables del servidor o en el gestor de secretos del proveedor, nunca en Git ni en imágenes. Rotar `META_APP_SECRET` y `N8N_EXECUTION_SECRET` si se sospecha de exposición y tras terminar el piloto.
- Contraseña del primer administrador: cambiarla en el primer inicio de sesión; `ADMIN_*` se definen solo para ejecutar `AdminUserSeeder` y se eliminan después.

### Circuito local de pruebas (solo desarrollo)

`sh docker/ops/local-loop/run.sh` (en Git Bash de Windows: `MSYS_NO_PATHCONV=1 sh docker/ops/local-loop/run.sh`, o desde WSL/PowerShell con `sh`) ejecuta, en el contenedor `app` de desarrollo y contra la base desechable `ccg_ava_e2e` (la crea y la elimina; rechaza ejecutarse si el contenedor no es `APP_ENV=local`), el recorrido Meta (conductor firmado) → Ava → trabajador de cola → doble de n8n → herramientas → doble de la Graph API, con escenarios de verificación del webhook, reserva, rechazo, duplicado, traspaso, fallos de n8n, respuestas inválidas y caída del trabajador con recuperación. Los dobles NO son Meta ni n8n. No usar contra producción ni contra `ccg_ava`.
