# Reglas de desarrollo: Ava

Guía obligatoria para cualquier agente o desarrollador. Si algo aquí contradice una instrucción puntual de la tarea, mandan estas reglas, salvo que la tarea pida explícitamente cambiarlas.

## 1. Antes de empezar cualquier tarea

1. Lee `reglas.md` y `pendientes.md` completos, aunque ya los hayas leído. Pueden haber cambiado.
2. Relaciona la tarea con los pendientes: busca soluciones parciales, conflictos y dependencias.
3. Si surge deuda técnica o una mejora fuera del alcance, no amplíes la tarea. Anótala en `pendientes.md` (sección Ideas o Pendientes), sin duplicar entradas.

## 2. Stack real (no asumir otro)

- Backend: PHP 8.4 (contenedor), Laravel 13, Inertia 3 (`inertia-laravel`), Sanctum instalado pero sin uso, `spatie/laravel-permission`, Horizon, Reverb, Ziggy.
- Frontend: React 19 en JSX (sin TypeScript), alias `@/*` a `resources/js/*`, Vite 8, Tailwind CSS 4. No hay librería de componentes ni de iconos.
- Datos: PostgreSQL 16, Redis 7 (phpredis), MinIO/S3 para archivos. Tests con SQLite en memoria.
- Todo corre en Docker. Servicios de desarrollo: app, worker (Horizon), scheduler, reverb, node, postgres, redis y minio.

## 3. Reutilización (regla principal)

Toda lógica se escribe una sola vez, en un lugar central, y se importa donde se necesite. Nunca se copia.

- Antes de crear algo, busca si ya existe: componentes, hooks, helpers, layouts, reglas de validación, traits, servicios.
- Si una funcionalidad se va a usar en más de un lugar (por ejemplo, la seguridad o configuración del login que luego aplica a la página inicial), créala como pieza compartida desde el primer día y úsala en ambos sitios.
- En JavaScript eso significa `export` en un archivo e `import` donde se use. En PHP, una clase, trait o regla compartida.
- Dónde vive cada cosa:
  - Componentes visuales reutilizables: `resources/js/Components/`.
  - Lógica de React reutilizable: `resources/js/Hooks/`.
  - Llamadas a la API y utilidades: `resources/js/Services/` y `resources/js/lib/`.
  - Layouts: `resources/js/Layouts/`. Hay un solo layout por tipo (acceso, aplicación).
  - Estilos y tokens: `resources/css/app.css`, usando `@theme` y `@layer components`.
  - Validaciones: `FormRequest` o reglas compartidas, no `$request->validate` repetido.
  - Lógica de negocio: `app/Services/` o `app/Actions/`, no dentro de los controladores.
- Si al implementar encuentras lógica duplicada en el código que vas a tocar, centralízala en esa misma tarea cuando el cambio sea pequeño y seguro. Si es grande, anótala en `pendientes.md`.

## 4. Código limpio y escalable

- SOLID, DRY, KISS, bajo acoplamiento y alta cohesión.
- Funciones y componentes pequeños, con una sola responsabilidad. Nombres claros y autoexplicativos.
- Sin código muerto, sin código comentado, sin soluciones temporales.
- Valida siempre las entradas y maneja errores de forma explícita.
- Todo lo que se construya debe poder crecer: pensar en más módulos, más roles y más usuarios, no solo en el caso actual.
- No hagas refactorizaciones masivas fuera del alcance de la tarea.

## 5. Diseño, estética y responsive

- Toda pantalla debe verse y funcionar bien en móvil, tablet y escritorio. Diseña primero para móvil y amplía con `sm:`, `md:`, `lg:` y `xl:`.
- Todo alineado y consistente: mismos espaciados, tamaños, tipografías y radios en toda la aplicación.
- Los colores, fuentes y espaciados salen de tokens definidos en `resources/css/app.css`. No uses valores sueltos repetidos.
- No uses Tailwind por CDN. Todo el CSS se compila con Vite.
- Comprueba cada pantalla nueva en los tres tamaños antes de darla por terminada, y evita desbordes horizontales.
- Textos legibles, contraste suficiente, controles táctiles cómodos en móvil y estados visibles (hover, foco, deshabilitado, error, carga).
- Las páginas definitivas son React/Inertia. Los HTML y Blade de referencia no son fuente de verdad.

## 6. Arquitectura y convenciones del repo

