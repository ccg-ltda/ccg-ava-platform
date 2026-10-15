# Tratamiento de datos en Ava Platform — propuesta de política

> **Estado: PROPUESTA TÉCNICA para revisión jurídica y comercial.** No es asesoría legal ni un texto contractual definitivo. No fija obligaciones legales, plazos de conservación ni textos de consentimiento: donde hace falta una decisión, está marcada como **[DECISIÓN]** y resumida en `pendientes.md`. Lo que sí describe es lo que el código hace hoy y lo que todavía no hace.

## 1. Roles y responsabilidades (propuesta)

| Parte | Rol propuesto | Responsabilidades |
|---|---|---|
| **Cliente** (el negocio dueño de un Workspace) | Responsable del tratamiento de los datos de *sus* contactos finales | Decidir finalidades, obtener y acreditar el consentimiento, atender solicitudes de sus contactos, decidir cuánto conserva, informar a sus contactos. |
| **CCG** (opera Ava Platform) | Encargado del tratamiento por cuenta del cliente; responsable de los datos de las cuentas del personal | Tratar los datos solo para prestar el servicio, aplicar medidas técnicas (aislamiento, accesos, auditoría), avisar de incidentes, no usar el contenido de los clientes para otros fines. |
| **Proveedores** (Meta/WhatsApp, n8n, almacenamiento, futuros proveedores de correo o IA) | Subencargados | Contrato propio, lista de proveedores comunicada al cliente. **[DECISIÓN]** n8n: confirmar si su licencia permite el modelo de servicio (ya anotado en `pendientes.md`). |

**[DECISIÓN jurídica]** Confirmar esta distribución de roles según la normativa aplicable en cada país donde operen los clientes (CCG, República Dominicana, Colombia, España...) y reflejarla en el contrato de servicio y en un acuerdo de tratamiento de datos.

## 2. Qué datos trata Ava, con qué finalidad y qué sensibilidad tienen

| Categoría | Dónde vive | Finalidad | Sensibilidad | Quién la ve hoy |
|---|---|---|---|---|
| Cuentas del personal (nombre, correo, rol) | `users`, `workspace_user` | Acceso y permisos | Media | Admins del Workspace; administración de plataforma (correo enmascarado fuera de su Workspace en Reportes y Auditoría) |
| Contactos finales: identificador de canal, nombre | `conversations` | Atender la conversación | **Alta** | Personal autorizado del Workspace (`view-conversations`). Otro Workspace: **solo con acceso temporal justificado, enmascarado** |
| Contenido de mensajes | `messages` | Atender la conversación, historial | **Muy alta** | Igual que arriba. Nunca en auditoría ni en logs |
| Clientes (nombre, teléfono, correo, notas, etiquetas, consentimiento) | `customers`, `customer_identities`, `customer_tags` | Conocer a quién se atiende y a quién se puede escribir con consentimiento | **Alta** | Personal del Workspace con `view-customers`. Nunca otro Workspace |
| Reservas (nombre, teléfono, correo, servicio, hora) | `booking_customers`, `appointments` | Gestionar citas | Alta | Personal del Workspace con `view-bookings` |
| Campañas (mensaje, destinatarios, exclusiones) | `campaigns`, `campaign_recipients` | Comunicaciones con consentimiento | Alta | Personal del Workspace con `view-campaigns` |
| Credenciales y tokens (WhatsApp, n8n, Booker, token del asistente) | `integrations`, `chatbots` | Conectar servicios | **Crítica** | Cifradas, solo escritura: nadie las ve completas, tampoco la administración |
| Auditoría (quién hizo qué) | `audit_logs` | Trazabilidad | Media | Admins del Workspace; plataforma. Sin nombres de contactos, sin contenido; IP y correo ocultos entre Workspaces |
| Datos administrativos (Workspaces, planes, módulos, estados, conteos) | `workspaces`, `plans`, `modules`... | Operar la plataforma | Baja | Administración de plataforma, sin permiso especial |

