# Plan de trabajo de AvaChat

Documento de planificación. Define las cuatro soluciones de AvaChat, los módulos de cada una, las funcionalidades de cada módulo y el orden exacto de desarrollo. No describe código implementado salvo donde se indica «Existe», y cada afirmación sobre el estado actual cita el archivo o la prueba que la respalda.

Este documento no reemplaza ni duplica `reglas.md` (reglas, decisiones permanentes y metodología) ni `pendientes.md` (tareas abiertas y hechas). Cuando un módulo de este plan se empiece a desarrollar, la metodología obligatoria es la de `reglas.md` §14, y las deudas o ideas que surjan se anotan en `pendientes.md`, no aquí. Los archivos del repositorio están en minúsculas (`reglas.md`, `pendientes.md`).

## 0. Cómo usar este documento

### Etiquetas de estado

Toda funcionalidad lleva una de estas etiquetas:

- **[Existe]**: está implementada en el repositorio y se indica dónde y con qué pruebas. «Existe» no significa «validado contra servicios reales».
- **[Parcial]**: existe una parte; se indica cuál.
- **[Propuesto]**: es el alcance inicial propuesto. No está implementado y puede cambiar.
- **[Decisión]**: requiere confirmación de negocio o de producto antes de implementarse. Está numerada (D-01, D-02...) y recogida en la sección 11.
- **[Verificar]**: depende de la documentación o de las capacidades de un tercero que no se han comprobado. No se asume nada sobre esa API.

### Fuentes revisadas

- Código: `routes/`, `app/`, `config/`, `database/migrations/`, `resources/js/`, `tests/`.
- Documentación del repositorio: `reglas.md`, `pendientes.md`, `README.md`, `INFRASTRUCTURE.md`. No existe un documento de decisiones aparte; las decisiones permanentes viven en `reglas.md`.
- Referencia funcional y visual: `docs/referencias/Suite AVA · Contact Center Grupo (2).html`. Es un prototipo con datos ficticios, interacciones simuladas y contradicciones internas: no es una decisión comercial aprobada ni una plantilla para reemplazar la interfaz. Se usa solo como inspiración de contenido y de experiencia.
- El caso del salón de uñas y Booker lo aporta el encargo de este plan. **No hay ninguna evidencia de ellos en el repositorio** (ni código, ni documentación, ni datos).

## 1. Qué es AvaChat y qué no es

AvaChat es una plataforma de chatbots empresariales. Su objetivo es **atender conversaciones, responder preguntas, consultar información y ejecutar acciones** mediante funcionalidades propias o integraciones autorizadas.

AvaChat **no es un CRM**. No administra ciclos de venta, oportunidades, campañas ni historias de contacto más allá de lo que una conversación necesita. Regla de producto para todo módulo específico:

1. **Integración primero.** Si otro sistema ya administra el dato (agenda, historia clínica, tienda, facturación, reservas), AvaChat lo consulta y opera a través de su API autorizada y no lo duplica.
2. **Datos propios mínimos.** Solo se guarda en Ava lo que el chatbot necesita para responder o ejecutar la acción cuando no existe un sistema externo, y se guarda por Workspace.
3. **El chatbot es el producto.** Cada módulo específico existe para que el asistente pueda consultar o hacer algo concreto. Una pantalla administrativa sin una capacidad del chatbot detrás no justifica un módulo.
4. **Lo clínico, fiscal o logístico pertenece a sistemas especializados.** AvaChat orienta, consulta y gestiona solicitudes; no sustituye a esos sistemas.

## 2. Reglas que debe respetar todo el plan

Provienen de `reglas.md` y del encargo; se citan para que cualquier módulo se planifique dentro de ellas.

- **Un único código base.** Las cuatro soluciones comparten código. No se crean cuatro aplicaciones ni se copia código por solución (`reglas.md` §3, reutilización).
- **Aislamiento por Workspace.** Toda consulta parte del Workspace de la sesión; el Workspace nunca viene del cliente (`reglas.md` §7 y §11). Las tablas de negocio nuevas llevan su Workspace y claves foráneas compuestas, como `conversations` y `chatbot_executions` (el aislamiento está en `reglas.md` §7; las claves compuestas, en las decisiones permanentes de §11).
- **`Desarrollo_DEV` y los controles existentes** se conservan: el Workspace administrativo se identifica por código, no se desactiva ni cambia de código, y `WorkspaceScope` es el único sitio que decide quién consulta otro Workspace (`reglas.md` §11, `app/Services/WorkspaceScope.php`).
- **Permisos y módulos son cosas distintas.** Un módulo habilitado comercialmente sigue exigiendo el permiso del rol; ocultar un enlace nunca es la protección (la comprobación está en el backend).
- **Auditoría obligatoria.** Un módulo o acción administrativa nueva no está terminada sin su auditoría (`reglas.md` §11, `app/Audit/AuditLogger.php`).
- **Migraciones seguras y aditivas** (`reglas.md` §6b): una funcionalidad nueva lleva su propia migración y nada es destructivo. Mientras el proyecto no esté desplegado, `reglas.md` §6b indica integrar un cambio estructural de una tabla del esquema inicial en su migración `create_*`; pero la base de desarrollo ya tiene datos, y la práctica reciente ha sido migraciones `add_*` aditivas (por ejemplo `add_chatbot_executions`). Cuál aplicar a cada cambio se decide al implementarlo, con la base de desarrollo intacta; desde el primer despliegue solo `add_*`.
- **Diseño y responsive.** Interfaz en el sistema actual de tokens y componentes; probada en móvil, tablet y escritorio (`reglas.md` §5).
- **Metodología por módulo** (`reglas.md` §14): leer reglas y pendientes, analizar, reutilizar, implementar el cambio mínimo, ejecutar pruebas (`php artisan test`, `npm run build`, Pint), probar la pantalla, actualizar `pendientes.md` y reportar.
- **No inventar** precios, planes, límites ni capacidades de terceros. Lo que depende de una API externa queda como [Verificar].
- **Distinguir** funciones confirmadas, propuestas y pendientes de decisión.

## 3. Estado verificado de Ava Platform

### 3.1. Plataforma

- Laravel 13, PHP 8.4 en el contenedor, Inertia 3, React 19 en JSX, Vite 8, Tailwind 4, PostgreSQL 16, Redis, MinIO, Horizon, Reverb (`composer.json`, `package.json`, `reglas.md` §2). Todo corre en Docker.
- Tenancy: `organizations`, `workspaces`, `workspace_user` con rol por Workspace (`database/migrations/2026_10_02_100200_create_workspace_tables.php`).
- Contexto de Workspace: `EnsureWorkspaceContext` lo toma solo de la sesión y lo revalida en cada petición (`app/Http/Middleware/EnsureWorkspaceContext.php`).
- Autorización: permisos Spatie por rol, comprobados con `workspace.permission:<permiso>` (`EnsureWorkspacePermission`). Permisos existentes: `manage-users`, `manage-settings`, `view-dashboard`, `view-users`, `view-chatbots`, `manage-chatbots`, `view-conversations`, `reply-conversations`, `manage-conversations`. Roles por defecto: `admin`, `supervisor`, `cliente`, `agente` (`database/seeders/RolesAndPermissionsSeeder.php`).
- Workspace administrativo `DESARROLLO_DEV` y consulta de otros Workspaces en solo lectura para superusuarios que trabajan dentro de él (`WorkspaceScope`, `ViewsWorkspace`, pruebas en `WorkspaceSelectorTest`).
- El sidebar muestra abajo el «Workspace activo» solo como información (`resources/js/Components/Sidebar.jsx`); el menú sale de `resources/js/config/navigation.js`, que filtra por permiso.

