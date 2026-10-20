# Módulos, soluciones, navegación, planes y permisos en Ava Platform

Describe cómo encajan las cinco piezas que no deben confundirse y cómo se administran. Todo lo de aquí es **lo que el código hace hoy**, salvo lo marcado como propuesta. El detalle técnico vive en `reglas.md` (§7); este documento es el mapa.

## 1. Las cinco piezas

| # | Pieza | Qué es | Dónde vive | Quién la cambia | Qué NO es |
|---|---|---|---|---|---|
| 1 | **Módulo funcional** | Una capacidad implementada: tiene código, rutas, permisos y protección en el servidor. Identidad estable (`key`). | `config/modules.php` `implemented` + fila en `modules` | Un desarrollador (código) | No lo crea la interfaz: registrar un módulo desde Planes solo reserva su lugar y queda «Pendiente de implementación». |
| 2 | **Categoría / solución** | Clasificación **abierta** para organizar el catálogo (Servicios y Reservas, eCommerce, Salud, lo que venga). | tabla `module_categories` (se crean desde Planes) | CCG, sin código | No limita qué módulos se combinan ni decide acceso. El código de acceso nunca nombra un tipo de negocio (lo verifica una prueba). |
| 3 | **Grupo conceptual / de navegación** | Tres grupos de Ava (`groups`: Administración de la plataforma, Capacidades compartidas, Soluciones comerciales) y, aparte, las **secciones del sidebar**. | `config/modules.php` `groups` y `resources/js/config/navigation.js` | Desarrollador | No es código, tabla ni permiso. Un módulo sin grupo declarado toma el de su categoría y, si no, `default_group` (`solutions`). |
| 4 | **Plan comercial** | Combinación configurable de módulos habilitados para un Workspace cliente. | tablas `plans`, `module_plan`, `workspaces.plan_id` | CCG desde `Desarrollo_DEV` | No da permisos: decide si el módulo **existe** para el Workspace. |
| 5 | **Permiso del usuario** | Qué puede hacer una persona dentro de los módulos que el Workspace tiene (`view-customers`, `create-bookings`, `cancel-orders`...). | roles Spatie + `workspace_user.role` | Admins del Workspace (sin escalar) y superusuarios | No habilita un módulo que el plan no incluye. |

**Regla de oro:** disponibilidad (plan) ≠ autorización (rol). El servidor exige las dos en cada ruta (`workspace.module:<clave>` y luego `workspace.permission:<permiso>`); el menú solo refleja lo que el servidor ya impone, y ocultar una opción nunca sustituye a la protección.

### Sidebar: secciones, no módulos

El sidebar tiene **tres grupos plegables por función** y todos se pliegan por separado, también el de la página actual:

| Grupo | Entradas | Qué es |
|---|---|---|
| **General** | Dashboard, Asistentes, Conversaciones | Lo cotidiano del Workspace. |
| **Operación** | Reportes, Catálogo, Pedidos, Servicios, Sedes, Profesionales, Agenda, Clientes, Campañas | Los módulos funcionales reutilizables, sea cual sea la solución comercial que los ofrezca. Proveedores de agenda se configura en Integraciones y no tiene entrada propia. |
| **Administración** | Usuarios y Roles, Auditoría, Configuraciones, Integraciones, Planes (solo administración de plataforma; incluye el catálogo de módulos) | Administración interna. |

Una sección es presentación: reúne entradas relacionadas y desaparece si ninguna se ofrece. **Las soluciones comerciales no son secciones del menú** (son categorías del catálogo y planes): un módulo existe una sola vez y lo comparten todas las soluciones. Un módulo reutilizable nuevo = una entrada en Operación; una solución nueva (Salud, Delivery, eCommerce) = una categoría y planes, sin tocar el menú. No confundir estos grupos del MENÚ (por función) con los grupos conceptuales del CATÁLOGO (`config/modules.php` `groups`: administración, capacidades compartidas, soluciones comerciales), que organizan el constructor de planes. El catálogo completo **no** se convierte en entradas permanentes: el menú muestra, por Workspace, solo los módulos implementados + incluidos en su plan + autorizados al rol. En `Desarrollo_DEV` (todos los módulos implementados) los grupos empiezan plegados salvo el de la página actual, y el catálogo se explora en **Planes**, no en el menú. Cuatro piezas siguen separadas: el catálogo de capacidades implementadas (`config/modules.php implemented`), las soluciones o categorías comerciales (abiertas, creadas desde Planes), los planes que combinan módulos, y los permisos de cada usuario; la disponibilidad de un módulo para un Workspace sale solo de su plan. Los módulos que faltan para Salud, Delivery y eCommerce NO existen todavía: sus categorías están vacías a propósito y se incorporarán siguiendo este mismo modelo (entrada en `implemented` + `workspace.module` + menú en Operación + sembrado).