## 3. Controles técnicos implementados

1. **Aislamiento por Workspace** en consultas, rutas, API, tareas y selección masiva: toda consulta parte del Workspace de la sesión; claves foráneas compuestas impiden enlazar filas de otro Workspace (verificado en PostgreSQL). Probado en `WorkspaceIsolationSweepTest`, `CustomersTest`, `CampaignsTest` y `PrivacyTest`.
2. **Separación entre administrar y mirar datos privados.** Los permisos comerciales (`manage-plans`, `manage-modules`) no dan acceso a datos de clientes. Ser superusuario tampoco: ver conversaciones de *otro* Workspace exige además `access-private-data` (rol `soporte`, sin asignar a nadie por defecto, ni siquiera a `admin`) y un **acceso temporal** abierto con un motivo escrito.
3. **Acceso temporal de soporte** (`support_accesses`): un Workspace, un tipo de dato, motivo obligatorio (mínimo 15 caracteres), vigencia 30 minutos, revocable, solo lectura, contactos enmascarados, sin búsqueda por contacto. Quedan en la **auditoría del Workspace consultado**: la apertura (quién, tipo, motivo, vencimiento) y cada consulta (una por conversación cada pocos minutos), sin guardar el contenido leído.
4. **Auditoría sin datos personales de contactos:** los registros de conversaciones y clientes se identifican por `#id`, no por nombre o número; los cambios de nombre, teléfono, correo y notas de un cliente se auditan como «cambió» sin guardar el valor; los registros antiguos que tuvieran un nombre se muestran como `#id`.
5. **Enmascaramiento entre Workspaces:** el Dashboard de otro Workspace nombra a sus contactos solo como `••••1234`; Reportes y Auditoría ocultan el correo completo y la IP de quien actuó en otro Workspace.
6. **Secretos:** las credenciales se cifran, son de solo escritura y no se serializan, registran ni auditan. Los logs de la aplicación llevan solo identificadores y códigos fijos (comprobado por una prueba automática que revisa las llamadas a `Log::`).
7. **Consentimiento comercial:** por defecto `Sin registrar` (cuenta como NO). Solo `Autorizado` (con constancia de cómo se obtuvo) puede recibir campañas; `Dado de baja` siempre excluye; cada exclusión queda con su motivo.
8. **Anonimización de un cliente** (a petición): borra nombre, contacto, notas, etiquetas e identificadores de canal de la ficha, la deja inactiva y con consentimiento revocado, y es irreversible y auditada. **No** borra las conversaciones, mensajes ni reservas de esa persona (ver §5).
9. **Sin envíos reales** de campañas ni conexiones externas nuevas: la simulación no toca ningún canal.
10. **Un superusuario no entra a un Workspace cliente.** `is_superuser` solo permite entrar a `Desarrollo_DEV`; para un Workspace cliente hace falta una membresía explícita (visible en la auditoría de ese Workspace) y con ella solo tiene el rol de esa membresía. Antes de esta corrección (2026-10-16) un superusuario podía abrir el Workspace de un cliente con su código y actuar como `admin`, leyendo sus conversaciones sin acceso de soporte: se cerró y está probado (`WorkspaceAccessTest`, `PlatformBoundaryTest`). Dentro de un Workspace cliente tampoco edita el catálogo de roles, ni Workspaces, ni Organizaciones, ni recibe permisos de plataforma.
11. **Correos del equipo de otro Workspace enmascarados** en la vista de plataforma de Usuarios y roles > Workspaces > Miembros (`a•••@dominio`): alcanza para distinguir a las personas y administrar su membresía. El nombre y el rol siguen visibles.
12. **Qué ve un administrador de Ava** sin acceso de soporte: Workspaces, organizaciones, planes, módulos y permisos; conteos y gráficos agregados por estado y periodo; nombres de contactos solo como `••••1234`; estado técnico de integraciones y canales (sin credenciales); instrucciones de asistentes y configuración no secreta (solo lectura); nombre y rol (no correo) del equipo de otro Workspace. **No ve** el contenido de mensajes, nombres, teléfonos ni correos de contactos, fichas de clientes, campañas ni reservas de otro Workspace (`?workspace=` se ignora en Clientes, Campañas y Reservas), ni las credenciales, ni la IP o el correo de quien actuó en otro Workspace.