### 3.2. Módulos existentes

| Módulo | Estado | Evidencia |
|---|---|---|
| Asistentes (Chatbots) | Existe | `ChatbotController`, `ChatbotService`, `Pages/Chatbots`; `ChatbotsTest` (24), `ChannelAppearanceTest` (32) |
| Conversaciones y atención humana | Existe | `ConversationInbox`, `ConversationControl`, `ConversationMessenger`; `ConversationsTest` (25), `HumanHandlingTest` (26) |
| Entorno DEMO de conversaciones | Existe (solo local/testing) | `DemoConversations`; `DemoConversationsTest` (20) |
| Canales Web y WhatsApp | Existe | `WebWidget`, `WhatsAppType`, `public/widget/ava-widget.js`; `WebWidgetTest` (18), `ChannelIntegrationsTest` (23) |
| Ejecución de workflows de n8n iniciada por Ava | Existe, solo con simulaciones | `ChatbotExecutions`, `N8nExecutor`, `config/n8n.php`, tabla `chatbot_executions`; `ChatbotExecutionTest` (53) |
| API del agente (n8n → Ava) | Existe | `routes/api.php` (`/api/agent/config`, `messages`, `messages/status`, `conversations/authorize`, `conversations/handoff`); `AgentApiTest` (15) |
| Integraciones | Existe | tipos `http`, `whatsapp`, `web`, `n8n` en `config/integrations.php`; `IntegrationsTest` (28), `N8nIntegrationTest` (21) |
| Reportes (ruta `/reports`) | Parcial | `ReportController` renderiza `Reports/Index`; solo el equipo del Workspace tiene datos reales, el resto dice «Sin datos todavía» (`config/reports.php`, `ReportsTest`) |
| Auditoría | Existe | `AuditController`, `audit_logs`, exportación a PDF; `AuditTest` (30) |
| Configuraciones | Existe | `Pages/Settings` (general, regional, apariencia, impuestos); `WorkspaceSettingsTest` (17) |
| Usuarios, roles, Workspaces y organizaciones | Existe | `Pages/Users` (usuarios, roles, permisos, Workspaces, organizaciones); `UserRoleManagementTest` (26), `WorkspaceAccessTest` (24), `WorkspaceAdministrationTest` (11), `WorkspaceLifecycleTest` (12) |
| Filtros guardados | Existe (Auditoría y Conversaciones) | `SavedFilters`, `SavedFiltersTest` (13) |

Observaciones importantes que condicionan el plan:

- **Dashboard y Reportes son pantallas distintas.** `/dashboard` (`DashboardController`, `Pages/Dashboard`) es el resumen y la página de inicio tras el login; `/reports` (`ReportController`) conserva el análisis detallado (`reglas.md` §11). Resuelve D-04.
- **La conexión real con Meta y n8n no está validada de punta a punta.** Todo lo que depende de ellas se probó con simulaciones (`pendientes.md`, «Chatbots — prueba manual con credenciales reales» y «Ejecución iniciada por Ava»).
- **Instagram y Messenger no están disponibles** como canales (`config/chatbots.php`).
- **Configuraciones no tiene horario de atención ni datos de negocio propios de una solución.** Región, moneda, zona horaria e impuestos existen y hoy solo se aplican a las fechas de las tablas (`pendientes.md`, «Configuraciones, fase futura»).
- **No hay concepto comercial alguno:** ni solución, ni plan, ni suscripción, ni límites. Buscados en migraciones, modelos y configuración: no existen. `User` no usa Sanctum aunque está instalado.

### 3.3. Lo que no existe

Ninguno de estos elementos está en el repositorio (ni modelos, ni migraciones, ni rutas, ni páginas): catálogo, productos, servicios, clientes o pacientes, pedidos, domicilios, zonas de cobertura, sucursales o sedes, citas o reservas, profesionales o especialistas, disponibilidad, recordatorios, promociones y cupones, caja, envíos, devoluciones, garantías, ni una API pública para desarrolladores. `pendientes.md` lista varios como «Módulos de la referencia sin construir».

## 4. Las cuatro soluciones

Cada Workspace de cliente tiene **una sola solución**, asignada por CCG. Si un cliente contrata dos soluciones, tiene dos Workspaces independientes. La solución no la elige ni la cambia el cliente.

| Solución | Para quién | Qué hace el chatbot (alcance inicial propuesto) |
|---|---|---|
| Salud | Clínicas, consultorios, IPS | Atender a pacientes, informar servicios y sedes, consultar disponibilidad y gestionar citas médicas, confirmar y recordar |
| Delivery | Restaurantes y negocios con entrega a domicilio | Mostrar el menú, tomar y consultar pedidos, verificar cobertura, dar seguimiento y novedades de la entrega |
| eCommerce | Tiendas en línea | Responder consultas de compra sobre el catálogo, consultar pedidos, envíos y seguimiento, gestionar cambios, devoluciones y garantías, informar promociones |
| Servicios y Reservas | Salones de uñas, barberías, spas y negocios de citas o reservas | Informar servicios, consultar disponibilidad, agendar y gestionar citas o reservas, confirmar y recordar |

Salud y Servicios y Reservas se parecen (citas, profesionales, sedes, disponibilidad, recordatorios) pero **no son lo mismo**: Salud tiene datos sensibles y límites legales y clínicos; Servicios y Reservas puede apoyarse en plataformas de agenda externas como Booker. Reutilizar componentes comunes entre ambas es una decisión técnica que se confirma al desarrollar la segunda (ver 8.5), no una suposición.

## 5. Fase 1 — Preparar las cuatro soluciones en la arquitectura existente

### 5.1. Objetivo

Que CCG pueda asignar a cada Workspace una de las cuatro soluciones y que la aplicación sepa, desde un único punto, qué módulos corresponden a ese Workspace, **sin cambiar el comportamiento de ningún Workspace actual** y sin duplicar código.

### 5.2. Qué hay que decidir y construir

**[Propuesto] Registro de módulos en código** (por ejemplo `config/modules.php`). Cada módulo lleva su identificador, si es general o específico, qué permiso lo gobierna y qué rutas y entradas de menú le pertenecen. Los ocho módulos comunes están siempre disponibles y no se guardan en base de datos.

**[Propuesto] Catálogo de soluciones** (por ejemplo `config/solutions.php`): `salud`, `delivery`, `ecommerce`, `servicios_reservas`, con su lista de módulos específicos. Vive en código mientras los módulos específicos estén atados al código; pasaría a datos solo si el negocio necesita editarlo sin desplegar.