### Qué no es un módulo contratable (rutas «core»)

Se declaran en `config/modules.php` `core_routes` y no aparecen en planes ni en el catálogo: perfil, cierre de sesión, verificación de correo y contraseña; el logo y el selector de Workspace; los filtros guardados (cada filtro comprueba el módulo de su `scope`); y la administración de plataforma (Planes, catálogo, acceso de soporte), ligada a `Desarrollo_DEV` por permiso. Una prueba (`ModuleArchitectureTest`) falla si una ruta con sesión no está detrás de `workspace.module` ni declarada ahí.

**No confundir con el «plan por defecto».** «Plataforma completa» (`default_plan`) es un plan contratable que solo contiene los ocho módulos compartidos y es el que usan los Workspaces sin plan asignado; los módulos nuevos (Clientes, Campañas, Servicios y Reservas) no entran en él salvo decisión explícita. Hay un riesgo anotado en `pendientes.md`: nada impide un plan sin `users_roles` o `settings`, que dejaría al cliente sin poder administrarse.

## 2. Mapa real de módulos y dependencias

Dependencias **técnicas** declaradas (`config/modules.php` `dependencies`), comprobadas contra el código; las inversas no se declaran:

| Módulo | Grupo | Necesita | Observación |
|---|---|---|---|
| `dashboard`, `reports` | Compartidas | — | Solo calculan lo que el plan incluye (`null`, no cero, para lo no contratado). |
| `conversations` | Compartidas | — | Sirve sola como «plan de consulta» (leer, tomar, resolver; sin enviar ni devolver a la IA). |
| `assistants` | Compartidas | `conversations` | La API del agente, el widget, el webhook y el envío necesitan ambos. |
| `customers` | Compartidas | — | Ficha por Workspace; **independiente** de reservas y campañas. No es un CRM. |
| `campaigns` | Compartidas | `customers` | Una campaña se dirige a clientes: sin ellos no existe. |
| `service_catalog` | Soluciones | — | Reutilizable por cualquier negocio con citas (no menciona uñas ni salones). |
| `locations` | Soluciones | — | Sedes opcionales (también para Salud). Sin sedes la agenda funciona igual; con ellas, cada reserva elige una (reglas en `RECOMENDACIONES.md`, «Sedes, precio y origen»). Solo aplican a la agenda de Ava, no a una externa. |
| `product_catalog` | Soluciones (eCommerce, también Delivery) | — | Productos con SKU, precio, categoría y sedes que los ofrecen. Nunca se borran. NO es `service_catalog` (los servicios se reservan; los productos se piden). |
| `orders` | Soluciones (eCommerce, también Delivery) | `product_catalog` | Pedidos con líneas copiadas del catálogo, total calculado por el servidor, estados y trazabilidad. Registra el pedido: sin pagos, sin avisos al cliente todavía. |
| `professionals` | Soluciones | `service_catalog` | Quien presta servicios, horarios (por sede, si se quiere) y días no laborables. |
| `bookings` | Soluciones | `service_catalog`, `professionals` | Agenda, citas, página pública y herramientas de Ava Chat. Guarda su propio contacto mínimo (`booking_customers`): **no necesita `customers`** (comprobado: ningún flujo de reservas lo lee). |
| `calendar_providers` | Soluciones | `bookings`, `integrations` | Agenda externa (Booker) como fuente de verdad. |
| `users_roles`, `audit`, `settings`, `integrations` | Administración | — | Del propio Workspace (no confundir con la administración de CCG). |

Clasificación según el código: **capacidades independientes** = `conversations`, `customers`, `dashboard`, `reports`, `service_catalog`, `locations`, `product_catalog`; **compartidas** (las usan varias soluciones) = asistentes, conversaciones, clientes, campañas, panel, reportes y, por diseño, `service_catalog`/`professionals` para Salud y otros; **dependientes** = `assistants`, `campaigns`, `professionals`, `bookings`, `calendar_providers`.

