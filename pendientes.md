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
- [ ] **Seeders de ejemplo:** `RolesAndPermissionsSeeder` crea usuarios con contraseñas débiles (`daniel@gmail.com`...); separar lo de desarrollo de lo de producción.
- [ ] **Valores por defecto de desarrollo en `docker-compose.yml`** (`DB_PASSWORD=secret`, `minioadmin`, `REVERB_APP_*`): decidir si se exigen desde `.env`.
- [ ] **Imagen de MinIO:** `bitnamilegacy/minio` está congelada; valorar alternativa si se quiere consola web o parches.
- [ ] **Traducciones:** no hay `lang/es`; los mensajes de autenticación salen en inglés.
- [ ] **Recordar sesión:** la cookie `remember` no recuerda el Workspace, así que no evita pasar por `/pre-login`.
- [ ] **Módulos de la referencia sin construir:** Productos, Combos, Cupones, Clientes, Pedidos, Domicilios, Sucursales y Caja (secciones DEMO/ADMIN/OPERACIONES de la imagen). Fuera de alcance por ahora; el menú vive en `resources/js/config/navigation.js`.
- [ ] **Chat de Ava:** el botón flotante (`ChatButton`) es solo visual; falta la lógica (Reverb + Echo).
- [ ] **Contexto ITBIS / moneda:** el chip "ITBIS 18%" y el selector RD$ DOP de la referencia se dejaron fuera a propósito; añadirlos si hacen falta.
- [ ] **Configuraciones e Integraciones:** páginas base vacías; definir su contenido real.
- [ ] **Layouts sin uso:** `AuthenticatedLayout.jsx` y `BackButton.jsx` ya no los usa ninguna página; borrarlos con tu visto bueno.
- [ ] **Estilo antiguo en Auth y Welcome:** las páginas de recuperación y verificación (`GuestLayout`) y `Welcome.jsx` siguen con el estilo anterior.
- [ ] **Tests de frontend:** no hay; la nueva interfaz solo está cubierta por tests de rutas, roles y props (`NavigationPagesTest`).
- [ ] **Alta de usuarios por invitación o autoregistro:** `/register` se cerró (no existe registro público). Si el negocio quiere que alguien se dé de alta solo, hay que decidir el mecanismo (invitación con token por Workspace, o aprobación de un admin); el Workspace elegido en el pre-login no autoriza por sí mismo.
- [ ] **Menú por permisos:** el backend autoriza por permisos del rol (`workspace.permission`), pero `config/navigation.js` y `AppLayout` aún filtran el menú por rol `admin`; un rol personalizado con `manage-users` accede a `/users` pero no ve el enlace.
- [ ] **Referencias a `route('register')` en archivos huérfanos:** `Pages/Welcome.jsx`, `views/login.blade.php`, `views/welcome.blade.php` y `auth/login.blade.php` (texto del enlace); ninguno es alcanzable. El enlace "Regístrese aquí" del login es inerte y ya no tiene destino.
- [ ] **Vite del contenedor `node` no ve cambios de archivos en Windows:** tras editar `.jsx` hay que ejecutar `docker compose restart node` (o activar polling en `vite.config.js`, que es configuración de infraestructura).
- [ ] **Unicidad del email entre Workspaces:** crear un usuario con un email que ya existe en otro Workspace falla con el error global de `unique:users` (revela que la cuenta existe). Valorar un flujo de "añadir cuenta existente" para superusuarios.
- [ ] **Permisos por Workspace:** el catálogo de roles/permisos es global; permisos distintos por Workspace requieren Spatie `teams` (también en Ideas).
- [ ] **Alinear el resto de páginas al sistema visual:** Reportes, Configuraciones, Integraciones y Perfil ya usan `AppLayout` y los componentes compartidos, pero no se revisaron una a una; recuperación de contraseña, verificación de correo y `Welcome.jsx` siguen con el estilo anterior.
- [ ] **Paginación de otros listados y filtros por rol:** solo `/users` tiene búsqueda y paginación; añadir filtro por rol si hace falta.
- [ ] **Tests de interfaz:** la validación visual y de pestañas se hizo a mano con Playwright; no hay tests automáticos de frontend.
- [ ] **Membresías de `DESARROLLO_DEV` que desaparecieron sin causa identificada:** en dos ocasiones faltaban filas de `workspace_user` de usuarios sembrados y se restauraron con `WorkspaceSeeder`. Antes de añadir el guard, un `php artisan test` ejecutado dentro del contenedor sí podía vaciar la base de desarrollo (`RefreshDatabase`), pero no se pudo demostrar que fuera la causa de estos casos concretos. Si vuelve a ocurrir, comparar con una instantánea previa.
- [ ] **Base de pruebas en PostgreSQL:** los tests usan SQLite en memoria; las consultas específicas de PostgreSQL no se ejercitan en CI. Si se quiere, crear una base `ccg_ava_test` (el guard ya la admite).
- [ ] **Validación de navegador no automatizada:** los pasos con Playwright se ejecutan a mano; el flujo `e2e:setup` / `e2e:cleanup` es manual (no hay un `finally` automático porque las herramientas de navegador no ejecutan Artisan).
- [ ] **Fuentes sin uso en `app.blade.php`:** Orbitron y Rajdhani ya no las usa el login; se siguen cargando desde Google Fonts. Quitarlas cuando se confirme que ninguna página antigua (Welcome, recuperación de contraseña) las necesita.
- [ ] TODO: añadir aquí las tareas de producto.