**[Propuesto] Solución como dato del Workspace.** Una columna validada contra el catálogo, asignada solo por CCG (superusuario, Gate `manage-workspaces`, no un permiso del catálogo Spatie) y auditada. No se guarda en `workspace_settings`, porque cualquier usuario con `manage-settings` edita esa tabla. La forma exacta del cambio de esquema (columna aditiva en `workspaces` frente a otra estructura) se confirma al implementar, con la migración aditiva que exige `reglas.md` §6b.

**[Propuesto] Servicio central de capacidades** (por ejemplo `WorkspaceCapabilities`, con la misma disciplina que `WorkspaceScope`): responde qué módulos tiene un Workspace y si permite uno concreto. Lo consumen:

- el middleware de rutas (por ejemplo `workspace.module:<módulo>`, apilado con `workspace.permission:<permiso>`);
- las propiedades compartidas de Inertia (`HandleInertiaRequests`) para filtrar el menú en `navigation.js`;
- las superficies públicas y sin sesión: `WebWidget::resolve`, `AuthenticateAgent`, el webhook de Meta (`WhatsAppInbound`);
- los procesos en segundo plano (`ChatbotExecutions`);
- Reportes, Auditoría y filtros guardados, de modo que no expongan datos de módulos que el Workspace no tiene.

**Acceso efectivo = módulo disponible para el Workspace Y permiso del rol del usuario.** Las consultas de otro Workspace en solo lectura (`?workspace=`) usan las capacidades del Workspace consultado, no las del activo.

**[Propuesto] Selector de Workspace.** Reutilizar `WorkspaceCombobox` (`purpose=view`) en el bloque inferior del sidebar, visible solo para quien `WorkspaceScope::canChoose`, mostrando la solución del Workspace consultado. No se crea otro selector ni se rediseña el sidebar. La consulta sigue siendo de solo lectura: las escrituras se aplican siempre al Workspace de la sesión. No existe un selector de solución para el cliente (el HTML tiene uno en `showSectorSwitcher`; es un artefacto de la demo y no se replica).

**[Propuesto] Administración.** Asignar y ver la solución de cada Workspace en la pestaña Workspaces de Usuarios y Roles (`Pages/Users/Partials/WorkspacesPanel.jsx`), solo para superusuarios, con auditoría.

### 5.3. Compatibilidad con los Workspaces actuales

Todo lo que existe hoy son módulos generales. Por eso la Fase 1 no puede quitarle funcionalidad a ningún Workspace. Un Workspace sin solución asignada se trata de forma explícita como «actual» (todos los módulos generales) hasta que CCG le asigne una; los Workspaces nuevos exigen solución desde su creación. `Desarrollo_DEV` conserva todas sus capacidades.

### 5.4. Relación con planes y límites

Los planes comerciales (módulos y límites contratados) **no se diseñan en esta fase**: no hay precios, límites, reglas de prueba ni renovación aprobados (D-02, D-03). El HTML presenta Starter, Pro y Enterprise con precios y límites que se contradicen con sus propios datos, así que no se usan como definición. La Fase 1 debe dejar el punto central de capacidades listo para incorporar planes después sin reprogramar rutas.

### 5.5. Criterios de aceptación de la Fase 1

1. Con todos los Workspaces actuales, ningún test existente cambia de resultado.
2. Cada solución expone únicamente sus módulos específicos (en esta fase, ninguno) y los ocho comunes.
3. Una ruta, un endpoint público o un proceso en segundo plano de un módulo no disponible para el Workspace responde como no autorizado, aunque el rol tenga el permiso.
4. Un módulo disponible sigue exigiendo el permiso del rol.
5. El menú oculta lo no disponible y el backend lo rechaza igualmente.
6. La vista de solo lectura de otro Workspace usa los módulos del Workspace consultado.
7. Un usuario de un Workspace cliente no puede cambiar su solución ni su contexto.
8. `Desarrollo_DEV` conserva todo.
9. Cambiar de solución un Workspace es una acción solo de CCG, auditada, y no destruye datos.
10. Tests nuevos de aislamiento entre dos Workspaces de soluciones distintas.

## 6. Fase 2 — Los ocho módulos comunes en las cuatro soluciones

Principio: **se reutilizan los módulos existentes**. Una mejora al código compartido beneficia a las cuatro soluciones; no hay versiones por solución. Lo que cambia entre soluciones es la **configuración** (terminología, plantillas de instrucciones, métricas, herramientas que el chatbot tiene disponibles), no el código.

Lo que sí es nuevo y transversal en esta fase:

- **Terminología por solución [Propuesto, D-09].** Textos como «paciente», «cliente» o «huésped» y el nombre de las entidades de cada solución se resuelven desde la configuración de la solución, no con ramas de código.
- **Plantillas de asistente por solución [Propuesto, D-10].** Instrucciones y mensaje de bienvenida iniciales sugeridos para cada solución. Siguen siendo editables por el cliente: Ava es la única fuente del comportamiento del chatbot (`reglas.md` §11).
- **Contrato de herramientas del chatbot [Propuesto].** Hoy n8n lee la configuración del chatbot y reporta mensajes (`/api/agent/*`) o Ava le ejecuta un workflow (`ChatbotExecutions`). Para que el chatbot consulte datos y ejecute acciones de un módulo específico se necesita una forma común, autenticada y por Workspace, de exponer esas capacidades al workflow. Se define en esta fase como parte de Asistentes (6.2) para que los módulos de las fases 3 a 6 la reutilicen. Nada de esto está implementado.

### 6.1. Dashboard

- **Propósito.** Resumen del día del negocio y puerta de entrada tras el login.
- **Existe / adaptación.** [Existe] Implementado en la Etapa 1: `/dashboard` (`DashboardController`, `DashboardMetrics`, `Pages/Dashboard`) es independiente de Reportes, que pasó a `/reports` (`ReportController`). Pruebas: `DashboardTest`. D-04 queda resuelta: son dos pantallas. Pendiente de adaptación: tarjetas específicas de cada solución cuando existan sus módulos.
- **Funcionalidades propuestas.** Conversaciones pendientes de agente y en atención (datos que ya existen, `ConversationInbox::counts`), estado de los canales, accesos rápidos a los módulos disponibles del Workspace, y tarjetas específicas de la solución solo cuando existan sus módulos.
- **Por solución.** Mismo módulo. Las tarjetas de cada solución aparecen cuando sus módulos específicos existan y estén disponibles; hasta entonces es igual para las cuatro.
- **Consulta y acciones.** Consulta; sin acciones de escritura propias.
- **Conexiones.** Conversaciones, Reportes, Asistentes.
- **Criterios de «listo».** Muestra solo datos reales del Workspace (nunca inventados, `reglas.md` §11); respeta módulos y permisos; el estado vacío está diseñado; las pruebas de aislamiento incluyen otro Workspace.

### 6.2. Asistentes / AVA Chat