### Cómo se relacionan Clientes y Campañas con el resto (sin copiar datos)

- **Clientes ↔ Conversaciones:** por los identificadores de canal que se guardan en la ficha (`customer_identities`, igual al `contact_id` de la conversación). Solo se muestra si el plan tiene `conversations` y el rol `view-conversations`.
- **Clientes ↔ Reservas:** por el mismo teléfono o correo normalizado de `booking_customers`, o por una cita creada desde una conversación del cliente. Solo con `bookings` y `view-bookings`. No se guarda el vínculo ni se duplica el contacto.
- **Clientes ↔ Campañas:** `campaign_recipients` (una fila por cliente de una campaña preparada). La ficha lista sus campañas solo con `campaigns` y `view-campaigns`.
- **Si el plan no incluye Clientes:** `/customers` responde 403 y el menú no lo ofrece; **Campañas tampoco es operativa** (dependencia) aunque esté en el plan; reservas y conversaciones siguen funcionando igual (no dependen de Clientes).
- **Si no incluye Campañas:** Clientes funciona y la ficha no muestra la sección de campañas (`null`). **Si no incluye Conversaciones o Reservas:** la ficha omite esas secciones, no falla.
- Un plan guardado antes de declarar una dependencia puede quedar incompleto: aparece marcado «Dependencias incompletas», el módulo dependiente no se ofrece y **no se puede asignar** a un Workspace hasta completarlo.

## 3. Cómo se administran los planes (flujo)

Todo en **Planes** (`/plans`), solo dentro de `Desarrollo_DEV` y con el permiso de cada operación (`view-plans`, `create-plans`, `edit-plans`, `activate-plans`, `assign-plans`; `view-modules`, `create-modules`, `edit-modules`, `activate-modules`) o siendo la administración interna de la plataforma (superusuario con rol `admin` ahí, que los tiene todos; ver README). Cada operación tiene su permiso independiente: poder consultar no permite crear, ni crear editar. Un superusuario de un Workspace cliente no accede (ni siquiera puede entrar a un Workspace cliente sin membresía explícita). Un cliente no ve el catálogo comercial, no elige ni cambia su plan, ni se asigna módulos: lo comprueba el servidor en cada ruta y acción (`workspace.platform`), no solo el menú. Quién puede qué:

| Acción | Quién | Dónde |
|---|---|---|
| Consultar planes y catálogo; crear, editar, activar/desactivar un plan y definir sus módulos; asignar un plan a un Workspace | `view-plans`, `create-plans`, `edit-plans`, `activate-plans`, `assign-plans` (o la administración interna) | solo `Desarrollo_DEV` |
| Registrar módulos y categorías | `view-modules`, `create-modules`, `edit-modules`, `activate-modules` (o la administración interna) | solo `Desarrollo_DEV` |
| Ver los módulos disponibles de su propio Workspace | cualquier usuario, solo a través del menú y de lo que su plan incluye | su Workspace |
| Cambiar el plan, el catálogo o sus módulos | nadie en un Workspace cliente (403, aunque su rol traiga el permiso o sea superusuario) | — |

Los planes deciden qué módulos EXISTEN para un Workspace; los permisos del rol deciden qué acciones hace cada usuario dentro de ellos; ninguno sustituye al otro. **Excepción interna:** el Workspace administrativo (`Desarrollo_DEV`) tiene siempre todos los módulos implementados, sea cual sea su plan (`ModuleAccess::keys`), y la administración interna tiene todo el catálogo de permisos (`PlatformAccess`); ningún Workspace cliente recibe esa excepción.

