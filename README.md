# Ava Platform

Plataforma multi-Workspace para operar asistentes de IA (chatbots) y atender a sus contactos. Ava es la **única fuente del comportamiento** de cada chatbot (instrucciones, canales, apariencia); la IA y la conversación con WhatsApp viven en **n8n**, que lee la configuración de Ava y le reporta los mensajes. Cuando hace falta, una persona toma el control de la conversación desde Ava y responde por el mismo canal.

Este README es la guía de arranque. Las reglas de desarrollo y las decisiones permanentes están en [`reglas.md`](reglas.md), las tareas abiertas en [`pendientes.md`](pendientes.md) y la infraestructura en [`INFRASTRUCTURE.md`](INFRASTRUCTURE.md).

## Arquitectura

```
Organization → Workspace → membresía (workspace_user.role) → usuarios
                  └── chatbots → canales (Web, WhatsApp) → integraciones del Workspace
                                      └── conversaciones → mensajes
```

- **Aislamiento por Workspace:** el Workspace sale siempre de la sesión (nunca del cliente) y toda consulta de negocio parte de él. Los roles son un catálogo global (Spatie); `workspace_user.role` asigna uno por Workspace y la autorización sale de sus permisos.
- **Desarrollo_DEV** (`DESARROLLO_DEV`, `config/workspace.php`) es el Workspace administrativo interno: no se desactiva ni cambia de código, y solo un superusuario que trabaja dentro de él puede consultar otros Workspaces (solo lectura).
- **Ava ↔ n8n:** n8n lee `GET /api/agent/config` con el token del chatbot, reporta mensajes con `POST /api/agent/messages` y respeta el control de Ava con `POST /api/agent/conversations/authorize` y `/handoff`. Ava no ejecuta modelos.

## Tecnologías

PHP 8.4 (contenedor) · Laravel 13 · Inertia 3 · React 19 (JSX) · Vite 8 · Tailwind CSS 4 · PostgreSQL 16 · Redis 7 · Horizon · Reverb · MinIO/S3 · `spatie/laravel-permission`. Los tests usan SQLite en memoria.

Requisitos: Docker con Compose. Para ejecutar PHPUnit, Pint o `npm` en el anfitrión, además PHP 8.4+, Composer y Node 20+.

## Puesta en marcha con Docker

```bash
cp .env.example .env            # PowerShell: Copy-Item .env.example .env
# Genera APP_KEY una vez y pégala en .env (ver INFRASTRUCTURE.md)
docker compose up -d --build    # o: make up
docker compose exec app php artisan db:seed
```

- Las migraciones corren solas al arrancar `app` en desarrollo (`RUN_MIGRATIONS=true`), también sobre una base que ya tiene datos: solo se aplican las pendientes.
- `db:seed` es **re-ejecutable**: crea el catálogo de permisos y los roles `admin`, `supervisor`, `cliente` y `agente`, el administrador inicial y los Workspaces base. En una base existente `admin` recibe siempre todos los permisos, y los demás roles solo reciben sus permisos por defecto al crearse (no pisa lo que un superusuario haya cambiado).
- Tras actualizar el código, reinicia el worker (`docker compose restart worker`): Horizon es un proceso largo y conserva la configuración vieja.
- Si Vite (`node`) no ve cambios de archivos en Windows: `docker compose restart node`.
- `make fresh` borra la base de desarrollo; úsalo solo ahí.

| URL | Qué |
|---|---|
| http://localhost:8000 | App (redirige a `/pre-login`) |
| http://localhost:5173 | Vite |

### Variables de entorno

Todas están documentadas en `.env.example`; nunca escribas secretos en el repositorio. Las que debes definir tú: `APP_KEY`, `ADMIN_EMAIL` y `ADMIN_PASSWORD` (administrador inicial; el seeder rechaza contraseñas débiles en producción), `DB_*`, `REDIS_*`, `AWS_*`/`MINIO_*` (archivos), `REVERB_*` y `MAIL_*`. `INTEGRATIONS_ALLOW_PRIVATE_HOSTS` debe ser `false` en producción. Las credenciales de WhatsApp o n8n **no** son variables de entorno: cada Workspace las guarda cifradas desde Integraciones.