## 4. Ciclo de vida de los datos — estado y propuesta

| Tema | Hoy | Propuesta / decisión pendiente |
|---|---|---|
| **Finalidades y categorías** | Definidas en la tabla del §2 (y en `reglas.md`). | **[DECISIÓN]** Redactar el aviso de privacidad por cliente; Ava no muestra todavía información de privacidad a los contactos finales (el widget no la tiene). |
| **Consentimiento, preferencias y bajas** | Estado por cliente, constancia, canal preferido; la baja manual excluye de campañas. | Falta la baja iniciada por el propio contacto (STOP) y su registro automático. **[DECISIÓN]** si una conversación iniciada por el contacto cuenta como consentimiento para responder (no para campañas). |
| **Conservación y eliminación** | Los mensajes y conversaciones **no tienen política de retención ni borrado**; las cuentas y Workspaces nunca se borran; los registros de auditoría solo crecen. | **[DECISIÓN jurídica y comercial]** plazos por categoría (mensajes, clientes, auditoría, copias de seguridad) y si cada cliente los configura. No se inventan plazos aquí. |
| **Consulta, corrección y eliminación (solicitudes)** | Corrección: edición de la ficha. Eliminación: anonimización de la ficha. Consulta/portabilidad: **no hay exportación** de los datos de una persona. | Definir el procedimiento (el cliente responsable atiende; CCG apoya con herramientas), una exportación por persona y la eliminación de sus conversaciones y reservas. Requiere decisión sobre qué se conserva por obligación legal. |
| **Acceso de personal y proveedores** | Permisos por rol dentro del Workspace; soporte de CCG solo con acceso temporal justificado; credenciales de proveedores cifradas. | Lista de proveedores y subencargados para el contrato; revisión periódica de quién tiene `access-private-data`. |
| **Exportación de datos** | El PDF de auditoría (sin datos de contactos); no hay exportación de clientes ni conversaciones. | Cualquier exportación futura debe llevar permiso propio, auditoría y el mismo aislamiento. |
| **Incidentes y trazabilidad** | Auditoría de cambios administrativos, accesos de soporte y eventos automáticos. | **[DECISIÓN]** procedimiento de notificación de incidentes (a quién, en qué plazo según contrato/ley), alertas sobre accesos de soporte. Faltan eventos de inicio de sesión y de lectura de conversaciones por el propio personal del Workspace (hoy solo se audita el acceso de soporte). |

## 5. Límites que hay que conocer (no ocultar)

- La anonimización de un cliente **no elimina** sus conversaciones, mensajes ni reservas: aún contienen su teléfono o nombre. Ofrecer «eliminar a esta persona» completa exige además borrar o anonimizar esos módulos, con una decisión sobre qué debe conservarse.
- El contenido de las conversaciones lo ve el personal autorizado del propio Workspace sin enmascarar (es su trabajo); el control de esta propuesta protege frente a la administración de CCG, no frente al propio cliente.
- Las **instrucciones** de un asistente y la configuración de canales (sin credenciales) siguen siendo visibles en solo lectura para la administración de plataforma: se tratan como configuración, no como datos personales. **[DECISIÓN]** si el cliente debe poder ocultarlas.
- La cuenta del personal de otro Workspace (nombre y rol) es visible para la administración de plataforma, necesaria para soporte. El correo y la IP se ocultan en Reportes, Auditoría y en la lista de miembros de otro Workspace. Para añadir una cuenta existente a un Workspace el administrador escribe el correo (lo conoce o se lo dio el cliente); la lista no lo revela.
- Nada de esto garantiza por sí solo el cumplimiento legal: depende de contratos, avisos y procedimientos que aún no existen.