## En curso

- [ ] TODO: nada en curso.

## Hecho

- [x] **Acceso por Workspace:** pre-login, login con diseño aprobado, middleware de contexto y rol por Workspace, superusuarios, tests.
- [x] **Protección de cuentas compartidas:** un admin de Workspace no puede cambiar nombre/email de cuentas con acceso a otros Workspaces ni de superusuarios; throttle del login independiente del Workspace.
- [x] **Dockerización:** imagen multi-stage única (app, worker, scheduler, reverb), compose de desarrollo y de producción, Postgres, Redis, MinIO, Vite, healthchecks, Makefile, `INFRASTRUCTURE.md`.
- [x] **Auditoría de infraestructura:** `league/commonmark` actualizado (avisos de seguridad), healthchecks corregidos, mantenimiento compartido entre réplicas, proxy con balanceo y websockets, workers sin root en producción, 4 tests arreglados (suite completa verde).
- [x] **Migración a PostgreSQL:** sin MySQL/MariaDB en la configuración, `DB_CONNECTION=pgsql` por defecto, migraciones verificadas en PostgreSQL 16.
- [x] **Layout y páginas principales de Ava:** `AppLayout` único (barra superior, barra lateral y contenido) con la identidad de la referencia, tokens `@theme` en `app.css`, menú en `config/navigation.js`, componentes compartidos, `lucide-react` como librería de iconos, y las páginas Reportes (inicio, datos reales del Workspace), Configuraciones, Integraciones y Usuarios y Roles. Movimiento discreto con `prefers-reduced-motion`.
- [x] **Login alineado con la estética de Ava:** `ava.css` deriva sus tokens de los del dashboard (`--color-primary`, `--color-surface-*`, `--color-ink`, `--color-line`, `--radius-card`, sombras, Inter). Card con radio y sombra de la app, botón primario azul sólido, controles con foco y error visibles, marca hexagonal sobre la superficie azul del sidebar; modo claro con el mismo fondo claro y modo oscuro con el mismo fondo oscuro y la card en el azul marino del shell. Partículas y esferas conservan geometría, cantidad y movimiento; solo cambian de color a la paleta azul/violeta de la app. Verificado en claro/oscuro, escritorio y móvil, sin errores de consola, sin datos residuales.
- [x] **Continuidad visual sidebar/topbar:** ambas superficies comparten familia de azules (`--color-surface-*` y `.brand-surface`), sin transición a blanco, líneas geométricas más tenues y resplandor casi imperceptible; el menú de usuario ya no queda recortado por la barra superior. Verificado en escritorio y móvil.
- [x] **Aislamiento y limpieza de datos de prueba:** PHPUnit usa SQLite en memoria (nada persiste) y `TestDatabaseGuard` impide que un test toque la base de desarrollo; las validaciones en navegador usan datos etiquetados (`e2e:setup` / `e2e:cleanup`, `make e2e-setup` / `make e2e-cleanup`) y la limpieza, probada tras un fallo controlado, dejó la base de desarrollo idéntica a la inicial (comparación por hash de usuarios, Workspaces, membresías, roles y permisos).
- [x] **Rediseño de Usuarios y Roles y sistema visual del dashboard:** `/users` pasa a un módulo con tres pestañas accesibles (Usuarios con búsqueda y paginación en servidor, Roles, Permisos en matriz de solo lectura) que renderizan solo la pestaña activa y guardan la pestaña en la URL. Paleta sobria basada en azules con tokens en `app.css` (sin colores sueltos), barra lateral en degradado azul claro-profundo-casi blanco con textura y movimiento sutil (`prefers-reduced-motion` respetado), barra superior con degradado y trazos finos, contenido más ancho (`max-w-screen-2xl`). Componentes compartidos nuevos: `Tabs`, `SearchInput`, `Pagination`, `Alert`, `FlashAlert`, `Table`; mensajes de éxito del servidor (`flash.success`). `Modal` y botones/campos alineados a los tokens.
- [x] **Usuarios, registro y roles:** `/register` cerrado (ningún usuario sin Workspace ni rol elegido por el cliente); aviso previo en `/users` cuando una cuenta compartida o superusuario no admite cambio de nombre/correo (regla única en `UserIdentityGuard`, el backend sigue siendo quien la aplica); roles: `workspace_user.role` asigna, Spatie `roles`/`permissions` son el catálogo global, rutas autorizadas por permisos (`workspace.permission`), solo superusuarios crean/editan/eliminan roles (rol `admin` protegido, roles en uso no se borran), asignación sin escalada de privilegios, sin roles Spatie globales por usuario. Tests en `UserRoleManagementTest`.
- [x] **Administrador inicial:** `AdminUserSeeder` idempotente (`ADMIN_EMAIL` / `ADMIN_PASSWORD`), rol `admin` con todos los permisos, superusuario, protegido en producción.

## Ideas

- [ ] Chat en tiempo real sobre Reverb (infraestructura lista, falta el frontend con Echo).
- [ ] Panel de administración de Organizations y Workspaces.
- [ ] Selector de Workspace para superusuarios sin cerrar sesión.
- [ ] Spatie `teams` para permisos por Workspace.
- [ ] CI (tests + Pint + `composer audit`) en cada push.
- [ ] TODO: más ideas.