- Rutas en inglés y en kebab-case (`pre-login`, `users.index`). Controladores en singular con sufijo `Controller`. Páginas React en PascalCase por feature (`Pages/Users/Index.jsx`).
- Modelos con atributos `#[Fillable]` y `casts()` como método.
- Respuestas: no hay API JSON propia. Se usa `Inertia::render`, `view` o `redirect`. Los errores de validación viajan como errores de sesión.
- Nunca uses `env()` fuera de `config/*.php`. El código lee `config('...')`. Excepción actual: `TRUSTED_PROXIES` en `bootstrap/app.php`.
- Si agregas una clave de configuración, defínela en `config/*.php` y documenta la variable en `.env.example`.
- Si cambias un contrato entre Laravel y React (nombres de campos, props, estructura, validaciones, permisos), revisa ambos lados.

## 6b. Migraciones

- El proyecto aún no está desplegado, así que las migraciones son el esquema inicial final, con fecha `2026_10_02_HHMMSS` y agrupadas por tema (una migración puede crear varias tablas que siguen siendo independientes):
  1. `create_authentication_tables`: `users`, `password_reset_tokens`, `sessions`.
  2. `create_permission_tables`: las cinco tablas de Spatie, tal como las publica el paquete (se conserva en un solo archivo porque lee `config/permission.php`).
  3. `create_workspace_tables`: `organizations`, `workspaces`, `workspace_user` (Organization → Workspaces → membresía → usuarios; `workspace_user` conserva sus FKs y `unique(workspace_id, user_id)`).
  4. `create_infrastructure_tables`: `cache`, `cache_locks`, `jobs`, `failed_jobs`.
  5. Funcionalidades posteriores llevan su propia migración `create_*` con fecha de su día (`2026_10_05_..._create_workspace_settings_table`: `workspace_settings`, 1:1 con `workspaces`; `2026_10_07_100000_create_chatbots_tables`: `chatbots` con su token de acceso y `chatbot_channels` con su clave pública; `2026_10_07_200000_create_conversations_tables`: `conversations` y `messages`).
- Auditoría de tablas (según el código y la configuración reales): `users` (identidad global), `password_reset_tokens` (broker de contraseñas de Laravel, `config/auth.php`), `failed_jobs` (`queue.failed.driver=database-uuids` en todos los entornos, también con Redis/Horizon) y las de Ava/Spatie se usan siempre. `sessions`, `cache`, `cache_locks` y `jobs` solo se usan cuando el driver es `database`: es el valor por defecto del framework y el que usa el `.env` de desarrollo en el host (`composer run dev`); Docker y `.env.example` usan Redis y no las tocan. Se conservan porque la configuración actual las necesita y porque cambiar los drivers para quitarlas no estaba autorizado; `cache_locks` es la tabla de bloqueos de ese mismo store. Spatie: se conservan las cinco (`model_has_roles` y `model_has_permissions` no guardan datos nuevos porque los roles se asignan por Workspace, pero `HasRoles` y los modelos de Spatie las consultan, por ejemplo al borrar un usuario o un rol).
- Mientras Ava no esté desplegada (etapa actual), un cambio estructural de una tabla que aún forma parte del esquema inicial se integra en su migración `create_*` y no se acumulan migraciones `add_*` (así se hizo con el token de acceso, la clave pública y la marca de última lectura de n8n, y con el índice único compuesto de `integrations`). Tras consolidar se comprueba el esquema desde cero en una base aislada y se alinea la tabla `migrations` de la base de desarrollo (renombrar o borrar las filas de los archivos absorbidos) sin reconstruirla ni perder datos.
- Desde el primer despliegue, todo cambio de esquema va en una migración nueva (`add_*`); no se editan las ya desplegadas.
- Para comprobar el esquema limpio sin tocar la base de desarrollo: crear una base vacía (`CREATE DATABASE ccg_ava_migrate_check`) y ejecutar `migrate:fresh --seed` con `DB_DATABASE` apuntando a ella.

## 7. Autenticación, roles y aislamiento por Workspace

