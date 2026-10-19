# Ava Platform

**AvaChat (Ava Platform)** es una plataforma multi-Workspace de atención inteligente al cliente: asistentes de IA que atienden conversaciones por WhatsApp y web, personas que toman el control cuando hace falta y herramientas de negocio (clientes, servicios y agenda, catálogo y pedidos) que el asistente y los agentes usan para ejecutar operaciones reales. No es un CRM ni un ERP: sus módulos existen para que la conversación pueda consultar información real, ejecutar una operación autorizada y dejar trazabilidad. Ava es la **única fuente del comportamiento** de cada chatbot (instrucciones, canales, apariencia); la IA y la conversación con WhatsApp viven en **n8n**, que lee la configuración de Ava y le reporta los mensajes. Cuando hace falta, una persona toma el control de la conversación desde Ava y responde por el mismo canal.

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

Requisitos: Docker con Compose. Los contenedores usan PHP 8.4 (`Dockerfile`), Node 22 y PostgreSQL 16. Para ejecutar PHPUnit, Pint o `npm` en el anfitrión, además PHP 8.4+ (`composer.json` declara `^8.4`), Composer y Node 20+.

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

| Puerto en el anfitrión | Servicio | Variable |
|---|---|---|
| http://localhost:8000 | App (redirige a `/pre-login`) | `APP_PORT` |
| http://localhost:5173 | Vite | `VITE_PORT` |
| 8080 | Reverb (WebSockets) | `REVERB_PORT` |
| 5433 | PostgreSQL (`5432` dentro de Docker) | `DB_FORWARD_PORT` |
| 9000 | MinIO (S3) | `MINIO_PORT` |

Además corren `worker` (Horizon), `scheduler` y `redis` sin puerto publicado. Los puertos salen de `docker-compose.yml`; la infraestructura completa y la de producción están en [`INFRASTRUCTURE.md`](INFRASTRUCTURE.md).

### Variables de entorno

Todas están documentadas en `.env.example`; nunca escribas secretos en el repositorio. Las que debes definir tú: `APP_KEY`, `ADMIN_EMAIL` y `ADMIN_PASSWORD` (administrador inicial; el seeder rechaza contraseñas débiles en producción), `DB_*`, `REDIS_*`, `AWS_*`/`MINIO_*` (archivos), `REVERB_*` y `MAIL_*`. `INTEGRATIONS_ALLOW_PRIVATE_HOSTS` debe ser `false` en producción. Las credenciales de WhatsApp o n8n **no** son variables de entorno: cada Workspace las guarda cifradas desde Integraciones.

## Permisos por recurso y operación

Cada operación de cada recurso tiene su propio permiso (70 en total, `config/permissions.php`): consultar, ver detalle, crear, editar, activar/desactivar, cancelar, anonimizar o configurar, según lo que el módulo realmente hace. Son **independientes**: marcar uno no concede otro. Lo único que se exige además del permiso de la operación es el que abre la página de su recurso (`view-*`), porque cada operación se hace desde ella; el editor de roles lo avisa sin marcar nada. El módulo del plan y el permiso del rol son dos condiciones separadas y ambas se comprueban en el servidor. Un rol de una base anterior conserva lo que podía hacer: al ejecutar `php artisan db:seed --class=RolesAndPermissionsSeeder` los permisos amplios (`manage-settings`, `manage-customers`...) se reparten una sola vez en los finos y se retiran; `delete-integrations` (retirar una conexión de prueba) no se entrega a nadie por migración. El editor de roles (Usuarios y Roles > Roles) agrupa los permisos por recurso, con búsqueda, filtros y acciones explícitas por grupo; la pestaña Permisos filtra por recurso y texto en el servidor.

## Datos de demostración