1. **Consultar el catálogo.** Pestaña «Catálogo de módulos»: todos los módulos registrados, con su categoría, su **grupo** y su estado (*Implementado* o *Pendiente de implementación*) y lo que requieren.
2. **Organizar.** Crear categorías y registrar módulos futuros (reservan lugar; nunca habilitan nada). Nada se borra: se desactiva.
3. **Crear un plan.** «Nuevo plan» o la ficha de un plan: casillas de módulos agrupadas por **grupo de Ava y categoría**, mezclando libremente categorías. Los módulos pendientes pueden incluirse (quedan reservados) pero se etiquetan.
4. **Ver dependencias.** Cada módulo muestra «Requiere…»; un aviso explica qué falta y ofrece «Añadir». El servidor rechaza guardar un plan que incumple una dependencia.
5. **Asignar a un Workspace.** Desde la ficha del plan (o el Workspace): un Workspace cliente tiene **un** plan. Se rechazan planes desactivados y, ahora también, **planes incompletos**. `Desarrollo_DEV` no admite plan.
6. **Auditoría.** Todo cambio (plan, módulos del plan con antes y después, asignación, catálogo) queda en la auditoría.
7. **Qué ve el cliente.** Solo lo que su plan incluye **y** está implementado **y** su rol permite; el resto responde 403 aunque conozca la URL.

### Ejemplos ilustrativos (NO son planes reales ni se han creado)

| Ejemplo | Módulos | Resultado |
|---|---|---|
| Consulta | `dashboard`, `conversations` | Leer y atender conversaciones; sin asistentes ni envío por IA. |
| Atención con IA | `dashboard`, `assistants`, `conversations`, `reports` | Asistentes con WhatsApp/web y analítica. |
| Agenda de servicios | `dashboard`, `service_catalog`, `professionals`, `bookings` | Reservas con agenda propia; sin Clientes ni Campañas. |
| Agenda + base de clientes | los de «Agenda de servicios» + `customers` | La ficha de cliente muestra sus reservas; sin envíos masivos. |
| Servicios con campañas | `dashboard`, `conversations`, `customers`, `campaigns`, `service_catalog`, `professionals`, `bookings` | Mezcla de tres categorías (compartida, comunicación, servicios); campañas con exclusión por consentimiento y simulación. |
| Salud (agenda de citas) | `service_catalog`, `locations`, `professionals`, `bookings` (+ `customers`) | Una clínica con varias sedes: servicios por sede, horarios del profesional por sede, reserva con sede, precio registrado y origen acreditado. **Sin datos clínicos** (D-07 pendiente). |
| Salud (módulos clínicos) | — | No existen: la categoría `health` sigue vacía a propósito hasta resolver D-07. |

### Cómo se registra y se habilita un módulo (y cómo lo ve Desarrollo_DEV)

Un módulo es utilizable desde la pantalla solo cuando coinciden cinco cosas. Falta una y el módulo «existe» pero no se ve o no abre:

| # | Pieza | Dónde | Quién la crea en un entorno ya instalado |
|---|---|---|---|
| 1 | Código y rutas protegidas (`workspace.module:<clave>` + `workspace.permission:`) | `routes/web.php` | el despliegue del código |
| 2 | Entrada «implementado» (con `route`, `permission` y dependencias) | `config/modules.php` | el despliegue del código |
| 3 | Fila en el catálogo (la que listan Planes y los planes) | tabla `modules` | `php artisan db:seed --class=ModuleCatalogSeeder` (idempotente, nunca renombra) |
| 4 | Permisos | `config/permissions.php` + tabla `permissions` | `php artisan db:seed --class=RolesAndPermissionsSeeder` (solo `admin` los recibe; no toca otros roles) |
| 5 | Entrada de menú y de accesos rápidos | `resources/js/config/navigation.js` y `dashboard.js` | el despliegue del código (compilación) |

Además, sus migraciones (`php artisan migrate`). En Docker: `make dev-sync` ejecuta migrate, los dos sembradores y `integrations:diagnose` contra el contenedor `app` de la pila local y se niega a correr si ese contenedor no es `APP_ENV=local`. Todo lo que ejecuta es repetible: las migraciones aplicadas no se repiten, `ModuleCatalogSeeder` solo crea lo que falta y `RolesAndPermissionsSeeder` solo crea permisos que faltan y da a `admin` los de cliente (en `local` además deja existir las tres cuentas de muestra con `firstOrCreate`, sin cambiar las que ya existen). Nunca lo apuntes a producción o a un entorno compartido: ahí las migraciones y los sembradores se ejecutan a propósito, uno a uno (`make prod-migrate`).

