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

Requisitos: Docker con Compose. Los contenedores usan PHP 8.4 (`Dockerfile`), Node 22 y PostgreSQL 16. Para ejecutar PHPUnit, Pint o `npm` en el anfitrión, además PHP 8.4+ (`composer.json` declara `^8.3`, pero el `composer.lock` exige 8.4.1 o superior), Composer y Node 20+.

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

## Acceso y usuarios

1. Entra en `/pre-login` y escribe el código del Workspace (por ejemplo `DESARROLLO_DEV`).
2. Inicia sesión con correo y contraseña. No hay registro público: un administrador crea las cuentas desde **Usuarios y Roles**.
3. El administrador inicial sale de `ADMIN_EMAIL` / `ADMIN_PASSWORD`. En `local` y `testing` el seeder crea además cuentas de ejemplo con contraseñas conocidas; nunca existen en otros entornos.

Permisos de Conversaciones: `view-conversations` (leer), `reply-conversations` (tomar, escribir, devolver a la IA, resolver) y `manage-conversations` (asignar). El rol `agente` trae los dos primeros; `admin` tiene todos.

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

**Salud:** hoy NO existe ningún módulo propio de Salud (su categoría está vacía). Una clínica o consultorio contrata en un plan los mismos módulos de Servicios y Reservas (y, si quiere, Clientes); el catálogo los marca «También en Salud» (`also_for` en `config/modules.php`), una etiqueta que no concede nada. El flujo de citas (servicio → profesional con horario → reglas → disponibilidad → reserva → ficha de Cliente) está probado de punta a punta con rutas reales (`HealthAppointmentsFlowTest`); no hay historia clínica ni datos de pacientes. Servicios y Reservas incluye confirmaciones y recordatorios por WhatsApp, una capacidad compartida que cada Workspace configura en Agenda → Configuración (nada se envía sin activarla; el scheduler ejecuta `appointments:notify` cada minuto y un worker de colas entrega los mensajes; migración `2026_10_19_100000_create_appointment_notifications`, ver `RECOMENDACIONES.md` §6.2). Las sedes (módulo `locations`), el precio registrado en la cita y su origen ya existen (exigen la migración `2026_10_18_100000_create_locations_and_booking_traceability` y registrar el módulo con `db:seed --class=ModuleCatalogSeeder`; ya aplicadas en la base de desarrollo local el 2026-10-18, pendientes en cualquier otro entorno; ningún plan incluye `locations` todavía); los datos clínicos, la publicación de precios por el chatbot y las tarifas por profesional o sede esperan decisiones del negocio (`RECOMENDACIONES.md` §6.2 y `docs/modulos-y-planes.md`, «Inventario de Salud»).

Los módulos se agrupan conceptualmente en **Administración de la plataforma**, **Capacidades compartidas** (Asistentes, Conversaciones, Clientes, Campañas, Dashboard y Reportes) y **Soluciones comerciales** (Servicios y Reservas hoy; Salud, eCommerce y otras combinaciones después). Es solo organización del catálogo, del constructor de planes y del menú: cada módulo es una sola implementación reutilizable que cualquier plan puede incluir.

CCG arma planes combinando módulos del catálogo (las áreas Salud, Delivery, eCommerce y Servicios y Reservas son categorías, no límites). Cada Workspace cliente tiene un plan; sin plan asignado usa el plan por defecto «Plataforma completa» (los ocho módulos compartidos), así que los Workspaces existentes siguen funcionando. Los Workspaces nuevos nacen con ese plan explícito. `Desarrollo_DEV` siempre tiene todos los módulos. Hay una dependencia técnica declarada: Asistentes necesita Conversaciones (un plan que los separe no se guarda y Asistentes no se ofrece sin ella). El Dashboard y Reportes solo muestran cifras de los módulos contratados. La administración comercial exige estar en `Desarrollo_DEV` y que el rol tenga el permiso `manage-plans` (planes y asignación) o `manage-modules` (catálogo y categorías); los superusuarios que trabajan dentro de `Desarrollo_DEV` los tienen siempre; ningún rol los recibe automáticamente (ni `admin`): el sembrado crea el rol `plataforma` sin asignar y un superusuario decide a quién más dárselo. Conversaciones puede contratarse sin Asistentes (consulta: sin envío ni devolución a la IA). Tras actualizar, ejecutar `php artisan db:seed --class=RolesAndPermissionsSeeder`. Un módulo es «Implementado» solo si está declarado en `config/modules.php`; registrar uno nuevo desde Planes reserva su lugar pero no lo desarrolla. Para añadir un módulo real: declararlo en `config/modules.php`, proteger sus rutas con `workspace.module:<clave>`, ponerle `module` en `resources/js/config/navigation.js` y ejecutar `php artisan db:seed --class=ModuleCatalogSeeder`.

### Servicios y Reservas