## Acceso y usuarios

1. Entra en `/pre-login` y escribe el código del Workspace (por ejemplo `DESARROLLO_DEV`).
2. Inicia sesión con correo y contraseña. No hay registro público: un administrador crea las cuentas desde **Usuarios y Roles**.
3. El administrador inicial sale de `ADMIN_EMAIL` / `ADMIN_PASSWORD`. En `local` y `testing` el seeder crea además cuentas de ejemplo con contraseñas conocidas; nunca existen en otros entornos.

Permisos de Conversaciones: `view-conversations` (leer), `reply-conversations` (tomar, escribir, devolver a la IA, resolver) y `manage-conversations` (asignar). El rol `agente` trae los dos primeros; `admin` tiene todos.

## Módulos

| Módulo | Para qué sirve |
|---|---|
| Reportes | Analítica del Workspace (solo muestra datos reales; sin fuente dice «Sin datos todavía») |
| Asistentes (Chatbots) | Identidad, instrucciones, token de acceso de n8n, canales y apariencia del chat / botón |
| Conversaciones | Bandeja unificada: filtros IA / pendientes / en atención / resueltas, asignación, respuesta del agente y devolución a la IA |
| Integraciones | n8n (Ava → n8n con prueba de conexión real), WhatsApp Business, canal Web y conexiones HTTP |
| Auditoría | Historial de cambios administrativos y de control de conversaciones (con exportación a PDF) |
| Configuraciones | Identidad, región, apariencia e impuestos del Workspace |
| Usuarios y Roles | Cuentas, roles, permisos, Workspaces y Organizaciones |

### Chatbots, canales y conversaciones

- **Web:** widget (`public/widget/ava-widget.js`) con un código de instalación único; el visitante habla con la IA a través del webhook de n8n del Workspace y, si un agente toma la conversación, recibe sus respuestas en el mismo chat.
- **WhatsApp:** n8n recibe y responde, y reporta cada mensaje a Ava. Las respuestas de un agente las envía Ava por la Graph API con el token de la integración del Workspace. Instagram y Messenger aún no están disponibles.
- **Atención humana:** `ai → pending → human → resolved`. Mientras una persona tiene la conversación, la IA no responde (n8n debe consultar `authorize` antes de enviar; el widget web lo aplica Ava misma).

### Entorno DEMO

Para probar Conversaciones sin APIs reales, en el Workspace administrativo y solo en `local`/`testing`:

```bash
docker compose exec app php artisan demo:conversations generate   # crea 11 escenarios (idempotente)
docker compose exec app php artisan demo:conversations reset      # borra solo lo demo y lo vuelve a crear
docker compose exec app php artisan demo:conversations clean      # borra solo lo demo
```

También desde el panel DEMO de la bandeja (requiere `manage-conversations`). Los datos demo se marcan con `demo_key`, `is_demo` y `simulated`; nada de ello llega a un proveedor real. En producción las rutas y el comando se niegan.

## Calidad

```bash
php artisan test                 # PHPUnit (o: make test, con SQLite en memoria)
npm run build                    # compila el frontend
vendor/bin/pint --test           # estilo (o: make pint)
make e2e-setup / make e2e-cleanup   # datos aislados para validar en navegador
graphify update .                # actualiza el grafo de código (graphify-out/, no se versiona)
```

`tests/TestCase.php` se niega a ejecutar tests contra la base de desarrollo. No hay ESLint, Prettier ni tests de frontend todavía.

## Limitaciones conocidas

- **WhatsApp y n8n reales no están validados de punta a punta:** el envío por la Graph API, el WhatsApp Trigger y los nodos `authorize` / `handoff` se probaron solo con simulaciones. Hacen falta una cuenta de WhatsApp Business y un n8n reales.
- Fuera de la ventana de 24 h de WhatsApp solo se pueden enviar plantillas aprobadas, que Ava no envía.
- Reportes todavía no tiene métricas de conversaciones; la «IA» del entorno DEMO es un texto fijo.
- Más pendientes y decisiones abiertas en [`pendientes.md`](pendientes.md).