## 6. Decisiones pendientes (resumen)

> **Cerrado técnicamente (2026-10-16): la vía indirecta por membresías.** Una cuenta interna de CCG (superusuario o miembro de `Desarrollo_DEV`) no puede añadirse ni añadir a un colega a un Workspace cliente, ni subir de rol allí, desde Workspaces > Miembros ni desde Usuarios; la ruta, no solo la interfaz, lo rechaza (`PlatformBoundaryTest`). Los administradores de un cliente siguen creando y gestionando a sus miembros. Sigue pendiente, y NO implementado, el acceso excepcional de soporte (D-P1 abajo): hasta entonces, denegado por defecto. Límite: la plataforma puede dar de alta una cuenta nueva en un cliente con una contraseña que conoce (alta del primer administrador); es auditable pero depende del procedimiento.

> **Decisión pendiente D-P1 — quién autoriza el acceso excepcional de soporte (no implementar el flujo hasta resolverla).** Hoy cualquiera con `access-private-data` abre un acceso de 30 minutos con motivo escrito y queda auditado en el Workspace del cliente, que lo ve DESPUÉS. Opciones: **(A)** solo autorización interna (quien tenga el permiso) más notificación inmediata al cliente; **(B)** autorización interna más un segundo aprobador de CCG; **(C)** aprobación explícita del cliente antes de abrir (un administrador del cliente acepta cada acceso, o lo concede de antemano en su contrato). La opción que se elija depende del contrato con cada cliente y del asesoramiento jurídico (no se decide aquí). Hasta entonces no hay concesión automática, no existe un permiso general ni se amplió el acceso a otros tipos de dato: la regla por defecto sigue siendo denegar.

1. Distribución de roles (responsable/encargado) y contrato de tratamiento de datos.
2. Vigencia del acceso de soporte (hoy 30 min como valor técnico) y quién puede tener `access-private-data`.
3. Plazos de conservación y borrado por categoría; copias de seguridad.
4. Procedimiento de solicitudes de los contactos (consulta, corrección, eliminación, portabilidad) y su exportación.
5. Texto de consentimiento y de privacidad para el widget, WhatsApp y las campañas; baja automática (STOP).
6. Si una conversación iniciada por el contacto vale como base para responder y para guardar.
7. Procedimiento de incidentes.
8. Lista de subencargados (Meta, n8n, almacenamiento, futuros proveedores) y licencia de n8n.

---

# Parte II — Arquitectura de privacidad administrativa (propuesta por fases)

> Esta parte **define** la arquitectura objetivo y el orden para llegar a ella. Lo ya construido (Fase 0) se describe como tal; **las Fases 1 a 4 NO están implementadas** y la 2 y la 3 requieren aprobación funcional o jurídica antes de tocarse. No se elimina información existente ni se fijan plazos legales.

## 7. Principio y niveles de dato

Administrar la plataforma (planes, módulos, Workspaces, permisos, estado técnico) **no** implica poder leer lo que los clientes finales de un Workspace escribieron ni quiénes son. Se separan cinco niveles:

| Nivel | Qué contiene | Ejemplos | Regla de acceso de la administración de CCG |
|---|---|---|---|
| **N0 Operativo** | Estructura y estado para operar la plataforma | Workspaces, organizaciones, planes, módulos, estados de integración, activo/inactivo | Libre, es su trabajo. |
| **N1 Agregado** | Cifras sin identificar personas | Conteos de conversaciones por estado, mensajes por periodo, citas por estado | Libre con permiso de panel; sin identificadores. Con pocos datos, riesgo de singularizar (ver §10). |
| **N2 Configuración del cliente** | Cómo configuró su negocio, sin credenciales | Instrucciones del asistente, canales, número de WhatsApp del negocio, sitios permitidos, equipo (nombre y rol) | Solo lectura para soporte; **[DECISIÓN]** si requiere permiso propio y aviso (§10). |
| **N3 Datos personales de contactos y contenido privado** | Quiénes son los contactos finales y lo que escribieron | Mensajes, nombre/teléfono/correo de contactos, fichas de Clientes, destinatarios de Campañas, datos de reservas | **Nunca** automático. Solo con acceso excepcional: temporal, justificado, limitado al Workspace y al tipo de dato, auditado en el Workspace consultado y sin guardar lo leído. |
| **N4 Secretos** | Credenciales y tokens | Tokens de WhatsApp/n8n/Booker, token del asistente, contraseñas | **Nunca** se muestran completos, ni siquiera con acceso excepcional; solo escritura y cifrados. |

## 8. Inventario de exposiciones (directas e indirectas)

Estado verificado en el código. «Fase» = cuándo se cierra el hueco que queda.

| Superficie | Qué puede ver hoy un administrador de plataforma (superusuario en `Desarrollo_DEV`) | Riesgo | Estado / Fase |
|---|---|---|---|
| **Conversaciones** (bandeja de otro Workspace) | Solo conteos por estado. Contenido solo con acceso de soporte vigente: contactos enmascarados, sin búsqueda por contacto, lectura auditada. | Alto si se abusa del acceso | **Fase 0 hecha.** F1: vista de «accesos de soporte» para los admins del cliente. F2: aviso previo y segundo aprobador opcionales. |
| **Clientes** | Nada: no existe vista de otro Workspace (`?workspace=` se ignora). | — | Hecho y probado (`PrivacyTest`). |
| **Campañas** (plantillas, destinatarios) | Nada de otro Workspace. En la auditoría propia, el texto de una **plantilla** se registra en claro (contenido comercial, puede incluir texto del cliente). | Bajo/medio | F1: auditar la plantilla como «cambió» (enmascarada), como ya se hace con el mensaje de una campaña. |
| **Reservas** (clientes, citas) | Nada de otro Workspace. La auditoría de citas lleva «Cita #id · servicio», sin contacto, pero el **motivo de cancelación** es texto libre (podría contener datos). | Medio | F1: enmascarar el texto libre en auditoría. |
| **Asistentes** | `?workspace=` en solo lectura: nombre, **instrucciones** (puede contener información comercial) y estado de canales; el token nunca. | Medio (propiedad intelectual del cliente) | Nivel N2. F2: permiso propio para leerlo. |
| **Integraciones** | `?workspace=` en solo lectura: tipo, estado y configuración **no secreta** (URLs, sitios permitidos, ID de ubicación, número visible). Credenciales: solo escritura, cifradas. | Medio-bajo | N2/N4. F2: permiso propio; revisar qué campos «no secretos» son realmente necesarios. |
| **Usuarios y roles** | Pestaña Workspaces > Miembros: **nombre, correo completo y rol** de cuentas de cualquier Workspace (administrar membresías). | Medio (datos del personal del cliente) | F2: enmascarar o pedir permiso; hoy es la herramienta de administración. |
| **Reportes / Dashboard** | Agregados; «conversaciones recientes» con contacto enmascarado; usuarios recientes con correo enmascarado fuera del Workspace propio. | Bajo | Hecho. F1: umbral mínimo para cifras muy pequeñas. |
| **Auditoría** | De otro Workspace: acciones, personas del equipo (nombre), **sin IP ni correo completo**, registros de contactos como `#id`, valores sensibles enmascarados. | Bajo | Hecho. F1: revisar campos libres restantes (plantillas, motivos). |
| **Exportaciones** | Solo el PDF de auditoría (sin datos de contactos). No hay exportación de clientes ni conversaciones. | Bajo hoy | Cualquier exportación futura: permiso propio + auditoría + aislamiento (criterio de aceptación, F3). |
| **Logs de aplicación** | Solo identificadores y códigos fijos; una prueba automática vigila `Log::` y los jobs no llevan texto. | Bajo | Hecho; mantener la prueba. F4: política de retención de logs. |
| **Cola y Horizon** | Los jobs llevan ids, no texto; Horizon sin acceso fuera de local (gate cerrado). | Bajo | F4: decidir quién accede a Horizon (ya en pendientes) y confirmar que ningún job futuro lleve texto. |
| **Widget y páginas públicas** | Responden solo lo necesario por clave; sin ids de Workspace ni datos de contacto de otros. | Bajo | Hecho. |
| **Proveedores** (n8n recibe el historial corto de una conversación para el asistente; Meta) | Terceros reciben datos de contactos por diseño. | Alto (contractual) | F3/F4: lista de subencargados y contrato. |
| **Base de datos, copias, secretos de infraestructura** | Quien tenga credenciales de la base ve todo, fuera de los controles de la aplicación. | Alto | F4: política de acceso, cifrado en reposo, retención de copias. |
| **Buscador de Workspaces** | Nombre, código y organización. | Bajo | — |

