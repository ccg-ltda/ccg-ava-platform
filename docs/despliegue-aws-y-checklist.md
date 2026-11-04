# Arquitectura de despliegue en AWS y checklist de producción

**Estado:** propuesta técnica verificada contra el código (2026-11-04). **No existe infraestructura AWS, cuenta ni recurso creado; no se creó ni modificó nada en AWS.** Todo lo que sigue son recomendaciones y requisitos; ninguno está probado en AWS.

## 1. Lo que el código ya soporta (sin acoplarse a AWS)

| Necesidad | Hecho en el código | Evidencia |
|---|---|---|
| Archivos en almacenamiento compatible con S3 | Disco `s3` por variables `AWS_*`; MinIO en desarrollo y Spaces/S3 solo cambian variables. Los archivos nunca van al disco del contenedor | `config/filesystems.php`, `INFRASTRUCTURE.md` |
| Cola y workers | Redis + Horizon (`worker`); `QUEUE_CONNECTION=redis`. SQS existe en `config/queue.php` pero **no se usa** (Horizon necesita Redis) | `docker-compose.prod.yml` |
| Scheduler | Servicio `scheduler` de **una sola réplica**, con `onOneServer()` y `withoutOverlapping()` y monitor propio | `routes/console.php` |
| Tiempo real | Reverb (WebSocket), servicio `reverb` | `docker-compose.prod.yml` |
| Health checks | `/up` (Laravel) en app y proxy; `horizon:status` en worker; `integrations:diagnose` | `bootstrap/app.php`, compose |
| Detrás de un balanceador | `TRUSTED_PROXIES` (lista exacta, nunca `*`), HSTS solo por HTTPS, `X-Forwarded-Proto` | `bootstrap/app.php`, `config/security.php` |
| Rate limiting y protección | `api.protect` por Workspace, límites por IP y clave, tope de cuerpo | `config/api_protection.php` |
| Sin migraciones automáticas | `migrate --force` es un paso explícito | `INFRASTRUCTURE.md` |
| Secretos fuera del repo | Variables de entorno; `.env` no se versiona | `reglas.md` §11 |

## 2. Arquitectura recomendada

```
Route 53 ─► CloudFront (opcional, solo para /build y /widget) ─► ALB (HTTPS, ACM)
                                                                  │  /up health check
                                  ┌───────────────────────────────┴───────────────────┐
                              ECS Fargate "app" (n tareas)                ECS "reverb" (WebSocket)
                              ECS "worker" (Horizon, n tareas)            ECS "scheduler" (EXACTAMENTE 1 tarea)
                                  │
        ┌─────────────────────────┼───────────────────────────┬──────────────────────┐
   RDS PostgreSQL 16        ElastiCache Redis 7            S3 (privado)        Secrets Manager / SSM
   (Multi-AZ, backups)      (colas, caché, sesiones)       (archivos, backups)  (variables sensibles)
   CloudWatch Logs + alarmas · SES (correo) · NAT/VPC endpoints
```

- **Cómputo:** la imagen del `Dockerfile` ya existe; Fargate evita administrar servidores. Alternativa más simple para el piloto: una instancia EC2 con `docker compose` (mismo compose de producción) y migrar a ECS después. **Decisión pendiente de CCG.**
- **Base de datos:** RDS PostgreSQL 16 (el esquema usa `btree_gist` y restricciones de exclusión: comprobar que la extensión esté permitida en la versión elegida). Multi-AZ y copias automáticas con retención ≥ 7 días y snapshot antes de cada migración.
- **Redis:** ElastiCache Redis 7, en subred privada, con contraseña (`REDIS_PASSWORD`) y TLS si el cliente lo permite (`phpredis`: verificar el esquema `tls://`).
- **Archivos:** bucket S3 **privado** (Block Public Access activo, sin CDN público), cifrado SSE, versionado, política de ciclo de vida. Credenciales por **rol IAM de la tarea** en lugar de llaves largas (el SDK las toma solas si `AWS_ACCESS_KEY_ID` queda vacío; hay que comprobarlo con un envío real). El código ya sirve los archivos privados por rutas autenticadas, no por URL pública.
- **Colas:** mantener Redis + Horizon. SQS exigiría reemplazar Horizon (supervisión, métricas, reintentos): no se recomienda sin motivo.
- **Correo:** SES por SMTP (`MAIL_*`) con dominio verificado, SPF/DKIM/DMARC; sacar la cuenta del sandbox de SES. Hoy **nunca se ha enviado un correo real desde producción**.
- **Secretos:** Secrets Manager o SSM Parameter Store inyectados como variables de la tarea; nunca en la imagen. Rotación de `APP_KEY` con `APP_PREVIOUS_KEYS`.
- **Red:** VPC con subredes privadas para app, worker, RDS y Redis; ALB público; grupos de seguridad mínimos; salida por NAT. Las llamadas salientes de Ava ya pasan por `SafeHttpTarget` (bloquea IPs internas), no desactivarlo (`INTEGRATIONS_ALLOW_PRIVATE_HOSTS` debe ser `false`).
- **Logs y monitorización:** `LOG_CHANNEL=stderr` hacia CloudWatch; alarmas sobre `/up`, latencia 5xx del ALB, CPU/memoria, conexiones de RDS, memoria de Redis, cola de Horizon y los avisos de `integrations:diagnose`. `alerts.log` (D-FALLO) debe llegar a un canal que alguien lea.
- **Backups y recuperación:** RDS automatizado + `scripts` de copia ya existentes para volcados lógicos; prueba de restauración periódica sobre una base desechable (procedimiento en `INFRASTRUCTURE.md`); versionado de S3. Definir RPO/RTO: **[POR DECIDIR]**.

## 3. Webhooks, CORS y URLs

