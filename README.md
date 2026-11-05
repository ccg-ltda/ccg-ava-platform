# Ava Platform

Plataforma multi-Workspace de atención inteligente al cliente: asistentes de IA que atienden conversaciones por WhatsApp y web, personas que toman el control cuando hace falta y herramientas de negocio (clientes, agenda y reservas, catálogo y pedidos) que el asistente y los agentes usan para ejecutar operaciones reales. No es un CRM ni un ERP. Ava es la **única fuente del comportamiento** de cada chatbot (instrucciones, canales, apariencia); la IA vive en **n8n**, que lee la configuración de Ava, reporta mensajes y pide handoff.

**Documentos:** reglas y decisiones permanentes → [`reglas.md`](reglas.md) · pendientes, riesgos y decisiones → [`RECOMENDACIONES.md`](RECOMENDACIONES.md) · operación, despliegue y AWS → [`INFRASTRUCTURE.md`](INFRASTRUCTURE.md) · contrato Ava → n8n → [`N8N_WORKFLOW_REFERENCIA.md`](N8N_WORKFLOW_REFERENCIA.md) · contrato n8n → Ava y WooCommerce → [`docs/api-n8n-contratos.md`](docs/api-n8n-contratos.md) · módulos y planes → [`docs/modulos-y-planes.md`](docs/modulos-y-planes.md) · privacidad → [`docs/privacidad.md`](docs/privacidad.md) · textos legales (borradores y PDF) → [`docs/legal/`](docs/legal/00-indice-y-datos-pendientes.md).

## Arquitectura

```
Organization → Workspace → membresía (workspace_user.role) → usuarios
                  └── chatbots → canales (Web, WhatsApp, Instagram, Messenger) → integraciones del Workspace
                                      └── conversaciones → mensajes
```

- **Aislamiento por Workspace:** el Workspace sale siempre de la sesión o del token, nunca del cliente; toda consulta de negocio parte de él. Los roles son un catálogo global (Spatie); `workspace_user.role` asigna uno por Workspace y la autorización sale de sus permisos (uno por operación, `config/permissions.php`). El módulo del plan y el permiso del rol son dos condiciones independientes, ambas comprobadas en el servidor.
- **`DESARROLLO_DEV`** (`WORKSPACE_ADMIN_CODE`) es el Workspace administrativo interno: solo un superusuario que trabaja dentro de él administra planes, módulos y Workspaces. El contenido privado de otro Workspace solo se lee con un acceso de soporte temporal, justificado y auditado.
- **Ava ↔ n8n:** `/api/agent/*` con el token del chatbot (mensajes, contexto, handoff, actividad técnica, reservas, encuestas) y ejecución firmada Ava → n8n. Atención humana: `ai → pending → human → resolved`; mientras una persona tiene la conversación, la IA no responde.

## Tecnologías y requisitos

PHP 8.4 · Laravel 13 · Inertia 3 · React 19 (JSX) · Vite 8 · Tailwind CSS 4 · PostgreSQL 16 · Redis 7 · Horizon · Reverb · MinIO/S3 · `spatie/laravel-permission`. Requisitos: Docker con Compose; para PHPUnit, Pint o `npm` en el anfitrión, PHP 8.4+, Composer y Node 20+.

## Puesta en marcha

```bash
cp .env.example .env            # PowerShell: Copy-Item .env.example .env
# APP_KEY una vez (ver INFRASTRUCTURE.md) y pégala en .env
docker compose up -d --build    # o: make up
docker compose exec app php artisan db:seed
```

- Las migraciones corren solas al arrancar `app` en desarrollo (`RUN_MIGRATIONS=true`). `db:seed` es re-ejecutable: crea permisos y roles, el administrador inicial (`ADMIN_EMAIL`/`ADMIN_PASSWORD`) y los Workspaces base sin pisar cambios de un superusuario. En un entorno existente, tras añadir un permiso: `php artisan db:seed --class=RolesAndPermissionsSeeder`.
- Tras actualizar el código reinicia el worker (`php artisan horizon:terminate`). `make fresh` borra la base: solo en desarrollo.
- Puertos en el anfitrión: app 8000, Vite 5173, Reverb 8080, PostgreSQL 5433, MinIO 9000.
- Variables: todas en `.env.example`; nunca escribas secretos en el repositorio. Las credenciales de WhatsApp o n8n **no** son variables: cada Workspace las guarda cifradas en Integraciones. `INTEGRATIONS_ALLOW_PRIVATE_HOSTS=false` en producción.
- Acceso: `/pre-login` (código de Workspace) → `/login`. No hay `/register`; las cuentas las crea un administrador (por invitación o directamente) y existe la contratación pública en `/registro` (sin pasarela validada: no la trates como lista para clientes).
- Datos de demostración (solo `local`/`testing`, Workspace administrativo): `php artisan demo:data generate|status|clean --confirm=<código>` y `php artisan demo:conversations generate|reset|clean --confirm=<código>`; nada los elimina solo.