`php artisan demo:data generate` (también `status` y `clean`) crea, en el Workspace administrativo y solo en `local`/`testing`, un conjunto coherente y ficticio para probar las pantallas: 36 clientes (activos e inactivos, con los tres consentimientos), 14 productos, 24 pedidos en todos los estados, 8 servicios, 3 sedes, 5 profesionales con horarios, recursos y excepciones, unas 38 citas pasadas y futuras en todos los estados, 4 plantillas y 4 campañas (borrador, preparada, simulada y cancelada). Todo lleva una marca visible (nombres «Demo ·», SKU `DEMO-`, correos `@demo.invalid`, teléfonos +1 202 555 01xx) y `clean` elimina solo lo marcado. Es idempotente, no envía nada y se niega a ejecutarse en producción o en otro Workspace; nunca lo ejecutan los seeders ni un despliegue. Para probarlo sin tocar la base compartida: `docker compose exec -e DB_DATABASE=<base_desechable> -e DB_URL= app php artisan migrate:fresh --seed` y luego el mismo `demo:data generate`.

## Acceso y usuarios

1. Entra en `/pre-login` y escribe el código del Workspace (por ejemplo `DESARROLLO_DEV`).
2. Inicia sesión con correo y contraseña. No hay registro público: un administrador crea las cuentas desde **Usuarios y Roles**.
3. **¿Olvidaste tu contraseña?** `/forgot-password` envía un enlace de un solo uso que caduca (`AUTH_PASSWORD_RESET_EXPIRE`, 60 min); la respuesta es la misma exista o no la cuenta y hay límites por IP y por correo. Exige un `MAIL_MAILER` real y un `MAIL_FROM_ADDRESS` propio: sin ellos (fuera de local) el formulario avisa de que el correo no está configurado y no finge el envío. Cada persona cambia su contraseña desde **Mi perfil**; cambiarla o restablecerla cierra las demás sesiones abiertas. En producción la contraseña exige 12 caracteres con mayúsculas, minúsculas y números.
4. **Textos legales** públicos en `/legal` (términos, aviso de privacidad y política de datos), enlazados desde el acceso y los formularios. Son **borradores** pendientes de revisión jurídica; los datos de la empresa salen de `LEGAL_*` y, vacíos, se muestran como «[POR CONFIRMAR]». Mientras no se aplique la migración `create_legal_acceptances_tables` el perfil lo avisa y no guarda nada. La aceptación por cuenta (versión, fecha e IP) existe pero está apagada (`LEGAL_ACCEPTANCE_REQUIRED=false`) hasta que CCG confirme los textos; las comunicaciones comerciales son una autorización aparte, opcional y revocable desde el perfil.
5. El administrador inicial sale de `ADMIN_EMAIL` / `ADMIN_PASSWORD`. En `local` y `testing` el seeder crea además cuentas de ejemplo con contraseñas conocidas; nunca existen en otros entornos.

Permisos de Conversaciones: `view-conversations` (leer), `reply-conversations` (tomar, escribir, devolver a la IA, resolver), `manage-conversations` (supervisar: asignar y operar cualquiera) y `use-conversation-demo` (el entorno DEMO). El rol `agente` trae los dos primeros; `admin` tiene todos.

## Módulos