Para negocios con citas (barbería, estética, y más adelante Salud). Se contratan como módulos de un plan (no están en el plan por defecto): `service_catalog` → `professionals` → `bookings`, y `calendar_providers` para una agenda externa. Permisos: `view-bookings`, `manage-bookings`, `manage-services` (tras actualizar: `php artisan migrate`, `db:seed --class=RolesAndPermissionsSeeder` y `db:seed --class=ModuleCatalogSeeder`).

- **Agenda de Ava:** servicios (duración, precio opcional, tiempo entre citas), profesionales con horario semanal y descansos, recursos opcionales, días no laborables, citas (crear, confirmar, reprogramar, cancelar) y clientes (solo nombre y contacto). La disponibilidad la calcula un único servicio; PostgreSQL impide además por restricción que dos citas se solapen.
- **Reserva en línea:** en Agenda y reservas → Configuración se activa la página pública y se copia su enlace (`/book/{clave}`); si se filtra o se abusa de él, «Regenerar enlace» lo reemplaza al instante. Las reglas (anticipación, cancelación, confirmar reservas, datos pedidos) son del Workspace.
- **Ava Chat:** n8n usa `/api/agent/booking/*` con el token del chatbot (`/api/agent/config` anuncia las herramientas). Ava guarda la cita, la ata a la conversación, la audita y devuelve el estado real.
- **Booker:** se configura en Integraciones → Agendas externas (solo con `calendar_providers`) y se elige en Agenda y reservas → Configuración. Hoy lee servicios y profesionales y lee/cancela citas; los horarios, reservar y reprogramar con Booker NO están implementados (falta verificarlos con un Sandbox) y Ava lo dice. Probado solo con respuestas simuladas.
- **Ciclo de una cita:** crear → (confirmar) → reprogramar / cancelar con motivo → realizada / no asistió. Desactivar un servicio o profesional, o cerrar un día con citas ya hechas, avisa cuántas hay y no las cancela.

### Teléfonos internacionales

Todo teléfono NUEVO o modificado (ficha de Cliente, profesional y reservas del personal, de la página pública y de Ava Chat) debe tener la forma `+` + prefijo de país + número (ITU-T E.164: código de país real, 7 a 15 dígitos; `+1` y `+7` exigen 11). Ava no asume el país del Workspace ni del usuario. Solo valida la forma, no el plan de numeración de cada país. Los números históricos no se tocan: `customers.phone` y `phone_normalized` no se reescriben, y reenviar un teléfono antiguo sin cambios al editar no se rechaza. `customers.phone_e164` (migración aditiva) existe solo para números comprobadamente internacionales, y el comando `php artisan customers:review-phones` (simulación por defecto; `--apply` y `--revert` exigen `--workspace=ID`) puede rellenarlo para históricos que traen `+` o `00`. En la API del asistente, un teléfono igual al id de WhatsApp de su conversación se acepta sin `+`. Un número local sin país nunca se vincula a uno internacional.

### Chatbots, canales y conversaciones

- **Web:** widget (`public/widget/ava-widget.js`) con un código de instalación único; el visitante habla con la IA a través del webhook de n8n del Workspace y, si un agente toma la conversación, recibe sus respuestas en el mismo chat.
- **WhatsApp:** n8n recibe y responde, y reporta cada mensaje a Ava. Las respuestas de un agente las envía Ava por la Graph API con el token de la integración del Workspace. Instagram y Messenger aún no están disponibles.
- **Atención humana:** `ai → pending → human → resolved`. Mientras una persona tiene la conversación, la IA no responde (n8n debe consultar `authorize` antes de enviar; el widget web lo aplica Ava misma).

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
- **Última validación (2026-10-17):** PHPUnit 1137/1137 en SQLite (≈170 s) y 1137/1137 en PostgreSQL 16 (≈570 s); `npm run test:js` 21/21; Pint y `npm run build` correctos.
- **Contra PostgreSQL:** crea una base desechable cuyo nombre acabe en `_test` (`TestDatabaseGuard` se niega a usar cualquier otra) y ejecuta PHPUnit con `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=<nombre>_test DB_USERNAME=... DB_PASSWORD=...`. Nunca apuntes las pruebas a `ccg_ava`.
- `tests/TestCase.php` se niega a ejecutar tests contra la base de desarrollo. No hay ESLint ni Prettier; las pruebas de JavaScript cubren la definición del menú, los permisos y las reglas del editor de mensajes, y la interfaz solo se revisa a mano con Playwright (no hay pruebas de navegador automáticas).

## Estado de integraciones y módulos

| Parte | Implementado | Validación |
|---|---|---|
| Servicios y Reservas (agenda de Ava, página pública, Ava Chat) | Sí | Pruebas automáticas y navegador; concurrencia en PostgreSQL a mano |
| Clientes, Campañas (borradores y simulación), Planes, permisos, aislamiento, privacidad | Sí | Pruebas automáticas (SQLite y PostgreSQL) |
| WhatsApp (Meta), n8n de la plataforma, n8n por Workspace | Sí | **Solo simulado**: faltan cuentas, workflow y licencia reales |
| Booker | Parcial: conexión, servicios, profesionales, leer y cancelar | **Solo simulado**; horarios, reservar y reprogramar NO existen |
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