## 9. Arquitectura objetivo

1. **Dos planos de permiso, no uno.** *Administrar la plataforma* (`manage-plans`, `manage-modules` y, a futuro, permisos de panel y de configuración) frente a *acceder a datos privados* (`access-private-data`). Ningún permiso del primer plano da acceso al segundo, y **ser superusuario no concede ninguno de los del segundo plano**. (Hoy es así para N3; N1 y N2 todavía se obtienen por ser superusuario en `Desarrollo_DEV`.)
2. **Agregado por defecto.** Reportes, Dashboard y supervisión técnica de otros Workspaces usan solo agregados o identificadores enmascarados (N0/N1).
3. **Acceso excepcional de soporte** (N3), con estas propiedades, ya implementadas para conversaciones (Fase 0) y por extender a otros tipos solo si hace falta:
   - **Temporal** (vigencia corta, nunca indefinida) y **revocable**;
   - **Justificado** (motivo obligatorio);
   - **Acotado** a un Workspace y a un tipo de dato, de solo lectura;
   - **Mínimo**: contactos enmascarados, sin búsqueda por persona;
   - **Auditado en el Workspace consultado** (quién, cuándo, tipo, motivo) y **sin guardar el contenido leído**.
4. **Transparencia hacia el cliente.** Los admins del Workspace ven los accesos de soporte en su auditoría (hecho) y, en F1, una vista propia; el aviso previo es F2.
5. **Minimización en lo que se registra.** Auditoría y logs sin datos de contactos ni texto libre del cliente.
6. **Aislamiento estricto** en consultas, rutas, API, tareas, exportaciones y acciones masivas, verificado por pruebas en cada módulo nuevo (criterio de aceptación permanente).

## 10. Decisiones: cuáles se pueden cerrar y cuáles no

**Cerradas técnicamente (ya aplicadas o directas):**
- N3 nunca automático; N4 nunca visible; acceso de soporte temporal, justificado y auditado en el Workspace consultado; contactos como `#id` en auditoría; correo e IP ocultos entre Workspaces; Clientes y Campañas sin vista cruzada.
- No guardar contenido leído ni texto libre en auditoría (aplicable a lo que queda en F1).

**Requieren aprobación funcional (producto/CCG):**
- Quién recibe `access-private-data` y si hace falta un **segundo aprobador**.
- Si el cliente debe **aprobar o ser avisado antes** de un acceso de soporte (hoy lo ve después).
- Si N2 (instrucciones, canales, equipo) necesita permiso propio y cuándo se enmascara.
- Umbral mínimo para mostrar agregados de un Workspace con muy pocos datos (evitar singularizar a una persona): valor y si se aplica.
- Duración del acceso (hoy 30 min como valor técnico).