- `APP_URL` con el dominio HTTPS público. Las URLs de webhook que se entregan a terceros (`/api/webhooks/whatsapp`, `/api/webhooks/wompi`, `/api/webhooks/in/{clave}`) se derivan de él.
- Meta, Wompi y WooCommerce deben alcanzar el ALB: no restringir esas rutas por IP si no se publican sus rangos; la autenticación es la firma.
- CORS: `config/cors.php` (`CORS_ALLOWED_ORIGINS`); el widget necesita CORS abierto en `/api/widget/*` y la reserva pública en `/api/booking/*`; el resto no.
- WebSocket: el ALB soporta WebSocket; configurar el timeout de inactividad ≥ 60 s y el health check de `reverb`.
- `SESSION_SECURE_COOKIE=true` y `SESSION_DOMAIN` correctos.

## 4. Variables de entorno necesarias (sin valores)

`APP_KEY`, `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false`, `TRUSTED_PROXIES`, `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`, `REDIS_HOST/PASSWORD/PORT`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `FILESYSTEM_DISK=s3`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_USE_PATH_STYLE_ENDPOINT=false` solo si no hay rol IAM), `REVERB_APP_ID/KEY/SECRET/HOST/PORT/SCHEME`, `MAIL_*`, `META_APP_SECRET`, `META_WEBHOOK_VERIFY_TOKEN`, `N8N_*`, `WOMPI_*` (desde la interfaz), `ADMIN_EMAIL/PASSWORD/NAME` (solo en el primer arranque), `HORIZON_ALLOWED_EMAILS`, `ALERT_LOG_CHANNELS`, `LEGAL_*`, `BRAND_*`, `SECURITY_CSP_MODE`, `CORS_ALLOWED_ORIGINS`. La lista completa y comentada está en `.env.example`. **No modificar `.env` ni inventar valores de producción.**

## 5. Cambios de código que podrían hacer falta (no hechos)

1. Confirmar que el disco `s3` funciona con rol IAM sin llaves (hoy se documenta con llaves).
2. `VITE_APP_NAME` es de compilación: añadir un `ARG` al `Dockerfile` (ver P14 de `pendientes.md`).
3. Imagen con usuario no root completo para `app` (pendiente conocido).
4. Un endpoint de salud más profundo (`/up` solo prueba la app): opcional.

## 6. Checklist de preparación para producción

| # | Punto | Estado hoy |
|---|---|---|
| 1 | Cuenta AWS, VPC, subredes, ALB, ACM, dominio | No existe |
| 2 | RDS PostgreSQL 16 con `btree_gist`, backups y snapshot previo a migrar | No existe |
| 3 | ElastiCache Redis 7 | No existe |
| 4 | Bucket S3 privado + política IAM mínima | No existe; confirmar privacidad (P9) |
| 5 | Secretos cargados; `.env` fuera del repo | Pendiente |
| 6 | Imagen construida y subida a ECR | Pendiente |
| 7 | `migrate --force`, `db:seed --class=RolesAndPermissionsSeeder`, `--class=ModuleCatalogSeeder` | Pendiente en cada entorno |
| 8 | Primer administrador y rol `plataforma` (P4) | Pendiente |
| 9 | 1 scheduler, ≥ 1 worker Horizon, reverb | Pendiente |
| 10 | HTTPS, `TRUSTED_PROXIES`, IP real tras el balanceador (P15) | Pendiente |
| 11 | Webhooks de Meta/Wompi registrados contra el dominio (P1, P10) | Pendiente |
| 12 | Correo real (SES) con SPF/DKIM | Pendiente |
| 13 | Alarmas, logs y responsable de revisarlos (P7) | Pendiente |
| 14 | Restauración de copia probada en el entorno real (P6) | Pendiente |
| 15 | CSP en `enforce` tras revisar pantallas (P18) | Pendiente |
| 16 | Documentos legales revisados y `LEGAL_*` cargadas | Pendiente (ver `docs/legal/`) |
| 17 | Retención de actividad técnica y permisos asignados (§89, §90) | Decidir y asignar |
| 18 | Prueba de carga/concurrencia en un entorno aislado (V4) | Pendiente |
| 19 | Auditoría de seguridad externa (P12) | Pendiente |

## 7. Verificación del código (2026-11-04, segunda ronda; sin tocar AWS)

- **S3 sin depender de MinIO:** la lógica de la aplicación usa solo `Storage::` sobre el disco por defecto (`FILESYSTEM_DISK`); «MinIO» aparece únicamente en comentarios y en el compose de desarrollo. Cambiar a S3 es cambiar variables (`FILESYSTEM_DISK=s3`, `AWS_*`).
- **Rol IAM sin llaves:** el adaptador S3 de Laravel solo fija credenciales si `AWS_ACCESS_KEY_ID` y `AWS_SECRET_ACCESS_KEY` tienen valor; vacías, el SDK usa su cadena de credenciales (rol de la tarea). Confirmado en el código del framework; **no probado en AWS**.
- **Riesgo conocido:** el disco `s3` tiene `throw => false` (`config/filesystems.php`): un fallo de S3 devuelve `false` en vez de lanzar. Hay que revisar que cada escritura importante compruebe el resultado antes de producción (hoy lo hacen `Storage::delete` en productos y avatares).
- **PostgreSQL administrado:** el esquema usa `btree_gist` (restricciones de exclusión de citas) y se verificó en PostgreSQL 16 desechable; falta comprobar la extensión en el servicio gestionado elegido.
- **Redis, workers, scheduler, `/up`, `TRUSTED_PROXIES`, rate limiting:** ya cubiertos en las secciones 1 a 3; verificados en Docker local (workers de Horizon y scheduler `healthy` tras el reinicio de esta ronda), **no** en un entorno real.