| Módulo | Para qué sirve |
|---|---|
| Dashboard | Resumen ejecutivo y operativo: indicadores, actividad en el tiempo, estado de las conversaciones, lo que requiere atención, integraciones y accesos rápidos (solo datos reales) |
| Reportes (`/reports`) | Analítica detallada del Workspace con los mismos cálculos del Dashboard: chats e interacciones con comparación contra el periodo anterior, actividad en el tiempo, canales, estados, franjas horarias y tendencias; Preguntas y Encuestas no tienen fuente de datos y lo dicen |
| Asistentes (Chatbots) | Identidad, instrucciones, token de acceso de n8n, canales y apariencia del chat / botón |
| Conversaciones | Bandeja unificada: filtros IA / pendientes / en atención / resueltas, asignación, respuesta del agente y devolución a la IA |
| Integraciones | n8n (Ava → n8n con prueba de conexión real), WhatsApp Business, canal Web y conexiones HTTP |
| Auditoría | Historial de cambios administrativos, de control de conversaciones y de procesos automáticos, con origen (persona o automático) y resultado, filtros en el servidor y exportación a PDF |
| Configuraciones | Identidad, región, apariencia e impuestos del Workspace |
| Usuarios y Roles | Cuentas, roles, permisos, Workspaces y Organizaciones |
| Servicios y Reservas | Cuatro módulos reutilizables: Servicios, Profesionales y disponibilidad, Agenda y reservas (con página pública y herramientas de Ava Chat) y Proveedores de agenda (Booker). Agenda propia de Ava o agenda externa como fuente de verdad |
| Clientes | Ficha de cliente por Workspace (contacto, canales, origen, etiquetas, consentimiento) con deduplicación segura; relaciona conversaciones y reservas sin copiarlas. El teléfono nuevo se escribe en formato internacional (`+573001112233`, sin asumir país); los históricos no se reescriben (ver «Teléfonos internacionales»). Módulo `customers`, fuera del plan por defecto |
| Campañas | Plantillas con variables y campañas con selección de destinatarios, exclusión por consentimiento, preparación y simulación. Las plantillas son borradores locales: NO hay integración con Meta y NO envía mensajes. El cuerpo se limita a 1.024 caracteres (límite del cuerpo de una plantilla de WhatsApp; contador visible, se rechaza el exceso, nunca se recorta). Módulo `campaigns` (necesita `customers`) |
| Privacidad | Los datos privados de los contactos de un cliente no se ven desde otro Workspace ni siquiera como superusuario: solo con un acceso temporal justificado (permiso `access-private-data`, motivo obligatorio, contactos enmascarados, auditado en el Workspace consultado). Propuesta de política en `docs/privacidad.md` |
| Planes (solo CCG, desde `Desarrollo_DEV`) | Catálogo de módulos reutilizables y planes comerciales: qué módulos incluye cada plan y qué plan tiene cada Workspace. La disponibilidad del módulo depende del plan y de que esté implementado; los permisos del rol siguen mandando |

### Planes y módulos

Mapa completo (módulo, categoría, grupo, plan y permiso; dependencias reales; cómo se administran los planes con ejemplos): `docs/modulos-y-planes.md`. Privacidad y acceso administrativo a datos: `docs/privacidad.md`. Integraciones reales, contrato del workflow de n8n, despliegue, pruebas reales, diagnóstico y riesgos: `RECOMENDACIONES.md`.

**Salud:** hoy NO existe ningún módulo propio de Salud (su categoría está vacía). Una clínica o consultorio contrata en un plan los mismos módulos de Servicios y Reservas (y, si quiere, Clientes); el catálogo los marca «También en Salud» (`also_for` en `config/modules.php`), una etiqueta que no concede nada. El flujo de citas (servicio → profesional con horario → reglas → disponibilidad → reserva → ficha de Cliente) está probado de punta a punta con rutas reales (`HealthAppointmentsFlowTest`); no hay historia clínica ni datos de pacientes. E-commerce y Delivery comparten un Catálogo de productos y Pedidos (módulos `product_catalog` y `orders`, permisos `view-catalog`, `create-products`, `edit-products`, `activate-products`, `view-orders`, `view-order-details`, `create-orders`, `edit-orders`, `cancel-orders`, `process-orders`): el pedido guarda una copia de lo comprado, el servidor calcula el total, los estados siguen un flujo fijo (entregado y cancelado son finales) y no hay pagos, descuentos, impuestos ni avisos al cliente todavía; migración `2026_10_20_100000_create_catalog_and_orders_tables` (ver `RECOMENDACIONES.md` §6.2). Servicios y Reservas incluye confirmaciones y recordatorios por WhatsApp, una capacidad compartida que cada Workspace configura en Agenda → Configuración (nada se envía sin activarla; el scheduler ejecuta `appointments:notify` cada minuto y un worker de colas entrega los mensajes; migración `2026_10_19_100000_create_appointment_notifications`, ver `RECOMENDACIONES.md` §6.2). Las sedes (módulo `locations`), el precio registrado en la cita y su origen ya existen (exigen la migración `2026_10_18_100000_create_locations_and_booking_traceability` y registrar el módulo con `db:seed --class=ModuleCatalogSeeder`; ya aplicadas en la base de desarrollo local el 2026-10-18, pendientes en cualquier otro entorno; ningún plan incluye `locations` todavía); los datos clínicos, la publicación de precios por el chatbot y las tarifas por profesional o sede esperan decisiones del negocio (`RECOMENDACIONES.md` §6.2 y `docs/modulos-y-planes.md`, «Inventario de Salud»).

