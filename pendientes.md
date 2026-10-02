# Pendientes

Actualiza este archivo al terminar cada tarea. Reglas en `reglas.md`.

## Pendientes

- [ ] **Horizon:** definir quién accede a `/horizon` fuera de local (gate `viewHorizon` en `app/Providers/HorizonServiceProvider.php`; hoy no entra nadie). Opciones: solo superusuarios o lista de emails por variable.
- [ ] **Producción — proxy:** decidir si se mantiene el servicio `proxy` (nginx) de `docker-compose.prod.yml` o se usa un DigitalOcean Load Balancer / Traefik.
- [ ] **Producción — servicios gestionados:** decidir si Postgres y Redis serán gestionados (DigitalOcean); si sí, quitar esos servicios del compose y apuntar `DB_HOST` / `REDIS_HOST`.
- [ ] **Scheduler:** hoy no hay tareas programadas. Al añadir la primera, usar `->onOneServer()` si el scheduler llegara a tener más de una réplica.
- [ ] **No-root completo en `app`:** hoy el proceso maestro (nginx/php-fpm) corre como root. Requiere nginx en puerto alto y rehacer el logging.
- [ ] **Pint:** `vendor/bin/pint --test` falla en 8 archivos por estilo (orden de imports, espacios en operadores, `fully_qualified_strict_types`...). Decidir si se aplica `pint` a todo el proyecto.
- [ ] **Versión de PHP:** `composer.json` dice `^8.3` pero el lock exige 8.4.1+. Subir a `^8.4` o regenerar el lock.
- [ ] **`/register`:** crea usuarios sin Workspace (no pueden entrar) y acepta `role=admin` del cliente. Cerrarlo o acotarlo (Workspace por invitación, rol fijo).
- [ ] **Edición de usuarios:** la pantalla de `/users` no avisa antes de guardar que una cuenta compartida no se puede renombrar.
- [ ] **Seeders de ejemplo:** `RolesAndPermissionsSeeder` crea usuarios con contraseñas débiles (`daniel@gmail.com`...); separar lo de desarrollo de lo de producción.
- [ ] **Valores por defecto de desarrollo en `docker-compose.yml`** (`DB_PASSWORD=secret`, `minioadmin`, `REVERB_APP_*`): decidir si se exigen desde `.env`.
- [ ] **Imagen de MinIO:** `bitnamilegacy/minio` está congelada; valorar alternativa si se quiere consola web o parches.
- [ ] **Traducciones:** no hay `lang/es`; los mensajes de autenticación salen en inglés.
- [ ] **Recordar sesión:** la cookie `remember` no recuerda el Workspace, así que no evita pasar por `/pre-login`.
- [ ] TODO: añadir aquí las tareas de producto.

## En curso

- [ ] TODO: nada en curso.

## Hecho

- [x] **Acceso por Workspace:** pre-login, login con diseño aprobado, middleware de contexto y rol por Workspace, superusuarios, tests.
- [x] **Protección de cuentas compartidas:** un admin de Workspace no puede cambiar nombre/email de cuentas con acceso a otros Workspaces ni de superusuarios; throttle del login independiente del Workspace.
- [x] **Dockerización:** imagen multi-stage única (app, worker, scheduler, reverb), compose de desarrollo y de producción, Postgres, Redis, MinIO, Vite, healthchecks, Makefile, `INFRASTRUCTURE.md`.
- [x] **Auditoría de infraestructura:** `league/commonmark` actualizado (avisos de seguridad), healthchecks corregidos, mantenimiento compartido entre réplicas, proxy con balanceo y websockets, workers sin root en producción, 4 tests arreglados (suite completa verde).
- [x] **Migración a PostgreSQL:** sin MySQL/MariaDB en la configuración, `DB_CONNECTION=pgsql` por defecto, migraciones verificadas en PostgreSQL 16.
- [x] **Administrador inicial:** `AdminUserSeeder` idempotente (`ADMIN_EMAIL` / `ADMIN_PASSWORD`), rol `admin` con todos los permisos, superusuario, protegido en producción.

## Ideas

- [ ] Chat en tiempo real sobre Reverb (infraestructura lista, falta el frontend con Echo).
- [ ] Panel de administración de Organizations y Workspaces.
- [ ] Selector de Workspace para superusuarios sin cerrar sesión.
- [ ] Spatie `teams` para permisos por Workspace.
- [ ] CI (tests + Pint + `composer audit`) en cada push.
- [ ] TODO: más ideas.