- Flujo de acceso: `/` redirige a `/pre-login`, se valida el código del Workspace y luego `/login` con email y contraseña.
- Estructura: Organization, Workspace y membresía `workspace_user`. El rol efectivo sale de `workspace_user.role`. `users.is_superuser` es el superusuario.
- El Workspace nunca viene del cliente. `EnsureWorkspaceContext` lo revalida en cada petición. Los mensajes de login son genéricos.
- Toda consulta de datos de negocio debe partir del Workspace activo. El aislamiento hoy es manual: nunca hagas consultas que crucen Workspaces sin una razón explícita.
- Para toda tabla de negocio nueva, propón y reutiliza un mecanismo central de aislamiento (trait o scope global) en lugar de repetir filtros.
- Las reglas de contraseña salen de una sola fuente compartida, no se repiten por controlador.
- Fuente de verdad de roles: las tablas Spatie `roles` y `permissions` son el catálogo GLOBAL (qué permisos da cada rol); `workspace_user.role` asigna un rol a un usuario dentro de un Workspace. La autorización sale de los permisos de ese rol (`workspace.permission:<permiso>`), nunca del nombre del rol ni de roles Spatie asignados al usuario (`model_has_roles` no se usa). Solo los superusuarios cambian el catálogo (`can:manage-roles`); los admins de Workspace solo asignan roles cuyos permisos ya poseen. Lógica en `app/Services/RoleCatalog.php` y `UserIdentityGuard.php`.
- No hay registro público (`/register` no existe): las cuentas las crea un admin del Workspace desde `/users`, siempre dentro de ese Workspace.
- Las cuentas nunca se borran: se desactivan (`users.is_active`) y se pueden reactivar; los Workspaces y las Organizations tampoco se borran (`is_active`). Quitar a alguien de un Workspace solo borra su fila de `workspace_user`.
- La lista de `/users` es del Workspace activo a propósito (aislamiento por Workspace): un usuario creado o movido a otro Workspace aparece al cambiar a ese Workspace. Los superusuarios administran los demás Workspaces desde la pestaña Workspaces (Miembros).
- Crear un usuario con un correo que ya existe falla con el error de unicidad: es intencional (una cuenta es una identidad global y no se duplica). Los superusuarios añaden cuentas existentes a un Workspace desde Workspaces > Miembros.
- Los textos de la interfaz y de los mensajes del framework están en español (`lang/es`, `APP_LOCALE=es`); las reglas que falten en `lang/es/validation.php` caen al inglés del framework.
- Configuraciones es del Workspace activo (`workspace_settings`, una fila por Workspace; sin fila usa los valores de `config/workspace.php`): el Workspace sale siempre de la sesión, nunca del cliente, y la ruta exige `manage-settings`. Los valores permitidos de cada opción salen de `config/workspace.php` (formulario y validación comparten esa fuente). El logo se guarda en el disco por defecto (`workspaces/{id}/logo/...`, S3/MinIO en Docker) y se sirve por `/workspace/logo` solo a miembros; se aceptan PNG, JPG y WebP de hasta 1 MB (nunca SVG). El color principal es lo único de la paleta que un Workspace puede cambiar y debe mantener contraste ≥ 4,5 con texto blanco; el modo oscuro del shell vive en `app.css` (`data-app-theme`) y no afecta al login.
- "Recordar sesión" recuerda el último correo y el último Workspace en cookies cifradas y HttpOnly (`App\Services\RememberedAccess`, `AUTH_REMEMBERED_ACCESS_MINUTES`), nunca la contraseña ni en tabla. El Workspace recordado se revalida en el servidor cada vez (existe, está activo, su Organization está activa) y la membresía del usuario se sigue comprobando en el login y en `EnsureWorkspaceContext`; si ya no es válido se descarta. "Cambiar" en el login olvida el Workspace; entrar sin marcar la casilla borra ambos recuerdos.
- Los usuarios de ejemplo con contraseña conocida (`RolesAndPermissionsSeeder`) solo se crean en `local` y `testing`.
- El administrador inicial se crea con `AdminUserSeeder` a partir de `ADMIN_EMAIL`, `ADMIN_PASSWORD` y `ADMIN_NAME`. Nunca escribas credenciales en el código ni en documentos.

## 8. Protección contra regresiones

Nunca arregles una cosa rompiendo otra. Una tarea no está terminada si algo que funcionaba dejó de funcionar.