**Requieren aprobación jurídica (no se deciden aquí):**
- Roles responsable/encargado y contrato; subencargados; textos de consentimiento y privacidad; plazos de conservación y borrado; procedimiento de solicitudes de contactos; notificación de incidentes; cualquier afirmación de «cumplimiento».

## 11. Fases, riesgos y criterios de aceptación

| Fase | Contenido | Estado | Aprobación | Criterios de aceptación |
|---|---|---|---|---|
| **0 — Base** | Aislamiento por Workspace; acceso de soporte para conversaciones (`support_accesses`, `access-private-data`); enmascarado de contactos; auditoría sin nombres de contactos; anonimización de una ficha de cliente; Clientes y Campañas sin vista cruzada; logs vigilados; un superusuario no entra a un Workspace cliente sin membresía; correos de miembros de otro Workspace enmascarados. | **Hecha** (el cierre del acceso implícito del superusuario y el enmascarado de miembros se añadieron el 2026-10-16) | — | `PrivacyTest`: acceso directo por URL sin permiso → 403; sin concesión no hay contenido; la concesión no es transferible, expira y se revoca; el contenido no aparece en auditoría ni en respuestas. |
| **1 — Endurecimiento sin cambiar permisos** | Auditar plantillas y motivos de cancelación enmascarados; vista de «Accesos de soporte» para admins del cliente (solo lectura); umbral de agregados pequeños; vigencia configurable por entorno. | Propuesta | Funcional (umbral, avisos) | Una prueba por cada campo libre que antes se auditaba en claro; la vista solo lista accesos del propio Workspace; ningún agregado revela un conjunto menor al umbral. |
| **2 — Reestructuración de permisos** | Separar permisos de panel (N1), configuración del cliente (N2) y directorio de personal; reemplazar «superusuario elige Workspace» por un permiso explícito; segundo aprobador y aviso previo al cliente (opcional); ampliar tipos de acceso (Clientes, Reservas) solo si soporte lo necesita. | Propuesta, **NO implementar sin aprobación** | Funcional | Un administrador sin el permiso recibe 403 por URL y por API; migración de roles sin quitar acceso a quien lo tiene hoy; pruebas por cada permiso nuevo y por cada módulo con `?workspace=`. |
| **3 — Ciclo de vida de los datos** | Retención y borrado por categoría; solicitudes de contactos (consulta, corrección, eliminación, portabilidad) incluyendo conversaciones y reservas; exportación por persona con permiso y auditoría; textos de consentimiento y STOP. | Propuesta, **requiere decisión jurídica** | Jurídica + funcional | Procedimiento documentado y aprobado; borrado verificado en todos los módulos que referencian a la persona; exportación auditada y aislada. |
| **4 — Aseguramiento** | Política de acceso a base de datos y copias; cifrado en reposo; retención de logs; gate de Horizon; procedimiento de incidentes; revisión periódica de quién tiene acceso de soporte; prueba de penetración. | Propuesta | Infraestructura + jurídica | Evidencia por control; informe periódico de accesos de soporte. |

**Riesgos abiertos (a decidir antes del despliegue):**
1. Mientras no exista la Fase 2, un superusuario de `Desarrollo_DEV` sigue pudiendo leer N1/N2 (agregados, instrucciones, configuración, correo del personal) sin permiso adicional ni aviso.
2. El permiso `access-private-data` no está asignado a nadie por defecto: nadie de CCG puede diagnosticar conversaciones de un cliente hasta que un superusuario lo asigne conscientemente.
3. La anonimización de un cliente no alcanza conversaciones ni reservas (Fase 3).
4. Fuera de la aplicación (base de datos, copias, n8n, Meta) los controles de esta propuesta no aplican (Fase 4 y contratos).
5. El valor de 30 minutos es técnico, no una decisión de negocio.