**Desarrollo_DEV** es el Workspace de pruebas funcionales: al ser el Workspace administrativo (`workspace.admin_code`) recibe TODOS los módulos implementados sin plan (`ModuleAccess::keys`), así que no hace falta asignarle un plan ni copiar una lista de módulos: basta que el módulo esté en `implemented`. Lo que SÍ necesita es el paso 4 (el menú exige el permiso del rol) y las migraciones. Esa condición no se hereda: un cliente solo ve los módulos de su plan, un rol sin permiso no los abre ni siquiera en Desarrollo_DEV, y los planes comerciales no cambian solos (se editan desde Planes). `ModuleRegistrationTest` y la sección «Módulos y permisos» de `integrations:diagnose` vigilan que las cinco piezas coincidan.

### Inventario de Salud (verificado el 2026-10-18)

Salud no tiene módulos propios: se arma con los mismos módulos reutilizables (`also_for`). Estado de cada pieza, según el código y `PLAN_TRABAJO_AVACHAT.md` §8.1:

| Pieza (referencia del plan) | Estado | Notas |
|---|---|---|
| Clientes (`customers`) | Implementado y validado (pruebas, simulado) | Ficha comercial; NO es «Pacientes». Muestra sus reservas con sede y precio. Sin importación ni fusión de duplicados. |
| Servicios (`service_catalog`, S1) | Implementado y validado | Precio opcional con 2 decimales; el precio se copia a la cita al reservar. |
| Sedes (`locations`, S1) | Implementado y validado (pruebas y navegador); en desarrollo ya migrado y registrado, **pendiente de asignar a un plan** | Migración y catálogo aplicados en la base de desarrollo el 2026-10-18; ningún plan lo incluye aún (decisión de CCG qué plan es de Salud). `Desarrollo_DEV` lo tiene por ser administrativo. En un entorno sin migrar la pantalla abre y lo dice. |
| Profesionales (`professionals`, S2) | Implementado y validado | Especialidad como texto; horarios por sede (un tramo sin sede vale para todas). No es un registro de credenciales. |
| Disponibilidad y citas (`bookings`, S3 y S4) | Implementado y validado con la agenda propia; parcial con agenda externa | Concurrencia verificada en PostgreSQL. Booker (`calendar_providers`) solo lee y cancela: sin horarios, reserva ni reprogramación. |
| Origen y precio de la cita | Implementado y validado | El origen lo fija el servidor; sin tarifas por profesional ni sede. |
| S5 Confirmaciones y recordatorios (`bookings`) | Implementado (capacidad compartida de Servicios y Reservas); integración interna probada; **Meta real sin validar** | Se configura por Workspace en Agenda → Configuración y necesita Servicios y Reservas **e Integraciones** en el plan, una cuenta de WhatsApp activa, el scheduler (`appointments:notify`) y un worker. Migración `2026_10_19_100000…` preparada, no aplicada en desarrollo. Plantillas de Meta declaradas por el Workspace (D-19 sigue abierta: Ava no puede comprobar su aprobación). |
| S6 Atención a pacientes (FAQ y derivación) | Implementado parcialmente | Asistentes + Conversaciones con atención humana existen y se probaron simulados; falta la base de conocimiento (D-20), el workflow real de n8n y la decisión D-IA. Sin consejo médico. |
| Historia clínica, datos de pacientes | **No requerido: alcance cero** | Bloqueado por D-07; no existe ninguna tabla ni campo clínico. |
| Reserva temporal de un hueco (D-17), reglas de cancelación distintas por tipo (D-18) | Pendiente de decisión funcional | Hoy un único plazo `cancel_notice_hours` para cancelar y reprogramar. |
| Informes de citas en Dashboard y Reportes | Ausente, sin requisito aprobado | Documentado como límite en `pendientes.md`; no se inventó. |

Dependencias de lo que falta: S5 → S4 + WhatsApp real + plantillas (D-19); S6 → Asistentes + Conversaciones + n8n real + D-20; cualquier dato clínico → D-07 resuelto antes.

### E-commerce y Delivery: qué es común y qué es propio (2026-10-20)