- Antes de modificar: identifica quién consume el código (controladores, páginas, componentes, middleware, jobs, seeders, tests) y qué contratos comparte.
- Durante: haz el cambio mínimo y seguro. No cambies comportamiento global para resolver un caso local.
- Cuando toques algo compartido (layouts, componentes, hooks, middleware, validaciones, configuración, modelos), revisa todos sus consumidores.
- Al corregir un bug, escribe primero un test que lo reproduzca, luego la corrección, y verifica que pase junto con los existentes.
- Si tocas migraciones, modelos o consultas, comprueba que sigan funcionando en PostgreSQL y respetando el Workspace.

## 9. Validaciones reales del repositorio

Ejecuta las que correspondan al cambio y repórtalas:

- `php artisan test` (en host o con `make test`, que fuerza SQLite en memoria; nunca lo ejecutes contra la base de desarrollo: `tests/TestCase.php` lo bloquea si la base no es de pruebas).
- Validaciones en navegador: usa `make e2e-setup` y `make e2e-cleanup` (datos etiquetados `*@e2e.ccg.test`, `E2E_*`, `e2e_*`); nunca modifiques usuarios, membresías ni roles reales para probar, y ejecuta siempre la limpieza al terminar, aunque falle.
- `npm run build`.
- `make pint` (Pint). Hoy falla en 8 archivos antiguos: tu cambio no debe empeorar ese número, y los archivos que toques deben quedar limpios.
- No hay ESLint, Prettier ni tests de frontend todavía. Si la tarea los necesita, regístralo en `pendientes.md`.
- No desactives ni saltes tests para que pasen.

## 10. Docker local

- Usa la configuración existente: `docker-compose.yml`, `docker-compose.prod.yml` y el Makefile (`make up`, `down`, `logs`, `migrate`, `fresh`, `seed`, `test`, `pint`). En Windows sin `make`, usa los comandos `docker compose` equivalentes de `INFRASTRUCTURE.md`.
- No modifiques Dockerfile, compose, redes ni volúmenes salvo que la tarea lo pida.
- Cuando las validaciones pasen: reconstruye lo necesario, levanta los servicios, espera a que estén `healthy`, revisa los logs y comprueba la funcionalidad modificada y sus flujos relacionados.
- `make fresh` borra la base: solo en desarrollo.
- Nunca ejecutes `DatabaseSeeder` en producción.

## 11. Seguridad