## Módulos

Dashboard · Reportes (conversaciones, reservas de cualquier origen, encuestas, pedidos, economía, clientes) · Asistentes · Conversaciones · Integraciones · Auditoría · Configuraciones · Usuarios y Roles · Servicios y Reservas (agenda propia o Booker) · Catálogo, inventario por sede y Pedidos · Sedes · Clientes · Campañas (sin envío real) · Recordatorios · Planes (solo CCG). Qué ve cada Workspace depende de su plan; detalle y dependencias en `docs/modulos-y-planes.md`.

## Calidad

```bash
php -d memory_limit=2G vendor/bin/phpunit --do-not-cache-result   # suite completa en el anfitrión (≈30 min), con APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: FILESYSTEM_DISK=local CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync BROADCAST_CONNECTION=null
npm run test:js        # pruebas JavaScript
vendor/bin/pint --test # estilo (o: make pint)
npm run build          # frontend
graphify update .      # grafo de código (graphify-out/, no se versiona)
```

- En el anfitrión usa PHPUnit directo (`php artisan test` se queda sin memoria en Windows). Dentro del contenedor, con el directorio montado desde Windows, PHPUnit descubre solo una parte de las pruebas: no tomes su conteo como el de la suite.
- **PostgreSQL:** las pruebas de concurrencia (`BookingRace`, `BookingConcurrency`, `InventoryConcurrency`, `N8nConcurrency`, `SupportAudit`) se omiten en SQLite. Crea una base **desechable cuyo nombre termine en `_testing`** y ejecútalas con `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=<nombre>_testing DB_USERNAME=… DB_PASSWORD=…`. Nunca apuntes pruebas a `ccg_ava`; `tests/TestCase.php` y `E2eFixtures` se niegan a usar la base de desarrollo.
- Validación en navegador: `make e2e-setup` / `make e2e-cleanup` sobre una base desechable (se niegan sobre `ccg_ava` salvo `E2E_ALLOW_DATABASE=ccg_ava`).

## Postman y n8n

- **Colección:** `postman/ava-platform.postman_collection.json` (única; todas las rutas `/api/*`). Importarla, crear un *Environment* con `base_url` y `agent_token` (token de un chatbot de **pruebas**) y ejecutar la carpeta «01 API del agente (n8n)»; las demás carpetas son un inventario de solo lectura (sesión, claves públicas o firmas de proveedor, según cada una). Con Newman: `npx newman run postman/ava-platform.postman_collection.json --folder "01 API del agente (n8n) — Bearer del chatbot" --env-var base_url=http://127.0.0.1:8011 --env-var agent_token=<token> --env-var contact_id=<id nuevo>` contra un servidor local desechable (`php -S 127.0.0.1:8011 -t public` con `DB_*` hacia una base `*_testing`). **Nunca contra `ccg_ava`**: escribe mensajes, pasos, reservas y encuestas.
- **Workflow:** plantilla en `docs/n8n/ava-referencia.workflow.json` (sin credenciales, sin probar en n8n real); pasos en `docs/api-n8n-contratos.md`.

## Favicon

En `public/brand/` (nombres exactos; se enlazan solo los que existen): `favicon.ico`, `favicon.svg`, `favicon.png` y `apple-touch-icon.png` (180×180). **Hoy hay `Favicon.jpg` (el original entregado, sin tocar: es la ficha azul sobre un fondo gris de maqueta, no apta como icono de pestaña) y `favicon.png` (256×256, derivado de ese JPG: ficha recortada y fondo transparente; es el que usan las páginas).** La mascota del login es `Mascota.jpg` (original, se muestra entera). Para regenerar `favicon.png`: recortar la ficha azul en cuadrado y dejar transparente el gris exterior. Las páginas los enlazan solas (solo los que existen) y `/favicon.ico` redirige al ICO. Comprobar: abrir `/brand/favicon.svg`, recargar con Ctrl+F5 y buscar `rel="icon"` en el código fuente. No hay icono de PWA (Ava no es una PWA) y el logotipo de cada Workspace se sube en Configuraciones → Apariencia.