| Nivel | Contenido |
|---|---|
| **Común (una sola implementación)** | Catálogo de productos, pedido, líneas con instantánea de precio, estados y transiciones, historial, permisos (`view-catalog`, `create/edit/activate-products`, `view-orders`, `view-order-details`, `create/edit/cancel/process-orders`), sedes opcionales como punto de operación y Clientes como vínculo opcional. |
| **Se configura por Workspace** | La moneda (ajuste del Workspace), los productos, qué sedes ofrecen cada producto y el rol de cada persona. |
| **Solo Delivery (hoy: datos; la lógica está pendiente)** | Dirección, ciudad, referencias y datos de quien recibe (campos del pedido `delivery`); pendientes: zonas de cobertura, tarifas, repartidor y seguimiento. |
| **Solo E-commerce (pendiente)** | Variantes, stock, transportadora, cambios y devoluciones, promociones. |
| **Todavía no existe en ninguna** | Pagos, descuentos, impuestos, costo de envío, avisos al cliente (`OrderNotifier` sin canal), integración con tiendas externas (D-25). |

Una empresa que contrata Salud y E-commerce tiene DOS Workspaces: el código no comparte filas entre ellos.

### Confirmaciones y recordatorios: qué es común, qué se configura y qué depende de la solución

| Nivel | Qué contiene |
|---|---|
| **Común (un solo código para Salud, salones y cualquier negocio con citas)** | Tabla `appointment_notifications`; planificador y envío (`AppointmentNotifier`); mensajes por WhatsApp (texto dentro de 24 h, plantilla fuera); estados `sent/delivered/read/failed/uncertain/skipped/cancelled`; confirmación del cliente que no cambia el estado de la reserva; endpoints del asistente y de la página pública; pantalla de configuración y seguimiento en la Agenda; comandos y diagnóstico. |
| **Por Workspace (columnas de `booking_settings`)** | Qué avisos se envían (recepción, confirmación, recordatorios), anticipaciones (hasta 3), cuenta de WhatsApp, texto propio y/o plantilla de Meta por tipo. Sin configurar no se envía nada; un Workspace nunca ve ni cambia la configuración de otro. |
| **Por solución comercial** | Nada en el código. Salud y un salón difieren solo en lo que cada Workspace configura (textos, anticipaciones, plantillas) y en los módulos de su plan (por ejemplo Sedes). No se hereda ninguna regla exclusiva de Salud. |
| **Depende del plan** | `bookings` e `integrations`; sin ellos la pantalla lo dice y no deja activar nada. Con una agenda externa no se envía nada. |

## 4. Criterios de aceptación (verificados por prueba)

- Un plan combina módulos de distintas categorías sin solución predeterminada (`ModuleArchitectureTest`).
- Dependencias: se validan al crear/modificar el plan y al aplicarlo; los módulos no operativos se descartan y no son accesibles por URL.
- Una categoría/módulo creados desde la interfaz no habilitan nada y caen en el grupo por defecto.
- Ninguna decisión de acceso nombra un tipo de negocio.
- Toda ruta con sesión está detrás de un módulo o declarada «core».
- `Desarrollo_DEV` conserva todos los módulos implementados sin plan; un superusuario de un cliente no recibe privilegios de plataforma.
- Sidebar: todas las secciones con título se pliegan, incluida la de la página actual; el título no cambia de alto ni el sidebar de ancho al plegar (`npm run test:js`: reglas de plegado `startsOpen`/`openAfterToggle`/`openAfterNavigation` y de maquetación, y revisión visual).
- Un superusuario solo es administrador de plataforma dentro de `Desarrollo_DEV` y no entra a un Workspace cliente sin membresía (`PlatformBoundaryTest`, `WorkspaceAccessTest`).

## 5. Permisos de usuario (qué puede hacer cada perfil)

El rol de la membresía es la unidad: sus permisos salen de la tabla de roles (catálogo global, solo lo edita un superusuario de `Desarrollo_DEV`). El catálogo legible está en `config/permissions.php` y se muestra agrupado en **Usuarios y roles** (casillas del rol, matriz «Permisos» y «Ver permisos» de cada persona). Los de **Usuarios**: `manage-users` abre el módulo; `create-users`, `edit-users`, `assign-roles` y `deactivate-users` autorizan cada acción; `export-audit` separa exportar la Auditoría. Reglas que no se saltan: solo se asignan roles con permisos que el actor ya posee, nadie cambia su propio rol, nada cruza Workspaces, y todo cambio queda auditado. Los permisos de plataforma (`manage-plans`, `manage-modules`, `access-private-data`) no hacen nada fuera de `Desarrollo_DEV`. Los usuarios de un cliente conservan su perfil y su contraseña (rutas de perfil, sin permiso) y por defecto no reciben ninguno de estos permisos salvo `admin`.