- Nunca expongas credenciales ni las escribas en el repositorio. Secretos solo por variables de entorno.
- No elimines validaciones, autenticación ni autorización, ni desactives middleware de seguridad.
- Toda modificación debe mantener o mejorar la seguridad.
- `.env` no se versiona. `.env.example` sí, con valores de ejemplo y sin secretos.
- Workspace contextual: cada petición opera sobre el Workspace de la sesión, validado en `EnsureWorkspaceContext`; un cliente nunca ve ni elige otro (ni por URL, ni con `workspace_id`, ni por endpoints directos). `Desarrollo_DEV` (código `DESARROLLO_DEV`, `config/workspace.php` `admin_code`, variable `WORKSPACE_ADMIN_CODE`) es el Workspace administrativo interno de Ava Platform: se identifica por código, no por id, no se puede desactivar, ni cambiarle el código, ni desactivar su organización. Solo un superusuario que trabaja DENTRO de ese Workspace puede ver todos los Workspaces o elegir uno (Reportes y Auditoría, parámetro `?workspace=all|<id>`); `App\Services\WorkspaceScope` es el único sitio que decide, responde 403 a cualquier otro que lo pida y 404 si el Workspace no existe. Un superusuario dentro de un Workspace cliente queda limitado a él.
- Selector de Workspace: `Components/WorkspaceCombobox` busca en el servidor (`workspaces.search`, 10 resultados y `hasMore`), nunca carga la lista entera. `purpose=view` (elegir qué se consulta; solo quien puede elegir ámbito) y `purpose=assign` (dónde se puede colocar gente, con los roles asignables; exige `manage-users`). Se reutiliza en Reportes, Auditoría y Usuarios y Roles; la selección siempre se vuelve a validar en el backend.
- Reportes: exige `view-dashboard` y parte siempre del Workspace de la sesión. Nunca muestra datos inventados: una métrica sin fuente (`config/reports.php`, `source` nulo) llega con `value: null` y la interfaz dice "Sin datos todavía". El periodo y la granularidad salen de `App\Reports\ReportPeriod` (zona horaria del Workspace) y los gráficos son SVG propios en `Components/Charts`, sin librería de gráficos.
- Auditoría: todo cambio administrativo (crear, modificar, eliminar) se registra en `audit_logs` por `App\Audit\AuditLogger`: los modelos usan el trait `Audited` (campos declarados, credenciales enmascaradas con `Masked`) y lo que no es un modelo lo registra el controlador. Se guarda solo la diferencia relevante, el usuario y el Workspace (copiados por nombre), y la IP; nunca navegador, contraseñas, tokens ni secretos. Los registros son de solo añadir y la consulta parte siempre del Workspace de la sesión (solo un superusuario puede ampliarla). Un módulo o acción administrativa nueva no está terminada sin su auditoría.
- Integraciones: pertenecen a un Workspace y toda consulta parte de `$workspace->integrations()`. Las credenciales se cifran (`encrypted:array`), son de solo escritura (el frontend recibe solo "guardada") y nunca se serializan, se registran en logs ni se flashean. Las conexiones salientes pasan por `SafeHttpTarget` (sin IPs internas/reservadas, sin redirecciones). Se desactivan, no se borran; las gobierna `manage-settings`. Un tipo nuevo se añade en `config/integrations.php`, sin lógica por proveedor en la página.
- Integraciones por Workspace (decisión permanente): el catálogo es GLOBAL y limpio (tipos en `config/integrations.php`; canales en `config/chatbots.php`: Web, WhatsApp, Instagram, Messenger) y la configuración concreta es una fila de `integrations` que pertenece a UN Workspace (`Integration` hace de WorkspaceIntegration). Nunca hay una entrada de catálogo por Workspace. Un tipo que usa un canal (`whatsapp`) se configura desde las tarjetas de canal (`PUT /integrations/channels/{channel}`, una por canal y Workspace, nombre = etiqueta del canal) y no aparece en el formulario genérico. Un canal solo se marca `available` cuando funciona de punta a punta; hoy solo WhatsApp se puede configurar (Web, Instagram y Messenger se muestran "No disponible todavía"). Un superusuario dentro de `Desarrollo_DEV` puede MIRAR (solo lectura) las integraciones o chatbots de UN Workspace con `?workspace=<id>` (`WorkspaceScope::resolveOne`: "all" no existe aquí, 404); toda escritura se aplica siempre al Workspace de la sesión. Un tipo implementa `supportsTest()` y `auditValues()`; el que no puede probar la conexión lo dice, nunca simula un resultado.
- Chatbots: pertenecen a un Workspace (`$workspace->chatbots()`), permisos `view-chatbots` (ver) y `manage-chatbots` (crear, editar, activar/desactivar y canales); configurar las credenciales del canal sigue siendo `manage-settings` (Integraciones). Se desactivan, no se borran. Un canal del chatbot (`chatbot_channels`) NO guarda credenciales: mientras está activo apunta a la integración del propio Workspace, que el servidor resuelve (el cliente nunca envía ese id). Claves foráneas compuestas `(chatbot_id, workspace_id)` y `(integration_id, workspace_id)` impiden a nivel de base de datos cruzar Workspaces, y `integration_id` es único: una cuenta (un número de WhatsApp) responde por un solo chatbot. El avatar del chatbot (PNG/JPG/WebP, 1 MB, nunca SVG) sale por `/chatbots/{id}/avatar` con `PrivateImage`, igual que el logo. Los colores no se duplican: el chatbot usa la identidad de Configuraciones.
- Ava, n8n y la IA (decisión permanente): Ava es la ÚNICA fuente del comportamiento de un chatbot; n8n no guarda otro prompt. n8n lee la configuración con `GET /api/agent/config` y el token del chatbot (`Authorization: Bearer ava_...`, `AgentAccess`: solo se guarda el SHA-256, se muestra una sola vez al generarlo, se rota o se revoca desde "IA y comportamiento"). El token identifica al chatbot y por él al Workspace: ningún parámetro, header ni cuerpo del llamante puede cambiarlo, y devuelve solo nombre, descripción, instrucciones, Workspace y los canales ACTIVOS con identificadores no secretos (nunca credenciales). La API de n8n (`X-N8N-API-KEY`) no hace falta para que un trigger funcione, pero Ava SÍ guarda por Workspace la dirección de la instancia y una API Key (integración `n8n`, ver abajo) para poder verificar la conexión Ava -> n8n; esa credencial no es el token del chatbot (n8n -> Ava) y nunca se mezclan. Las credenciales que el WhatsApp Trigger (OAuth de la app de Meta) y el envío (token y ID de cuenta) necesitan viven DENTRO de n8n; el token que Ava guarda de WhatsApp solo se usa para verificar el número con Meta (`WhatsAppType::test`, lectura del número en la Graph API, versión en `config/integrations.php`) y no sale de Ava. Ava no ejecuta modelos ni guarda mensajes.
- Separación de responsabilidades del módulo Chatbots (decisión permanente de arquitectura y UX): GENERAL = presentación (la primera vez pide la identidad: avatar, nombre y descripción; guardada (`chatbots.profile_saved_at`), muestra una vista previa del chatbot con sus canales reales, no un formulario); IA Y COMPORTAMIENTO = edición posterior (identidad, instrucciones y token de acceso de n8n, con un único formulario compartido); INTEGRACIONES = conexiones (n8n, y las cuentas de cada canal); el CANAL (hoy WhatsApp, `Conversations/Index`) = operación (conversaciones y mensajes, solo lectura) con la configuración técnica relegada a su pestaña "Conexión". Un canal solo aparece como activo en la vista previa si está encendido, y su verificación se muestra tal cual (sin verificar / verificado / error).
- Conversaciones (decisión permanente): Ava no habla con WhatsApp, lo hace n8n, que REPORTA cada mensaje y cada estado de entrega a Ava (`POST /api/agent/messages` y `/messages/status`, mismo token del chatbot, solo para un canal que el chatbot tiene encendido y que declara `conversations` en `config/chatbots.php`). Se guarda en `conversations` y `messages` (texto, tipo, hora, estado, `external_id` para que un reintento no duplique); no se descarga audio, imágenes ni archivos. Cada fila lleva su Workspace y claves foráneas compuestas impiden cruzarlos. Leerlas exige el permiso propio `view-conversations` (tienen datos personales de los contactos; solo `admin` lo tiene por defecto) y la página busca siempre dentro del Workspace mostrado: un id de otro Workspace, chatbot o canal es 404. No hay política de retención todavía.
- n8n en Integraciones (decisión permanente): son dos conexiones separadas que nunca se confunden. (1) Ava -> n8n: el tipo de integración `n8n` (`N8nType`, una por Workspace, `config/integrations.php` `dedicated`, fuera del formulario genérico) guarda la dirección de la instancia (reducida a `scheme://host[:puerto]`) y la API Key cifrada y de solo escritura; "Probar conexión" hace una solicitud REAL de solo lectura a `GET /api/v1/workflows?limit=1` con `X-N8N-API-KEY` (pasa por `SafeHttpTarget`) y solo un 200 con forma de API de n8n la da por buena; los mensajes de error son fijos y no repiten secretos ni respuestas. Estados: sin configurar, sin probar, conectado (última prueba correcta) y error de conexión: guardar datos nunca es "conectado". "Desconectar" borra la integración y con ella la API Key (excepción a "se desactivan, no se borran": una credencial desconectada no se conserva) y queda en la auditoría. (2) n8n -> Ava: el token de cada chatbot (solo hash; se muestra una vez al generarlo, también desde la tarjeta), las direcciones `/api/agent/config` y `/api/agent/messages` y `chatbots.agent_last_seen_at` (la última vez que n8n leyó la configuración o reportó un mensaje, sin tocar `updated_at` ni auditar). Ninguna de las dos conexiones implica que WhatsApp o la IA funcionen, ni al revés: WhatsApp se configura aparte y sus conversaciones salen solo de los mensajes que n8n reporta de verdad.
- Estados que no se inventan: "Configurado" (datos guardados) no es "Conexión verificada" (una prueba real devolvió OK) ni significa que lleguen o salgan mensajes. Un tipo de integración declara `supportsTest()`; si no puede probar (el widget web: probarlo ejecutaría el workflow), la interfaz no ofrece la prueba. Editar la configuración borra el último resultado de la prueba.
- Canal Web: un tipo de integración `web` por Workspace (URL del webhook de n8n, secreto compartido opcional enviado en `X-Ava-Secret`, sitios permitidos) y una clave pública por canal de chatbot (`chatbot_channels.public_key`, no secreta, estable al desactivar). Rutas públicas `/api/widget/{clave}/config|avatar|messages` (sin sesión, CORS abierto, con límites por IP y widget): la clave es lo único que elige el visitante, y todo lo demás (chatbot, Workspace, webhook) lo resuelve el servidor; si algo está apagado (canal, chatbot, integración, Workspace u organización) la clave no existe (404). Los sitios permitidos limitan solo a los navegadores (el header Origin lo puede falsificar un cliente que no lo sea). El webhook se llama con `SafeHttpTarget` (sin IPs internas). El widget usa la apariencia de Configuraciones y el avatar del chatbot. Un tipo de canal describe su propio formulario (`ChannelIntegrationType::fields()`): la página no tiene lógica por proveedor.