Los módulos se agrupan conceptualmente en **Administración de la plataforma**, **Capacidades compartidas** (Asistentes, Conversaciones, Clientes, Campañas, Dashboard y Reportes) y **Soluciones comerciales** (Servicios y Reservas hoy; Salud, eCommerce y otras combinaciones después). Es solo organización del catálogo, del constructor de planes y del menú: cada módulo es una sola implementación reutilizable que cualquier plan puede incluir.

CCG arma planes combinando módulos del catálogo (las áreas Salud, Delivery, eCommerce y Servicios y Reservas son categorías, no límites). Cada Workspace cliente tiene un plan; sin plan asignado usa el plan por defecto «Plataforma completa» (los ocho módulos compartidos), así que los Workspaces existentes siguen funcionando. Los Workspaces nuevos nacen con ese plan explícito. `Desarrollo_DEV` siempre tiene todos los módulos. Hay una dependencia técnica declarada: Asistentes necesita Conversaciones (un plan que los separe no se guarda y Asistentes no se ofrece sin ella). El Dashboard y Reportes solo muestran cifras de los módulos contratados. La administración comercial exige estar en `Desarrollo_DEV` y que el rol tenga el permiso `manage-plans` (planes y asignación) o `manage-modules` (catálogo y categorías); los superusuarios que trabajan dentro de `Desarrollo_DEV` los tienen siempre; ningún rol los recibe automáticamente (ni `admin`): el sembrado crea el rol `plataforma` sin asignar y un superusuario decide a quién más dárselo. Conversaciones puede contratarse sin Asistentes (consulta: sin envío ni devolución a la IA). Tras actualizar, ejecutar `php artisan db:seed --class=RolesAndPermissionsSeeder`. Un módulo es «Implementado» solo si está declarado en `config/modules.php`; registrar uno nuevo desde Planes reserva su lugar pero no lo desarrolla. Para añadir un módulo real: declararlo en `config/modules.php` (con su `route` y `permission`), proteger sus rutas con `workspace.module:<clave>` y `workspace.permission:`, declarar sus permisos en `config/permissions.php` y en `RolesAndPermissionsSeeder`, ponerle `module` y `permission` en `resources/js/config/navigation.js` (y su texto en `resources/js/config/dashboard.js`) y, en cada entorno, ejecutar `php artisan migrate`, `db:seed --class=ModuleCatalogSeeder` y `db:seed --class=RolesAndPermissionsSeeder` (en Docker: `make dev-sync`). `Desarrollo_DEV` recibe todo módulo implementado sin plan, pero su menú solo lo muestra si el rol tiene el permiso (`ModuleRegistrationTest` y `integrations:diagnose` lo vigilan; ver `docs/modulos-y-planes.md`).

### Servicios y Reservas

Para negocios con citas (barbería, estética, y más adelante Salud). Se contratan como módulos de un plan (no están en el plan por defecto): `service_catalog` → `professionals` → `bookings`, y `calendar_providers` para una agenda externa. Permisos por recurso: agenda (`view-bookings`, `create-bookings`, `edit-bookings`, `cancel-bookings`, `manage-booking-settings`), servicios, profesionales (y `manage-availability`) y sedes (cada uno con `view-`, `create-`, `edit-` y `activate-`) (tras actualizar: `php artisan migrate`, `db:seed --class=RolesAndPermissionsSeeder` y `db:seed --class=ModuleCatalogSeeder`).

