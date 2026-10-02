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

## Variables importantes

Todo sale de `.env` (ver `.env.example`). El cableado de arquitectura (pgsql, drivers Redis, disco `s3`, hosts de servicio) está fijo en el compose.

| Variable | Uso |
|---|---|
| `APP_KEY` | Obligatoria. Compartida por todos los contenedores |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Postgres |
| `REDIS_PASSWORD` | Obligatoria en producción |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_DEFAULT_REGION`, `AWS_URL`, `AWS_USE_PATH_STYLE_ENDPOINT` | Almacenamiento S3 (MinIO o Spaces) |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Websockets |
| `TRUSTED_PROXIES` | `*` o lista de IPs cuando hay balanceador delante |
| `APP_PORT`, `VITE_PORT`, `DB_FORWARD_PORT`, `MINIO_PORT` | Puertos del host (desarrollo) |

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
4. TLS: pon un DigitalOcean Load Balancer (o Caddy/Traefik) delante del servicio `proxy` y deja `TRUSTED_PROXIES=*`. El `proxy` conserva `X-Forwarded-Proto`.
5. Tras cada despliegue: `docker compose ... exec worker php artisan horizon:terminate` para que Horizon cargue el código nuevo.

### Horizon

`/horizon` solo está abierto en `APP_ENV=local`. Fuera de local, el gate `viewHorizon` (`app/Providers/HorizonServiceProvider.php`) tiene la lista de emails vacía: **nadie entra** hasta que definas quién.