- **Propósito.** Crear y configurar los chatbots del Workspace: identidad, instrucciones, canales, apariencia y conexión con el motor de ejecución.
- **Existe.** [Existe] Identidad (avatar, nombre, descripción), instrucciones, token de acceso del agente, canales Web y WhatsApp con su apariencia (sliders, tooltips), vista previa que ejecuta el mismo script público, activar y desactivar, `chatbots.workflow_key` y `chatbot_executions` para workflows administrados por el equipo. Pruebas: `ChatbotsTest`, `ChannelAppearanceTest`, `ChatbotExecutionTest`, `AgentApiTest`.
- **Adaptación necesaria.** [Propuesto]
  - Plantillas iniciales por solución (D-10).
  - El contrato de herramientas del chatbot descrito arriba.
  - Selección y visibilidad de las herramientas disponibles por solución y por Workspace.
  - Una interfaz para que CCG vea y asigne el workflow de un chatbot (hoy solo `workflows:manage`).
- **Consulta y acciones.** El chatbot, a través de herramientas autorizadas, podrá consultar y actuar solo sobre los datos de su propio Workspace. Las acciones de riesgo (cancelar, devolver, cobrar) requieren una política explícita (D-11).
- **Conexiones.** Conversaciones (relevo a humano), Integraciones (credenciales de canales y de sistemas externos), Auditoría, módulos específicos (herramientas).
- **Criterios de «listo».** Un asistente creado en cualquiera de las cuatro soluciones funciona igual; las herramientas expuestas son exactamente las del módulo disponible; ningún token ni dato de otro Workspace es alcanzable; la configuración sigue en Ava como única fuente.

### 6.3. Conversaciones

- **Propósito.** Bandeja única de conversaciones de todos los canales, con atención humana.
- **Existe.** [Existe] Filtros IA / pendientes / en atención / resueltas, búsqueda, canal y asistente, tomar, escribir, devolver a la IA, resolver, asignar, estados de entrega, envío real por WhatsApp (Graph API) y por el widget web, mensajes sin confirmar, filtros guardados, entorno DEMO. Pruebas: `ConversationsTest`, `HumanHandlingTest`, `DemoConversationsTest`, `SavedFiltersTest`.
- **Adaptación necesaria.** [Propuesto] Mostrar en la conversación el contexto de la solución (por ejemplo, la cita o el pedido relacionado) cuando existan los módulos específicos, sin duplicar el dato (se enlaza). Política de retención y reproducción de multimedia siguen en `pendientes.md`.
- **Por solución.** Igual para las cuatro. Salud puede exigir límites adicionales de acceso a conversaciones con datos sensibles (D-07).
- **Conexiones.** Asistentes (quién responde), Auditoría (cambios de control), Reportes (volumen, tiempos), módulos específicos (vínculos).
- **Criterios de «listo».** Sin cambios de comportamiento para los Workspaces actuales; el vínculo con módulos específicos respeta módulos y permisos; los permisos `view-conversations`, `reply-conversations` y `manage-conversations` siguen gobernando todo.

### 6.4. Reportes

- **Propósito.** Analítica del Workspace: conversaciones, interacciones, preguntas, encuestas y tendencias.
- **Existe / Parcial.** Periodo, granularidad, gráficos SVG propios y estados vacíos. Solo hay datos reales del equipo del Workspace; las métricas de `config/reports.php` tienen `source` nulo y muestran «Sin datos todavía». `pendientes.md` ya define la regla: al construir cada módulo, darle su `source`.
- **Adaptación necesaria.** [Propuesto] Conectar primero las métricas que ya tienen datos propios (conversaciones, mensajes, atención humana, ejecuciones). Las métricas de cada solución se añaden con su módulo. Nunca se muestran números inventados. Exportación a PDF o Excel: D-12.
- **Conexiones.** Conversaciones, Asistentes, módulos específicos, Auditoría.
- **Criterios de «listo».** Cada métrica mostrada tiene fuente real y se calcula desde el Workspace consultado; sin fuente, muestra el estado vacío; no mezcla datos de Workspaces.

### 6.5. Auditoría

- **Propósito.** Historial de cambios administrativos y de control de conversaciones.
- **Existe.** [Existe] Filtros, resumen, detalle antes/después, exportación a PDF, enmascarado de credenciales, solo se añade. Prueba: `AuditTest` (30).
- **Adaptación necesaria.** [Propuesto] Cada módulo específico registra sus cambios (obligatorio por `reglas.md` §11). La asignación o el cambio de solución de un Workspace se audita. Falta decidir la retención del histórico (`pendientes.md`).
- **Conexiones.** Todos los módulos.
- **Criterios de «listo».** Toda acción administrativa nueva aparece con usuario, Workspace, IP y diferencia; no se guardan secretos ni mensajes.

### 6.6. Configuraciones

- **Propósito.** Preferencias del Workspace.
- **Existe.** [Existe] General, regional, apariencia e impuestos; logo privado; color principal con contraste mínimo. Prueba: `WorkspaceSettingsTest` (17).
- **Adaptación necesaria.** [Propuesto]
  - Horario de atención y datos de negocio que el chatbot necesite (el HTML tiene un horario de atención; en Ava no existe) (D-13).
  - Mostrar la solución del Workspace como dato de solo lectura.
  - La solución, el plan y los módulos nunca son editables por el cliente aquí.
- **Conexiones.** Asistentes (horario y mensajes fuera de horario), módulos específicos (zonas horarias, moneda).
- **Criterios de «listo».** Los nuevos datos son por Workspace, validados desde `config/workspace.php` o su sucesor, y no exponen configuración comercial al cliente.

### 6.7. Integraciones

- **Propósito.** Conexiones del Workspace con canales y sistemas externos.
- **Existe.** [Existe] WhatsApp Business (con prueba real contra Meta), canal Web, n8n por Workspace, conexiones HTTP/REST, credenciales cifradas y de solo escritura, protección contra SSRF (`SafeHttpTarget`). Pruebas: `IntegrationsTest`, `ChannelIntegrationsTest`, `N8nIntegrationTest`.
- **Adaptación necesaria.** [Propuesto] Un tipo de integración por sistema externo que una solución necesite (agenda, tienda, plataforma de reservas, pasarela), añadido con la clase de tipo y el registro de `config/integrations.php`, sin lógica por proveedor en la página. Instagram y Messenger siguen no disponibles hasta que funcionen de punta a punta. Las integraciones disponibles para un Workspace dependen de su solución (D-14).
- **Conexiones.** Asistentes (canales y herramientas), módulos específicos (datos externos), Auditoría.
- **Criterios de «listo».** Una integración nueva se prueba con una solicitud real de solo lectura cuando el proveedor lo permite; un tipo que no puede probarse lo dice; nunca se simula una conexión.

### 6.8. Usuarios y roles

- **Propósito.** Cuentas, roles, permisos, Workspaces y organizaciones.
- **Existe.** [Existe] Alta, edición y desactivación de usuarios, roles por Workspace sin escalada de privilegios, catálogo de roles global solo para superusuarios, Workspaces y organizaciones sin borrado. Pruebas: `UserRoleManagementTest`, `WorkspaceAccessTest`, `WorkspaceAdministrationTest`, `WorkspaceLifecycleTest`.
- **Adaptación necesaria.** [Propuesto] Cada módulo específico define sus permisos (por ejemplo `view-*` y `manage-*`) en el catálogo y en el sembrado re-ejecutable (`reglas.md` §7). Un límite de agentes por plan, si se define (D-03), se aplicará en `ConversationAgents`. La asignación de solución se hace aquí (Fase 1).
- **Criterios de «listo».** Los permisos nuevos no rompen los roles existentes; `admin` los recibe todos; un rol de un Workspace no ve módulos que su Workspace no tiene.

