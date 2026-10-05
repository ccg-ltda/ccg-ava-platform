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
- Auditoría de tablas (según el código y la configuración reales): `users` (identidad global), `password_reset_tokens` (broker de contraseñas de Laravel, `config/auth.php`), `failed_jobs` (`queue.failed.driver=database-uuids` en todos los entornos, también con Redis/Horizon) y las de Ava/Spatie se usan siempre. `sessions`, `cache`, `cache_locks` y `jobs` solo se usan cuando el driver es `database`: es el valor por defecto del framework y el que usa el `.env` de desarrollo en el host (`composer run dev`); Docker y `.env.example` usan Redis y no las tocan. Se conservan porque la configuración actual las necesita y porque cambiar los drivers para quitarlas no estaba autorizado; `cache_locks` es la tabla de bloqueos de ese mismo store. Spatie: se conservan las cinco (`model_has_roles` y `model_has_permissions` no guardan datos nuevos porque los roles se asignan por Workspace, pero `HasRoles` y los modelos de Spatie las consultan, por ejemplo al borrar un usuario o un rol).
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