- **Agenda de Ava:** servicios (duración, precio opcional, tiempo entre citas), profesionales con horario semanal y descansos, recursos opcionales, días no laborables, citas (crear, confirmar, reprogramar, cancelar) y clientes (solo nombre y contacto). La disponibilidad la calcula un único servicio; PostgreSQL impide además por restricción que dos citas se solapen.
- **Reserva en línea:** en Agenda y reservas → Configuración se activa la página pública y se copia su enlace (`/book/{clave}`); si se filtra o se abusa de él, «Regenerar enlace» lo reemplaza al instante. Las reglas (anticipación, cancelación, confirmar reservas, datos pedidos) son del Workspace.
- **Ava Chat:** n8n usa `/api/agent/booking/*` con el token del chatbot (`/api/agent/config` anuncia las herramientas). Ava guarda la cita, la ata a la conversación, la audita y devuelve el estado real.
- **Booker:** se configura en Integraciones → Agendas externas (solo con `calendar_providers`) y se elige en Agenda y reservas → Configuración. La opción solo se muestra en un Workspace donde está conectada y activa (los demás ven únicamente la agenda de Ava). Hoy lee servicios y profesionales y lee/cancela citas; los horarios, reservar y reprogramar con Booker NO están implementados (falta verificarlos con un Sandbox) y Ava lo dice. Probado solo con respuestas simuladas.
- **Ciclo de una cita:** crear → (confirmar) → reprogramar / cancelar con motivo → realizada / no asistió. Desactivar un servicio o profesional, o cerrar un día con citas ya hechas, avisa cuántas hay y no las cancela.

### Teléfonos internacionales

Todo teléfono NUEVO o modificado (ficha de Cliente, profesional y reservas del personal, de la página pública y de Ava Chat) debe tener la forma `+` + prefijo de país + número (ITU-T E.164: código de país real, 7 a 15 dígitos; `+1` y `+7` exigen 11). Ava no asume el país del Workspace ni del usuario. Solo valida la forma, no el plan de numeración de cada país. Los números históricos no se tocan: `customers.phone` y `phone_normalized` no se reescriben, y reenviar un teléfono antiguo sin cambios al editar no se rechaza. `customers.phone_e164` (migración aditiva) existe solo para números comprobadamente internacionales, y el comando `php artisan customers:review-phones` (simulación por defecto; `--apply` y `--revert` exigen `--workspace=ID`) puede rellenarlo para históricos que traen `+` o `00`. En la API del asistente, un teléfono igual al id de WhatsApp de su conversación se acepta sin `+`. Un número local sin país nunca se vincula a uno internacional.

### Chatbots, canales y conversaciones

- **Web:** widget (`public/widget/ava-widget.js`) con un código de instalación único; el visitante habla con la IA a través del webhook de n8n del Workspace y, si un agente toma la conversación, recibe sus respuestas en el mismo chat.
- **WhatsApp:** n8n recibe y responde, y reporta cada mensaje a Ava. Las respuestas de un agente las envía Ava por la Graph API con el token de la integración del Workspace. Instagram y Messenger aún no están disponibles.
- **Atención humana:** `ai → pending → human → resolved`. Mientras una persona tiene la conversación, la IA no responde (n8n debe consultar `authorize` antes de enviar; el widget web lo aplica Ava misma).
- **Herramientas del asistente (`/api/agent/*`):** n8n las usa con el token del chatbot o, mejor, con el token de ejecución de vida corta que Ava le entrega en cada ejecución (solo sirve para la conversación de esa ejecución y muere si una persona la toma). `/api/agent/config` anuncia cuáles ofrece el plan del Workspace: **reservas** (`/api/agent/booking/*`) y **pedidos** (`/api/agent/orders/*`). El asistente nunca ejecuta nada por su cuenta: pide la operación y cuenta al contacto lo que Ava respondió.
- **Pedidos desde el asistente (Delivery y E-commerce):** con los módulos Catálogo de productos y Pedidos en el plan, el asistente consulta el catálogo con el precio de ese momento (`GET /api/agent/orders/products` y `/options`), crea un pedido (`POST /api/agent/orders`, solo productos, cantidades, entrega y contacto; **nunca** precios ni totales: los calcula Ava) y consulta los pedidos de SU conversación (`GET /api/agent/orders`). Un reintento es el mismo pedido (idempotencia por ejecución o cabecera `Idempotency-Key`), el contacto solo ve lo de su conversación, y el asistente NO cambia estados ni cancela. Hoy no hay pagos, cobertura, costo de envío, inventario ni avisos al cliente, y la API lo dice (`customerNotified`, `delivery.coverageChecked`, `stockTracked`). Un reintento con la misma clave y el mismo contenido es el mismo pedido; la misma clave con otro contenido es `409 idempotency_conflict` y no crea nada. Exige las migraciones `2026_10_21_100000_link_orders_to_conversations` y `2026_10_21_110000_create_agent_tool_calls_table`, aplicadas el 2026-10-17 solo en la base de desarrollo local `ccg_ava`; **pendientes en cualquier otro entorno** (procedimiento, copia previa y comprobaciones en `RECOMENDACIONES.md` §6.2 y §7.8); sin ellas las herramientas de pedidos responden `503 not_ready` y los pedidos del admin siguen funcionando. Probado solo contra la API de Ava con pruebas automáticas; ningún flujo real de n8n lo usa todavía.
- **Trazabilidad de las herramientas:** cada llamada del asistente a las herramientas de reservas y pedidos queda en `agent_tool_calls` (qué se pidió, cuándo, en qué conversación, si Ava lo hizo, lo rechazó, falló o es incierto, y si fue un reintento), sin datos personales ni secretos; distingue la llamada, la operación persistida y un aviso planificado (nunca «enviado»). El agente la ve en la conversación («Herramientas del asistente»). No registra lo que el workflow de n8n consulta por su cuenta. `php artisan agent:tools:reconcile` liquida las llamadas que quedaron sin resultado.
- **Reglas de honestidad:** `config/chatbots.php` `guidelines` viaja en cada ejecución (`ava.guidelines`) y en `/api/agent/config`: el asistente no debe inventar precios, horarios ni confirmaciones, ni prometer lo que Ava no hace hoy. Ava no ejecuta modelos: la regla solo puede aplicarla el workflow; lo que Ava sí aplica por su cuenta (totales, disponibilidad, estados, permisos) no depende del modelo.
- **Baja por mensaje (STOP):** si un contacto escribe exactamente `STOP` o `BAJA` (`config/customers.php` `opt_out_keywords`), el cliente vinculado a ese contacto (por identidad de canal o, en WhatsApp, por su número) pasa a consentimiento `revoked`, que toda campaña respeta. Queda auditado sin datos personales y Ava no responde al contacto (confirmar la baja es una decisión pendiente). Si el contacto no tiene ficha, no se crea ninguna.