## 7. Patrón común para los módulos específicos

Cada módulo específico se planifica y entrega como **una unidad funcional completa**, no como una pantalla suelta. Un módulo específico está terminado solo cuando incluye, con pruebas:

1. **Datos o integración.** Tablas propias con Workspace y claves foráneas compuestas, o el tipo de integración que consulta al sistema externo. Migración aditiva.
2. **Backend.** Servicios en `app/Services/` (no lógica en controladores), validaciones en `FormRequest`, autorización por permiso y por módulo disponible.
3. **Herramienta del chatbot.** La capacidad concreta que el asistente podrá usar (consultar o ejecutar), autenticada y limitada al Workspace del chatbot, con el control de atención humana ya existente y manejo de errores sin exponer datos internos.
4. **Interfaz de administración.** Solo lo necesario para configurar y supervisar, en el sistema de diseño actual, responsive.
5. **Auditoría** de los cambios administrativos.
6. **Reportes.** La métrica de su `source` real, si corresponde.
7. **Pruebas.** Funcionales, de permisos, de aislamiento entre dos Workspaces (incluyendo uno de otra solución), de módulo no disponible, de regresión de lo existente y del flujo con el chatbot.
8. **Documentación.** Actualización de `reglas.md` (decisiones permanentes), `pendientes.md` y este plan.

No se desarrollan primero todas las interfaces para implementar después todo el backend. Un módulo no se da por terminado por tener pantalla o ruta.

## 8. Módulos específicos por solución

Los alcances de esta sección son **propuestos**. Todo lo marcado [Decisión] o [Verificar] debe confirmarse antes de programarlo. En todos los módulos las funciones del chatbot son propuestas y ninguna existe hoy.

### 8.1. Salud — Fase 3

**Límite de producto [Decisión D-07].** El chatbot orienta, informa y gestiona solicitudes administrativas (citas, sedes, servicios, confirmaciones). **No** emite diagnósticos, no da indicaciones médicas, no sustituye la historia clínica ni un sistema clínico. Distinción:

- Funciones del chatbot: responder preguntas generales de la institución, informar servicios y sedes, consultar disponibilidad, agendar, reprogramar y cancelar citas, confirmar y recordar, y derivar a una persona.
- Funciones propias de un sistema clínico (fuera de AvaChat): historia clínica, órdenes médicas, resultados, prescripciones, facturación clínica. Si el cliente ya tiene un sistema de agenda o clínico, AvaChat se integra con él.

Los datos de pacientes son sensibles. Antes de guardar datos de salud en Ava es obligatoria la decisión legal y de seguridad de D-07 (qué se guarda, dónde, por cuánto tiempo y con qué acceso).

Orden propuesto de los módulos (cada uno se desarrolla y valida antes del siguiente):

**S1. Sedes y servicios**
- Objetivo y límites: catálogo informativo de sedes (dirección, horarios, contacto) y servicios ofrecidos. No es un sistema de facturación ni de inventario.
- Funciones del chatbot: informar servicios, precios si el cliente los publica (D-15), sedes, horarios y cómo llegar.
- Operaciones: consultar. La administración (crear, editar, activar) es de la interfaz.
- Datos y origen: carga manual en Ava, o importación desde un sistema externo cuando exista (D-16).
- Integraciones: ninguna obligatoria; opcionalmente el sistema que ya administra servicios y sedes.
- Dependencias: Configuraciones (horario, zona horaria), Asistentes (herramienta).
- Reutilización: el patrón de listado, paginación, auditoría y permisos existente; un catálogo de servicios compartible con las demás soluciones (ver 8.5).
- Criterios de aceptación: el chatbot responde solo con datos del Workspace; una sede o servicio inactivo no se ofrece; datos de otro Workspace nunca aparecen; auditoría de cambios; permisos propios.

**S2. Profesionales y especialidades**
- Objetivo y límites: relación de profesionales, sus especialidades, las sedes donde atienden y los servicios que prestan. No es un registro médico ni de credenciales.
- Funciones del chatbot: informar qué especialidades hay y qué profesionales atienden cada una; sugerir con quién agendar.
- Operaciones: consultar.
- Datos y origen: Ava o sistema externo (D-16).
- Dependencias: S1.
- Criterios: solo profesionales activos; relaciones coherentes con sedes y servicios del mismo Workspace; no se muestran datos personales que el cliente no autorice.

**S3. Disponibilidad**
- Objetivo y límites: horarios en que se puede agendar con cada profesional o servicio. Si un sistema externo administra la agenda, es la fuente (no se duplica).
- Funciones del chatbot: ofrecer huecos libres y proponer alternativas.
- Operaciones: consultar disponibilidad. Reservar un hueco temporalmente mientras el paciente confirma es una decisión (D-17).
- Datos y origen: agenda propia en Ava o consulta al sistema externo [Verificar capacidades de su API].
- Dependencias: S1, S2, Configuraciones (zona horaria).
- Criterios: nunca ofrece un hueco ocupado; maneja zonas horarias del Workspace; ante una caída del sistema externo informa sin inventar disponibilidad.

**S4. Citas médicas**
- Objetivo y límites: agendar, consultar, reprogramar y cancelar citas por el chatbot. No gestiona el acto clínico.
- Funciones del chatbot: flujo conversacional de agendamiento, identificación mínima del paciente (D-07), confirmación, reprogramación y cancelación con las reglas del cliente (D-18), relevo a una persona.
- Operaciones: crear, consultar, modificar y cancelar una cita, siempre sobre datos del Workspace y con trazabilidad.
- Datos y origen: citas propias o del sistema externo. Se guarda solo lo necesario.
- Dependencias: S1 a S3, Conversaciones (relevo), Auditoría.
- Reutilización: el control de atención humana y las ejecuciones con respuesta única existentes; el módulo de citas es candidato a compartir núcleo con Servicios y Reservas (8.5).
- Criterios: una cita no se duplica ante un reintento (idempotencia); no se agenda sobre un hueco ocupado; la cancelación y el cambio dejan auditoría; un paciente solo opera sobre sus propias citas.

**S5. Confirmaciones y recordatorios**
- Objetivo y límites: mensajes proactivos de confirmación y recordatorio de citas.
- Funciones del chatbot: enviar el recordatorio, recibir la respuesta (confirmo, cambio, cancelo) y actuar sobre la cita.
- Operaciones: programar y enviar mensajes salientes.
- Datos y origen: citas (S4). Requiere mensajes salientes iniciados por la empresa: en WhatsApp, fuera de la ventana de 24 horas solo se permiten plantillas aprobadas por Meta, que hoy Ava no envía (`pendientes.md`). Es una dependencia [Verificar] y una decisión (D-19).
- Dependencias: S4, Integraciones (WhatsApp), colas (Horizon) y el planificador.
- Criterios: nunca se envía dos veces el mismo recordatorio; respeta la ventana y las plantillas; un envío con resultado incierto no se repite (comportamiento ya existente).