- Canales y apariencia (decisión permanente): la apariencia del botón/widget de cada canal es presentación pura y vive en `chatbot_channel_appearances` (Workspace + chatbot + canal, clave foránea compuesta; nunca credenciales), separada de `chatbot_channels` (estado y cuenta). `ChannelAppearanceService` es el único dueño del contrato (ajustes por tipo `widget`/`button` en `config/chatbots.php`, defaults, validación con contraste, qué recibe el público). El script público es siempre el mismo y pide `/api/widget/{clave}/config` en cada carga; esa respuesta solo lleva presentación y el enlace público `wa.me` (nunca ids, Workspace ni secretos), y la clave no existe si el canal, el chatbot, la integración, el botón (`enabled`) o el destino están apagados. El botón de WhatsApp solo existe con un número verificado por una prueba real con Meta (`display_number` en la config de la integración). La vista previa de la interfaz usa el mismo script (`window.AvaWidget.mount`, modo preview): no hay un segundo renderizador.

## 12. Rendimiento

- Consultas eficientes, evitar N+1, eager loading, índices adecuados.
- Tareas pesadas en colas (Horizon), no en la petición.
- Evitar renders innecesarios en React y memoizar solo cuando aporte valor.
- Los archivos subidos van a S3/MinIO, nunca al disco del contenedor.