### n8n de la plataforma (ejecución iniciada por Ava)

Modo opcional para WhatsApp, apagado mientras no se configure. Ava recibe el webhook de Meta (`/api/webhooks/whatsapp`, firmado con `META_APP_SECRET`), guarda el mensaje una sola vez y, si la IA tiene la conversación y el chatbot tiene un workflow asignado, lo ejecuta en el n8n de la plataforma (`N8N_EXECUTION_URL` y `N8N_EXECUTION_SECRET`, firma HMAC por solicitud). La respuesta vuelve en la misma llamada, Ava comprueba que el control no cambió y la envía por la Graph API. Los workflows existen en el catálogo `config/n8n.php`; el equipo los asigna a un chatbot con `php artisan workflows:manage assign|unassign|list` (columna `chatbots.workflow_key`); los clientes no la ven ni la eligen. Cada ejecución queda en `chatbot_executions` (estado, intentos, workflow usado, resultado del control y de la entrega) y `executions:recover` (programado cada minuto) retoma lo que un fallo dejó a medias sin reenviar nunca un mensaje. Un envío a WhatsApp cuyo resultado es incierto queda «Sin confirmar» y no se reintenta solo. El modo anterior (n8n recibe de Meta y reporta a Ava) sigue funcionando; no actives los dos para el mismo número. Tras desplegar, reinicia el worker (`horizon:terminate`).

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
make test                                                     # PHPUnit dentro de Docker, SQLite en memoria (o: docker compose exec app php artisan test)
php -d memory_limit=1G vendor/phpunit/phpunit/phpunit         # PHPUnit en el anfitrión
npm run test:js                                               # pruebas de JavaScript (ejecutor de Node, sin dependencias)
vendor/bin/pint --test                                        # estilo (o: make pint)
npm run build                                                 # compila el frontend
make e2e-setup / make e2e-cleanup                             # datos aislados para validar en navegador
graphify update .                                             # actualiza el grafo de código (graphify-out/, no se versiona)
```

- **En el anfitrión usa PHPUnit directo:** `php artisan test` lanza un proceso hijo que ignora `-d memory_limit` y con el límite de 128 MB del PHP de Windows se queda sin memoria (`Allowed memory size of 134217728 bytes exhausted`).
- **Última validación (2026-10-19, sexto bloque):** PHPUnit 1337 pruebas con 0 fallos en SQLite (3 omitidas: las de PostgreSQL) y en PostgreSQL 16 desechable (0 omitidas), ambas dentro del contenedor de desarrollo, que incluye GD (en el anfitrión o con otro PHP usa los mismos comandos con `DB_CONNECTION=sqlite DB_DATABASE=:memory:`); `npm run test:js` 21/21; Pint (421 archivos) y `npm run build` correctos; `composer audit` y `npm audit` sin vulnerabilidades; circuito local con salida 0. Detalle, comandos y límites en `RECOMENDACIONES.md` §11.10 (las revisiones de pantallas de la validación del 2026-10-17 siguen en §7.7, §7.8 y §8.10; este bloque no cambió ninguna pantalla).
- **Contra PostgreSQL:** crea una base desechable cuyo nombre acabe en `_test` (`TestDatabaseGuard` se niega a usar cualquier otra) y ejecuta PHPUnit con `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=<nombre>_test DB_USERNAME=... DB_PASSWORD=...`. Nunca apuntes las pruebas a `ccg_ava`.
- `tests/TestCase.php` se niega a ejecutar tests contra la base de desarrollo. No hay ESLint ni Prettier; las pruebas de JavaScript cubren la definición del menú, los permisos y las reglas del editor de mensajes, y la interfaz solo se revisa a mano con Playwright (no hay pruebas de navegador automáticas).

## Estado de preparación (2026-10-17)

Resumen; evidencia, definiciones de «listo», brechas por solución y decisiones pendientes en `RECOMENDACIONES.md` §8.

| Nivel | Veredicto |
|---|---|
| Entorno de pruebas desplegable | **Apto con condiciones operativas** (HTTPS, variables, worker y scheduler, copia programada); sin bloqueadores de código conocidos |
| Piloto con clientes reales | **No listo:** ninguna integración externa (Meta, n8n, Booker) se ha ejecutado contra el servicio real y el workflow de la empresa no está adaptado al contrato de Ava; falta aviso de privacidad y contrato de tratamiento. Es viable un piloto técnico interno y, después, uno limitado de Servicios y Reservas / Salud (solo citas) |
| Comercialización | **No listo:** sin límites, precios, medición de uso, suscripción, facturación ni onboarding |

**Cuarto bloque (piloto):** flujo Ava–n8n–WhatsApp trazado y probado con servicios simulados (ninguna conexión real validada), comandos programados observables (`integrations:diagnose` distingue «no se ejecuta» de «falla»), copias con prueba de restauración (`make backup`, `make restore-check`), borradores de privacidad pendientes de revisión jurídica (`docs/privacidad.md` Parte III), primer piloto definido y estructura de planes propuesta sin precios. Detalle en `RECOMENDACIONES.md` §9.

**Quinto bloque (circuito local):** `sh docker/ops/local-loop/run.sh` recorre mensaje firmado de Meta → Ava → cola → doble de n8n → herramientas de reservas → persistencia → respuesta → trazas sobre una base desechable, con HTTP y cola reales pero con Meta y n8n SIMULADOS (no prueba servicios reales). Hallazgos F1 a F6, veredictos y pasos de la primera prueba real en `RECOMENDACIONES.md` §10.

**Sexto bloque (preparación del piloto del 21-oct-2026):** D-TEL (en WhatsApp la reserva usa el número de la conversación cuando el workflow no envía teléfono y es un número internacional válido; en web se pide teléfono o correo y Ava responde `422 contact_required` si falta) y D-FALLO (si la IA falla de forma definitiva la conversación pasa a «Pendiente de agente», se escribe una alerta local `ai_failure_handed_to_human` y, solo si el negocio lo configuró, el contacto recibe el texto aprobado `CHATBOT_FAILURE_NOTICE`). El circuito local cubre ambos. Contrato exacto del workflow de n8n, alcance del piloto, bloqueadores, pasos de despliegue y criterios de parada en `RECOMENDACIONES.md` §11. **Nada de esto se ha probado con Meta ni con n8n reales.**

**Revisión integral previa a la publicación (2026-10-19):** estado consolidado, hipótesis de Booker, requisitos de Staging, de Meta/WhatsApp y de despliegue, riesgos y criterios de parada en `RECOMENDACIONES.md` §13.

**Cómo iniciar las pruebas locales:** con el stack de desarrollo levantado (`docker compose up -d`), `sh docker/ops/local-loop/run.sh` (en Git Bash de Windows: `MSYS_NO_PATHCONV=1 sh docker/ops/local-loop/run.sh`); usa una base desechable `ccg_ava_e2e` que crea y elimina, y se niega a correr si el contenedor no es `APP_ENV=local`.

## Estado de integraciones y módulos

| Parte | Implementado | Validación |
|---|---|---|
| Servicios y Reservas (agenda de Ava, página pública, Ava Chat) | Sí | Pruebas automáticas y navegador; concurrencia en PostgreSQL a mano |
| Catálogo de productos, Pedidos y herramientas de pedidos del asistente (Delivery y E-commerce, primera fase) | Sí, sin pagos, cobertura, envío, inventario ni avisos | Pruebas automáticas (SQLite y PostgreSQL); ningún workflow real de n8n las usa todavía |
| Clientes, Campañas (borradores y simulación), Planes, permisos, aislamiento, privacidad | Sí | Pruebas automáticas (SQLite y PostgreSQL) |
| WhatsApp (Meta), n8n de la plataforma, n8n por Workspace | Sí | **Solo simulado**: faltan cuentas, workflow y licencia reales |
| Teléfono de la reserva por WhatsApp (D-TEL) y traspaso a una persona cuando la IA falla (D-FALLO) | Sí | Pruebas automáticas (SQLite y PostgreSQL) y circuito local con dobles; sin alerta externa configurada |
| Booker | API Merchant (horarios, clientes y creación de cita) más el modo `customer` anterior (servicios, profesionales, leer y cancelar); varias sucursales por Workspace | **Solo simulado**: ninguna llamada real a Booker; pendiente de Staging con credenciales corporativas. Reprogramar no existe; cancelar y leer no existen en modo Merchant |
| Envío de Campañas y plantillas de Meta | **No** | No hay integración ni envío |
| Módulos propios de Salud, Delivery y eCommerce | **No** | Categorías vacías |

## Limitaciones conocidas

- **WhatsApp y n8n reales no están validados de punta a punta:** el envío por la Graph API, el WhatsApp Trigger y los nodos `authorize` / `handoff` se probaron solo con simulaciones. Hacen falta una cuenta de WhatsApp Business y un n8n reales.
- **La ejecución iniciada por Ava (webhook de Meta + n8n de la plataforma) solo se probó con simulaciones.** Falta un n8n con el workflow, una app de Meta con el webhook apuntando a Ava y verificar con n8n que su licencia permite este modelo de servicio antes del uso comercial. Meta no ofrece idempotencia al enviar: si el resultado de un envío es incierto, Ava no lo reenvía y depende de que Meta devuelva el estado con la etiqueta `ava:{id}` para completarlo.
- Fuera de la ventana de 24 h de WhatsApp solo se pueden enviar plantillas aprobadas, que Ava no envía.
- Reportes no tiene Preguntas ni Encuestas (Ava no guarda esa información) ni tiempos de respuesta; la «IA» del entorno DEMO es un texto fijo.
- Planes: los módulos específicos de Salud, Delivery y eCommerce todavía no existen (sus categorías están vacías; Salud puede usar los de Servicios y Reservas); no hay precios, límites ni renovación. La interfaz de Planes se revisó en navegador (móvil, tablet y escritorio) sin pruebas de frontend automáticas.
- Los modales de Clientes y de Plantillas (diseño por filas, contador de caracteres) se comprobaron con una maqueta estática y las pruebas de JavaScript, no dentro de la aplicación con sesión.
- Más pendientes y decisiones abiertas en [`pendientes.md`](pendientes.md).