**S6. Atención a pacientes**
- Objetivo y límites: preguntas frecuentes y orientación administrativa (requisitos, documentos, horarios, cobertura informada por el cliente), con derivación a una persona. Sin consejo médico.
- Funciones del chatbot: responder desde conocimiento autorizado por el cliente, detectar cuándo derivar, entregar la conversación a un agente.
- Datos y origen: instrucciones del chatbot y el contenido que el cliente cargue (D-20, base de conocimiento).
- Dependencias: Asistentes, Conversaciones.
- Criterios: no responde fuera de lo autorizado; deriva cuando corresponde; la derivación queda registrada.

### 8.2. Delivery — Fase 4

**Límite de producto.** El chatbot toma y consulta pedidos y da seguimiento. No es un sistema de punto de venta ni de logística de flota, ni procesa pagos salvo integración autorizada (D-21). Si el negocio ya tiene un POS o una plataforma de pedidos, AvaChat se integra.

**D1. Catálogo o menú**
- Objetivo y límites: productos del menú con categorías, precios, disponibilidad y variantes que el cliente configure (D-22, opciones y combos). No es inventario completo.
- Funciones del chatbot: mostrar el menú, responder sobre ingredientes y precios, sugerir y reflejar disponibilidad.
- Datos y origen: Ava o carga/sincronización con el POS del cliente (D-16).
- Reutilización: núcleo de catálogo compartido (ver 8.5), con atributos propios de Delivery sin forzar los de eCommerce.
- Criterios: un producto pausado no se ofrece; precios coherentes con la moneda del Workspace; sin mezcla entre Workspaces.

**D2. Zonas de cobertura**
- Objetivo y límites: dónde se entrega y a qué costo o tiempo estimado, según lo que el negocio defina (D-23).
- Funciones del chatbot: verificar si una dirección o zona tiene cobertura e informar costo y tiempo.
- Datos y origen: zonas definidas en Ava (por lista de barrios o zonas; la geocodificación es una decisión, D-23) o servicio externo [Verificar].
- Dependencias: Configuraciones, D1.
- Criterios: respuesta determinista para una zona dada; una zona inactiva no tiene cobertura.

**D3. Pedidos**
- Objetivo y límites: armar, confirmar y consultar pedidos hechos por el chatbot.
- Funciones del chatbot: tomar el pedido, validar contra el menú, calcular el total, confirmar con el cliente, consultar el estado.
- Operaciones: crear y consultar un pedido; la cancelación según la política del negocio (D-11, D-18).
- Datos y origen: pedidos propios o del sistema externo. Se guarda solo lo necesario.
- Integraciones: POS o plataforma de pedidos si la hay; pasarela de pago si se decide (D-21).
- Dependencias: D1, D2, Conversaciones (relevo), Auditoría.
- Criterios: un pedido no se crea dos veces ante un reintento; el total sale del catálogo y no del texto del cliente; los cambios de estado quedan auditados.

**D4. Domicilios (entrega)**
- Objetivo y límites: asignación y estado de la entrega de cada pedido. Si el negocio usa una plataforma de repartidores, se integra; no se construye un sistema de flota (D-24).
- Funciones del chatbot: informar quién entrega y el estado, recibir una dirección o referencia.
- Datos y origen: Ava (estado y repartidor asignado) o la plataforma externa.
- Dependencias: D3.
- Criterios: el estado de la entrega es coherente con el del pedido; un repartidor de otro Workspace nunca se ve.

**D5. Seguimiento de entregas**
- Objetivo y límites: que el cliente pregunte por su pedido y reciba el estado actual y un tiempo estimado.
- Funciones del chatbot: consultar el estado por pedido o por el contacto de la conversación.
- Dependencias: D3, D4.
- Criterios: solo se informa el pedido del propio contacto; el estado coincide con la fuente de verdad.

**D6. Novedades de los pedidos**
- Objetivo y límites: avisos proactivos al cliente (confirmado, en camino, entregado, retraso, incidencia) y registro de novedades.
- Funciones del chatbot: enviar la novedad y atender la respuesta.
- Dependencias: D3 a D5; mensajes salientes con las limitaciones de plantillas (D-19).
- Criterios: no se duplica una novedad; una incidencia puede derivarse a una persona.

### 8.3. eCommerce — Fase 5

**Límite de producto.** El chatbot responde sobre productos, pedidos y posventa. No reemplaza la tienda en línea ni la pasarela de pago. Si la tienda está en WooCommerce, Shopify u otra plataforma, AvaChat consulta y opera mediante su API en lugar de duplicar el catálogo y los pedidos [Verificar capacidades de cada API]; las plataformas concretas son D-25.

**E1. Catálogo de productos**
- Objetivo y límites: productos con categorías, precios, variantes (talla, color) y disponibilidad. La fuente suele ser la tienda del cliente.
- Funciones del chatbot: buscar, comparar y responder sobre un producto.
- Datos y origen: sincronización o consulta a la plataforma del cliente (preferido) o carga manual (D-16).
- Reutilización: núcleo de catálogo compartido con D1, con atributos de eCommerce (variantes, stock) propios.
- Criterios: no se ofrece un producto agotado o inactivo; el precio proviene de la fuente.

**E2. Consultas de compra**
- Objetivo y límites: guiar la decisión de compra (existencias, tallas, recomendaciones) sin cerrar la venta por sí mismo si el negocio no lo decide (D-26).
- Funciones del chatbot: responder sobre existencias, características y alternativas; llevar al cliente al enlace de compra o armar el pedido.
- Dependencias: E1.
- Criterios: respuestas basadas solo en el catálogo; no inventa existencias.

**E3. Pedidos**
- Objetivo y límites: consultar y, si se decide, crear pedidos. La plataforma de la tienda es la fuente.
- Funciones del chatbot: consultar el estado de un pedido, resumir su contenido.
- Operaciones: consultar; crear o modificar es una decisión (D-26, D-11).
- Integraciones: plataforma de la tienda [Verificar].
- Dependencias: E1.
- Criterios: el cliente solo ve sus pedidos; consistencia con la fuente.

**E4. Envíos y seguimiento**
- Objetivo y límites: estado del envío y guía de la transportadora.
- Funciones del chatbot: informar estado y guía, y enlazar el seguimiento.
- Integraciones: la plataforma de la tienda o las transportadoras [Verificar, D-27].
- Dependencias: E3.
- Criterios: solo información del propio pedido; sin inventar estados cuando la fuente no responde.

**E5. Cambios, devoluciones y garantías**
- Objetivo y límites: iniciar y dar seguimiento a solicitudes según las políticas del negocio (D-18). El chatbot recoge la solicitud y aplica reglas; la decisión financiera es del negocio.
- Funciones del chatbot: explicar la política, validar elegibilidad (por fecha, estado), registrar la solicitud y derivar a una persona cuando corresponda.
- Operaciones: crear una solicitud y consultar su estado.
- Datos y origen: solicitudes en Ava o en la plataforma externa.
- Dependencias: E3, Conversaciones (relevo), Auditoría.
- Criterios: una solicitud no se duplica; el cliente solo opera sobre sus pedidos; los cambios quedan auditados.