## 13. Git

- No hagas commit, push ni Pull Requests salvo que la tarea lo pida de forma explícita.

## 14. Flujo obligatorio por tarea

1. Leer `reglas.md` y `pendientes.md`.
2. Entender el requerimiento y analizar el código relacionado.
3. Buscar si ya existe algo reutilizable antes de crear algo nuevo.
4. Identificar dependencias, riesgos y regresiones posibles.
5. Implementar el cambio mínimo, limpio y reutilizable.
6. Ejecutar pruebas y validaciones, y corregir hasta que pasen.
7. Probar la pantalla en móvil, tablet y escritorio si hay cambios visuales.
8. Actualizar Docker local y verificar servicios, logs y funcionalidad.
9. Actualizar `pendientes.md` con lo que surgió o se completó.
10. Entregar el reporte final.

## 15. Reporte final

- Cambios realizados: archivos, componentes y servicios modificados.
- Qué se reutilizó y qué piezas compartidas se crearon.
- Validaciones ejecutadas con su resultado.
- Errores encontrados y cómo se corrigieron.
- Riesgos y recomendaciones.

## 16. Criterio de finalización

La tarea está terminada solo si: el requerimiento está completo, no hay duplicación, el código es limpio, las pruebas y el build pasan, no hay regresiones, la interfaz funciona en móvil, tablet y escritorio, Docker funciona, no hay errores relevantes en los logs y se entregó el reporte. Si falta algo, no está terminada.

## 17. Deuda técnica conocida (para no agravarla)

- Hay tres sistemas de estilos (Tailwind compilado, Tailwind por CDN y CSS propio en Blade) y dos claves distintas de tema en `localStorage`. Todo estilo nuevo va al sistema de tokens de Tailwind 4.
- Hay vistas y páginas sin ruta: `login.blade.php`, `welcome.blade.php`, `layouts/app.blade.php`, `Pages/Welcome.jsx`, `Auth/Login.jsx`, `Auth/Register.jsx`, y dos copias del HTML de referencia. No las uses como base.
- `AppLayout.jsx` y `AuthenticatedLayout.jsx` conviven; `Hooks/` y `Services/` están vacíos; `tailwind.config.js` está huérfano.
- Sin capa de servicios ni acciones: la lógica nueva no debe crecer dentro de los controladores.
- `composer.json` pide PHP ^8.3 pero el lock exige 8.4.1 o más.
- La imagen de MinIO es solo para desarrollo.
- Detalle y estado de cada punto en `pendientes.md`.