**E6. Promociones**
- Objetivo y límites: informar promociones vigentes y, si se decide, validar o aplicar cupones. La fuente suele ser la tienda; aplicar descuentos es una decisión (D-26).
- Funciones del chatbot: informar promociones, condiciones y vigencia.
- Dependencias: E1.
- Criterios: nunca informa una promoción vencida; respeta la vigencia y las condiciones de la fuente.

### 8.4. Servicios y Reservas — Fase 6

**Límite de producto.** Atiende negocios de citas o reservas (salones de uñas, barberías, spas y similares). El chatbot informa, consulta disponibilidad y gestiona citas. No es un software de gestión de salón: si el cliente ya usa una plataforma de agenda, esa es la fuente.

**Caso de referencia (aportado por el encargo, sin evidencia en el repositorio):** el cliente actual del salón de uñas, que utiliza Booker. La futura integración con Booker debe permitir consultar y gestionar citas y orientar sobre tarjetas de regalo **cuando sus APIs lo permitan**. Qué operaciones ofrece la API de Booker, con qué autenticación y con qué límites es [Verificar] con la documentación oficial y con el cliente; este plan no asume ninguna capacidad.

Orden propuesto, sujeto a D-28 (si Booker administra servicios, profesionales, sedes y agenda, varios módulos se resuelven con la integración y no con datos propios):

**R1. Catálogo de servicios**
- Objetivo y límites: servicios con duración y precio informativo. Si Booker (u otra plataforma) los administra, se consultan allí.
- Funciones del chatbot: informar servicios, duración y precio, y recomendar.
- Datos y origen: plataforma externa (preferido) o Ava (D-16).
- Reutilización: núcleo de catálogo/servicios compartido con S1 (8.5).
- Criterios: solo servicios activos; consistencia con la fuente.

**R2. Profesionales**
- Objetivo y límites: quién ofrece cada servicio. No incluye nómina ni comisiones.
- Funciones del chatbot: informar profesionales y ofrecer elegir.
- Datos y origen: plataforma externa o Ava.
- Reutilización: núcleo de profesionales compartido con S2 (8.5).
- Criterios: relaciones coherentes con servicios y sedes.

**R3. Sedes y horarios**
- Objetivo y límites: ubicaciones y horarios de atención. Reutiliza el módulo de sedes de Salud si se confirma (8.5).
- Funciones del chatbot: informar sedes y horarios; responder fuera de horario.
- Dependencias: Configuraciones (horario de atención, D-13).
- Criterios: respeta zona horaria y horario del Workspace.

**R4. Disponibilidad**
- Objetivo y límites: huecos libres por servicio, profesional y sede. La plataforma de agenda es la fuente si existe.
- Funciones del chatbot: ofrecer huecos y alternativas.
- Integraciones: Booker u otra [Verificar].
- Dependencias: R1 a R3.
- Criterios: nunca ofrece un hueco ocupado; informa sin inventar si la fuente falla.

**R5. Citas y reservas**
- Objetivo y límites: agendar, consultar, reprogramar y cancelar. Incluye la reserva de espacios o recursos si el negocio lo requiere (D-29).
- Funciones del chatbot: flujo de agendamiento, confirmación, cambios y cancelación según las reglas del negocio (D-18), relevo a una persona.
- Operaciones: crear, consultar, modificar y cancelar sobre la fuente de verdad (la plataforma externa o Ava).
- Reutilización: núcleo de citas compartido con S4 si se confirma (8.5).
- Criterios: idempotencia ante reintentos; no se agenda sobre un hueco ocupado; el cliente solo opera sobre sus citas; cambios auditados.

**R6. Confirmaciones y recordatorios**
- Objetivo y límites: confirmación y recordatorio de citas y atención de la respuesta.
- Funciones del chatbot: igual que S5.
- Dependencias: R5; mismas restricciones de plantillas de WhatsApp (D-19).
- Reutilización: el mecanismo de S5, no una segunda implementación.
- Criterios: iguales a S5.

**R7. Integración con Booker y tarjetas de regalo**
- Objetivo y límites: conectar el Workspace del salón de referencia con Booker. Consulta y gestión de citas, y orientación sobre tarjetas de regalo **solo si la API lo permite**; no se procesa pagos de tarjetas de regalo por el chatbot salvo que la API y una decisión lo habiliten (D-30).
- Funciones del chatbot: consultar y gestionar citas del cliente en Booker, informar sobre tarjetas de regalo (qué son, cómo comprarlas, saldos si la API los expone).
- Datos y origen: Booker. Las credenciales se guardan cifradas y de solo escritura por Workspace, como las demás integraciones (`reglas.md` §11).
- Integraciones: Booker [Verificar: autenticación, endpoints disponibles, límites, entornos de prueba, soporte de webhooks, términos de uso].
- Dependencias: Integraciones (nuevo tipo de integración), R1 a R6.
- Criterios: la prueba de conexión es real y de solo lectura; un fallo de Booker no bloquea la bandeja ni el chatbot; no se duplican citas; nunca se simula la conexión.

### 8.5. Reutilización entre soluciones

Lo que se evalúa compartir, siempre con configuración y sin forzar procesos distintos:

- **Núcleo de catálogo** (servicios, menú, productos): campos comunes (nombre, categoría, precio, estado) con atributos propios por solución. No se unifica lo que cambia de forma esencial: variantes y stock (eCommerce), opciones y combos (Delivery), duración y profesional (servicios y citas).
- **Núcleo de citas y disponibilidad** entre Salud y Servicios y Reservas: se decide al desarrollar la segunda, a partir de lo aprendido con la primera. Salud añade restricciones de datos sensibles que no deben contaminar el núcleo.
- **Profesionales y sedes** entre Salud y Servicios y Reservas.
- **Recordatorios y confirmaciones**: un único mecanismo de mensajes salientes programados.
- **Pedidos** entre Delivery y eCommerce: los procesos difieren (entrega inmediata frente a envío y devoluciones); se comparte lo que resulte común (cabecera, estados configurables) solo si se confirma tras el primero.
- **Contactos.** AvaChat no es un CRM: se mantiene el contacto mínimo que ya existe (`conversations.contact_id` y `contact_name`) y se vincula a citas o pedidos; no se construye una base de clientes completa (D-31).

## 9. Orden de desarrollo (obligatorio)

El plan sigue exactamente esta secuencia. No se inicia una fase sin haber validado la anterior. Dentro de las fases 3 a 6, cada módulo se desarrolla y valida completo, uno por uno, y no se pasa al siguiente hasta cumplir sus criterios de aceptación y el patrón de la sección 7.

**Fase 1 — Preparar las cuatro soluciones en la arquitectura existente** (sección 5).

**Fase 2 — Incorporar, adaptar y validar los ocho módulos comunes en las cuatro soluciones** (sección 6): Dashboard, Asistentes / AVA Chat, Conversaciones, Reportes, Auditoría, Configuraciones, Integraciones y Usuarios y roles. Validación: los criterios de «listo» de cada módulo en las cuatro soluciones, sin versiones separadas del código.

**Fase 3 — Módulos específicos de Salud, uno por uno** (8.1): S1 Sedes y servicios → S2 Profesionales y especialidades → S3 Disponibilidad → S4 Citas médicas → S5 Confirmaciones y recordatorios → S6 Atención a pacientes.

**Fase 4 — Módulos específicos de Delivery, uno por uno** (8.2): D1 Catálogo o menú → D2 Zonas de cobertura → D3 Pedidos → D4 Domicilios → D5 Seguimiento de entregas → D6 Novedades de los pedidos.

**Fase 5 — Módulos específicos de eCommerce, uno por uno** (8.3): E1 Catálogo de productos → E2 Consultas de compra → E3 Pedidos → E4 Envíos y seguimiento → E5 Cambios, devoluciones y garantías → E6 Promociones.

**Fase 6 — Módulos específicos de Servicios y Reservas, uno por uno** (8.4): R1 Catálogo de servicios → R2 Profesionales → R3 Sedes y horarios → R4 Disponibilidad → R5 Citas y reservas → R6 Confirmaciones y recordatorios → R7 Integración con Booker y tarjetas de regalo.

Notas sobre el orden:

- El orden dentro de cada solución es una propuesta basada en dependencias entre módulos. Puede ajustarse al confirmar el alcance de cada módulo, pero el orden de las **fases** no cambia.
- Si el cliente de referencia de Servicios y Reservas necesita Booker antes, eso exige una decisión explícita sobre el orden de las fases (D-32); este plan no la asume.
- Los módulos de una fase que dependan de uno compartido de otra (8.5) se planifican al iniciar la fase, no antes.
- Después de cada módulo se ejecuta la regresión completa (`php artisan test`, `npm run build`, Pint) y se actualizan `pendientes.md` y este documento.

## 10. Riesgos transversales

- **Olvidar un punto de entrada al restringir módulos:** rutas, widget, API del agente, webhook de Meta, jobs y vistas de otro Workspace deben usar las capacidades del Workspace correcto.
- **Convertir AvaChat en un CRM** por acumulación de datos de clientes. La regla de la sección 1 y D-31 lo contienen.
- **Duplicar datos que ya administra un sistema externo** y generar divergencias. Integración primero.
- **Datos de salud** sin decisión legal y de seguridad previa.
- **Mensajes salientes proactivos en WhatsApp** limitados por las plantillas aprobadas de Meta, que Ava hoy no envía.
- **Capacidades de terceros sin verificar** (Booker, plataformas de tienda, transportadoras, POS). Ninguna se asume.
- **Reutilizar de más:** forzar un núcleo común donde los procesos de negocio difieren.
- **Conexión real con Meta y n8n sin validar:** todo el chatbot ejecutor sigue probado solo con simulaciones; los módulos específicos dependen de ella.

## 11. Decisiones pendientes de confirmar

**Producto y alcance**
- **D-01.** Nombre comercial y público de cada solución («Servicios y Reservas», «eCommerce», etc.) y su descripción.
- **D-04. [Resuelta en la Etapa 1]** Dashboard y Reportes son pantallas distintas (ver 6.1 y 6.4).
- **D-09.** Terminología por solución (paciente, cliente, huésped; cita, reserva, turno).
- **D-10.** Contenido de las plantillas iniciales de asistente por solución.
- **D-13.** Horario de atención y qué datos de negocio guarda Configuraciones.
- **D-20.** Base de conocimiento: cómo carga el cliente el contenido que el chatbot usa (texto en instrucciones, documentos, FAQ estructuradas).
- **D-28.** Con Booker, qué módulos de Servicios y Reservas se resuelven con la integración y cuáles con datos propios.
- **D-31.** Qué datos mínimos de contacto se guardan en Ava para vincular citas y pedidos sin ser un CRM.
- **D-32.** Si alguna necesidad del cliente de referencia exige adelantar Booker respecto al orden de fases.

**Comercial**
- **D-02.** Planes comerciales: nombres, módulos y capacidades. No se usan los del HTML de referencia.
- **D-03.** Límites contratados (agentes, conversaciones u otros), qué cuenta como cada uno y si el límite es duro o blando.
- **D-05.** Reglas de prueba (trial), renovación, cambio de plan, vencimiento y suspensión.
- **D-06.** Si habrá excepciones por cliente sobre los módulos de su solución y quién las aprueba.

**Seguridad, legal y operación**
- **D-07.** Salud: qué datos de pacientes se guardan, dónde, por cuánto tiempo y quién accede; marco legal aplicable y límites del chatbot.
- **D-08.** Qué ocurre con los datos si CCG cambia la solución de un Workspace.
- **D-11.** Política de acciones de riesgo del chatbot (cancelar, devolver, cobrar, modificar): cuáles puede ejecutar solo, cuáles requieren confirmación del cliente y cuáles se derivan a una persona.
- **D-12.** Exportaciones de reportes (PDF, Excel) y su alcance.
- **D-17.** Si se reserva temporalmente un hueco mientras el cliente confirma.
- **D-18.** Reglas de cancelación, reprogramación, cambios y devoluciones: las define cada cliente y dónde se configuran.
- **D-19.** Mensajes salientes proactivos: uso de plantillas de WhatsApp aprobadas por Meta, quién las gestiona y sus costos.

**Datos e integraciones**
- **D-14.** Qué integraciones están disponibles para cada solución.
- **D-15.** Si los precios se publican por el chatbot y quién los mantiene.
- **D-16.** Origen de los catálogos (carga manual, importación o sincronización con el sistema del cliente).
- **D-21.** Pagos: si el chatbot puede cobrar o solo informar, y con qué pasarela.
- **D-22.** Delivery: opciones, adicionales y combos del menú.
- **D-23.** Delivery: cómo se define la cobertura (zonas por lista o geocodificación) y cómo se calcula el costo y el tiempo.
- **D-24.** Delivery: repartidores propios, plataforma externa o ambos.
- **D-25.** eCommerce: plataformas de tienda a soportar primero (por ejemplo WooCommerce, Shopify) y sus capacidades [Verificar].
- **D-26.** eCommerce: si el chatbot cierra ventas, crea pedidos o aplica descuentos, o solo informa y deriva.
- **D-27.** eCommerce: transportadoras y cómo se obtiene el seguimiento.
- **D-29.** Servicios y Reservas: reservas de espacios o recursos además de citas.
- **D-30.** Tarjetas de regalo: alcance (solo orientar o también vender) según lo que permita la API de Booker.

## 12. Cómo se mantiene este documento

- Al terminar un módulo se marca su estado [Existe] con los archivos y las pruebas que lo respaldan, y se actualiza `pendientes.md` según su metodología.
- Una decisión confirmada se traslada a `reglas.md` como decisión permanente y se elimina de la sección 11, dejando el identificador resuelto en el módulo afectado.
- Una capacidad de un tercero verificada con su documentación oficial reemplaza la etiqueta [Verificar] e indica la fuente.
- Si el alcance de un módulo cambia, se actualiza aquí antes de empezar a desarrollarlo.
