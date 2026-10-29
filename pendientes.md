# Pendientes

Solo trabajo abierto. Lo terminado y validado se retiró de este archivo: su historial está en Git y su evidencia en `RECOMENDACIONES.md` (las secciones se citan como §N). Reglas permanentes en `reglas.md`. Actualiza este archivo al cerrar o abrir una tarea.

**Base documental:** 2026-10-29, `main`, publicado tras la revisión pre-deploy de §67.8. Verifica el estado de Git antes de apoyarte en esta fecha.

**Niveles de evidencia** (mismo vocabulario que `RECOMENDACIONES.md`): *automatizada* (PHPUnit en SQLite en memoria o PostgreSQL desechable, `npm run test:js`, Pint, `npm run build`), *simulada* (`Http::fake`, dobles locales de Meta, n8n, Booker y Wompi), *navegador* (Playwright contra una base desechable) y *real* (servicio externo o `ccg_ava`). **No existe ninguna validación real de Meta, n8n, Booker, Wompi, SIGO ni correo.** Una prueba simulada que pasa no cierra un pendiente de integración.

---

## 1. Validación incompleta (implementado, falta comprobar)

| Id | Qué falta comprobar | Se cierra cuando |
|---|---|---|
| V1 | **Contraste de bordes (§66, §67.2).** El token `--color-field` ya se ajustó a ≥3:1 (cálculo, build y resolución de `.field` comprobados). No se ha visto la interfaz con sesión iniciada en claro/oscuro a 390 y 1440 px, ni los componentes que lo comparten (campos, `Select`, botón secundario, casillas, control segmentado, selector de color, filtros del Historial de API), ni lectores de pantalla ni una pasada completa de teclado | Revisión con Playwright en una base desechable (procedimiento en §67.6) o manual, con resultado anotado. Lectores de pantalla: solo con evidencia de que se hicieron |
| V2 | **Comprobaciones posteriores a la migración `2026_11_13_100000_create_payments_notifications_api_log_and_context` (§67.1, §67.5).** Una inspección de solo lectura del 2026-10-29 la mostró aplicada en `ccg_ava` (lote 36, tablas vacías); el estado actual no se ha vuelto a consultar y no consta quién la aplicó. Falta ver con el esquema real: Configuraciones (cierre automático), campanita, Historial de API, panel de contexto, `integrations:diagnose`, `conversations:maintain`, y si Horizon/worker/scheduler conservan «tabla no existe» y necesitan reinicio. **No ejecutar `migrate:rollback` para esta migración en `ccg_ava`: `down()` borra tablas y columnas con sus datos** | Cada comprobación hecha con autorización y resultado anotado |
| V3 | **Última suite PHP completa registrada: §62.1** (2 258 pruebas, 2 fallidas). Las dos fallas se atendieron después (§63.1, §63.2) con pruebas focalizadas; no consta una suite completa posterior | Suite completa en el anfitrión (comando de §62.1) sin fallos, antes de cualquier despliegue |
| V4 | **Concurrencia de inventario en PostgreSQL.** `InventoryConcurrencyTest` solo comprueba restricciones y requiere una base PostgreSQL desechable; la carrera de dos ventas simultáneas con dos conexiones no existe. La reserva concurrente sí tiene prueba en PostgreSQL (§56.6) | Base desechable autorizada (nunca `ccg_ava`; `ccg_ava_test` está vetado), prueba ejecutada y, si se quiere, prueba de dos procesos |
| V5 | **Pantallas rediseñadas pendientes de revisión visual:** modo oscuro, tablet y móvil de Pedidos, Catálogo, Campañas, Regional, editor de mensajes de Agenda y paneles de Configuraciones e Integraciones | Revisión anotada |

## 2. Implementación pendiente (sin dependencias externas)

| Id | Qué falta | Notas |
|---|---|---|
| I1 | **Guardia en `e2e:setup` / `E2eFixtures`** (§67.6): solo se niega con `APP_ENV=production`; con el `.env` actual (`pgsql`/`ccg_ava`) sembraría usuarios y un Workspace en la base real | Rechazar cuando la base resuelta sea `ccg_ava` o no sea desechable; probarlo |
| I2 | **Selector de workflow por el cliente (§63.4, §65.2).** El catálogo (`config/n8n.php`) lo gestiona la plataforma y la asignación va por consola (`workflows:manage assign`) | Requiere permiso `select-workflow` y auditoría; antes, la decisión D-IA (sección 4) |
| I3 | **Flujo antiguo del widget web** (§35.3): no se usa si hay límite de IA, pero no está unificado con las ejecuciones medidas | Confirmar que sigue vigente antes de tocarlo |
| I4 | **Datos de prueba `[TEST]` en `ccg_ava` (§35.5)** y limpieza de los datos demo antes de un despliegue (`demo:data clean`, `demo:conversations clean`, con `--confirm=<código>`) | Nunca sin orden expresa y copia previa; decidir qué se conserva |
| I5 | **Conducción de reservas y pedidos por el asistente:** `/api/agent/booking/*` y `/api/agent/orders/*` están probados contra la API de Ava; ningún workflow real los llama | Depende del workflow real (sección 3, n8n) |
| I6 | **Brechas funcionales conocidas:** envío real de Campañas (hoy `UnavailableCampaignDispatcher`); devoluciones de pedidos (`delivered` es final); reintentar un mensaje `failed`; descargar y reproducir multimedia; tiempo real con Reverb en vez de refresco; Instagram y Messenger; módulos Combos, Cupones, Domicilios y Caja; módulos propios de Salud, Delivery y eCommerce; informes de citas y pedidos; Preguntas y Encuestas en Reportes; filtros guardados para Usuarios, Workspaces y Organizaciones; multidioma y configuración fiscal por jurisdicción | Cada una requiere decisión de alcance; no iniciar sin ella. Lista heredada de documentos anteriores, no reverificada una por una en esta depuración |
| I7 | **Calidad:** CI (tests + Pint + `composer audit`) en cada push; automatizar la prueba de concurrencia en PostgreSQL con una base `*_test` fija. No hay pruebas de navegador automáticas: la interfaz se revisa a mano con Playwright | — |

## 3. Bloqueado por una integración externa o una autorización

### n8n (soporte y workflows)
- **Falta:** definición del primer workflow de soporte (lógica, cuándo hace `handoff`, si usa modelo de lenguaje y cuál); instancia n8n de pruebas por HTTPS y secreto de ejecución (≥16 caracteres) guardado en Integraciones del Workspace de pruebas; `webhook_path`, `n8n_workflow_id`, `version` y clave para `config/n8n.php` (el catálogo `workflows` está **vacío**); Workspace de pruebas con plan que incluya Asistentes y Conversaciones y chatbot de soporte activo; autorización expresa para registrar y asignar el workflow, hacer la llamada real y procesar la cola.
- **Contrato y criterios de aceptación:** `N8N_WORKFLOW_REFERENCIA.md` y §62.7. Arquitectura por Workspace y aislamiento: implementados y probados solo con HTTP simulado (§65).
- **Se cierra cuando:** el escenario de aceptación (nombre → teléfono → agente pide correo → devuelve con y sin «continuar ahora») pasa contra un n8n real, con firma verificada, sin preguntas repetidas y con el contexto actualizado.
- **Licencia:** confirmar con n8n que permite este modelo de servicio (credenciales propias de cada cliente) antes de uso comercial.

### Wompi
- **Falta:** dominio HTTPS público con `https://<dominio>/api/webhooks/wompi` registrado en Wompi; llave pública `pub_test_…`, secreto de integridad y secreto de eventos de Sandbox cargados desde Configuraciones > Impuestos y facturación; plan de pruebas con precio y un correo propio; tarjetas de prueba (aprobada y rechazada); autorización para «Validar», el pago de prueba y el webhook.
- **Se cierra cuando:** el procedimiento de §64.3 se ejecuta en Sandbox: el pago aprobado activa el Workspace una sola vez aunque Wompi reenvíe, el rechazado no activa nada y el historial de llamadas no guarda cuerpos ni datos del pagador. Producción solo después, con llaves `pub_prod_`.
- Estado actual: código revisado contra el contrato público y probado con respuestas simuladas (§64.3, §65.4); sin conexión, transacción ni webhook reales.

### Renovaciones, cambios de plan y cobros recurrentes
- **No implementados:** hoy solo la contratación pública cobra por Wompi (§65.4). Faltan reglas comerciales que nadie ha definido: consentimiento y texto legal del cobro recurrente, calendario (anticipación, día de cobro), prorrateo y crédito en cambios de plan (inmediato o al final del periodo), reintentos y periodo de gracia, cancelación y reembolsos, qué ve el cliente. Confirmar en la documentación vigente de Wompi la tokenización de fuentes de pago.
- **Técnicamente:** referencia de pago por cobro ligada a `Subscription`, el mismo camino verificado `WompiEvents → SubscriptionService::confirmPayment` y pruebas de idempotencia por periodo. No empezar sin las reglas.
- Decisiones de planes aún abiertas: D-PL1 a D-PL7, D-LIM1 a D-LIM3, D-PRU1, D-PRU2, D-SES, D-OF1 a D-OF5 (§32.5, §33.9, §34, §29.4, §36.5): licencia gratuita, pruebas, sesiones y cuotas, vencimiento, descuento anual, ofertas cambiadas tras aceptar, reembolsos.

### SIGO (facturación)
- **No existe** cliente, configuración ni credenciales (§64.6). Faltan: documentación oficial de la API (autenticación, URL de pruebas y producción, emisión, errores, idempotencia y numeración), credenciales de un entorno de pruebas, campos fiscales y resolución de facturación, y la regla de cuándo se factura.
- Mantener separados «pago confirmado» (Wompi) y «factura emitida» (SIGO): hoy el historial solo dice «pendiente de facturación», nunca «facturado».

### Meta y WhatsApp
- **Falta:** app de Meta con el producto WhatsApp, número con su `phone_number_id`, App Secret, token permanente, suscripción al campo `messages` con `META_WEBHOOK_VERIFY_TOKEN`, dominio HTTPS (P1) y plantillas aprobadas para escribir fuera de la ventana de 24 h. Un número no puede tener a la vez este webhook y el WhatsApp Trigger de n8n.
- **Por confirmar contra Meta real:** el estado devuelto con `biz_opaque_callback_data`; Meta no ofrece idempotencia al enviar (un envío incierto queda `unconfirmed` y lo verifica una persona).
- **Se cierra cuando** el ciclo de P10 se ejecuta con una cuenta real: mensaje firmado, respuesta, estados, duplicado, fallo provocado y traspaso a persona.

### Booker
- Implementado y **solo simulado** (API Merchant y modo `customer`). Falta: Client ID, Client Secret y Subscription Key de Staging, `Location ID` de pruebas y acceso al portal de desarrolladores; confirmar los supuestos A1 a A4 de `app/Booking/BookerMerchantCalendar.php` y las dudas del modo `customer` (forma de tratamientos y empleados, `IsHeld`, `IsCancelled`). Producción solo con `BOOKER_MERCHANT_VERIFIED=true` tras Staging y confirmación de Booker. Sin endpoint confirmado en modo Merchant: profesionales, cancelar, reprogramar y leer una cita.

### Correo
- **Nunca se ha enviado un correo real.** Falta: `MAIL_MAILER=smtp` con credenciales (Google Workspace: contraseña de aplicación), `MAIL_FROM_ADDRESS` propio con SPF/DKIM, `php artisan mail:test <destinatario>` y un worker que procese la cola (el `.env` del anfitrión usa cola `database` y el de Docker usa Redis: §49.1, §50.2). Se cierra cuando un enlace de recuperación o de invitación llega a un buzón real, se usa una vez, caduca y no cae en spam.

## 4. Decisiones de producto, comerciales o jurídicas

- **Planes y comercial:** módulos por plan y precios reales (ninguna oferta publicada con datos comerciales reales); límites, prueba gratuita y vencimiento; si un plan de consulta sigue mostrando el filtro por asistente; cuándo volver `plan_id` obligatorio.
- **D-IA:** responder directo o delegar en n8n (hoy el workflow es el asistente; una IA propia de Ava sería un módulo nuevo con proveedor, costos y privacidad).
- **Conversaciones:** qué hace la IA con una conversación `pending` que nadie toma; asignación automática; texto aprobado de `CHATBOT_FAILURE_NOTICE` (vacío = no se envía nada); destino de la alerta de D-FALLO (`ALERT_LOG_CHANNELS`).
- **Retención** (P-RET-REG-1 a 6): plazos para mensajes, conversaciones, clientes, reservas, auditoría, trazas, verificaciones y borradores de registro, pagos, logs de llamadas y copias; si se auditan inicios de sesión y exportaciones.
- **Registro público:** proveedor de CAPTCHA y de pagos; si `/registro/disponibilidad` exige correo verificado; si se programa `signup:reconcile --retry`.
- **Textos legales:** CCG debe fijar en `LEGAL_*` razón social, identificación tributaria, dirección, contacto, canal de derechos, jurisdicción y vigencia; revisión jurídica del texto; luego `LEGAL_DOCUMENTS_REVIEWED=true`, versión en `config/legal.php` y cuándo activar `LEGAL_ACCEPTANCE_REQUIRED=true`. Roles responsable/encargado y subencargados (Meta, n8n, almacenamiento), consentimiento del contacto final, procedimiento de solicitudes de datos (¿módulo o manual?), notificación de incidentes y acceso excepcional de soporte (D-P1; `access-private-data` no está asignado a nadie). Detalle en `docs/privacidad.md`.
- **Permisos y plataforma:** roles por defecto para los permisos nuevos; si `Desarrollo_DEV` puede editar otro Workspace (hoy solo lee) y con qué auditoría; permisos sueltos por persona; visibilidad del personal de un cliente para la plataforma (fases F1 a F4 de `docs/privacidad.md`).
- **Pedidos y catálogo:** cancelación por el cliente, alta automática de fichas de Clientes, avisos de pedido, cobertura y tarifas de Delivery, pagos, variantes, precios por el chatbot, datos clínicos de la cita (D-07).
- **Meta, Dashboard y canal web:** secreto de aplicación por Workspace, mensajes que no son texto, costo de plantillas, límite del webhook (600/min por IP, estimado); si un rol sin `view-conversations` ve los agregados de conversaciones; hacer opcional la URL del webhook de la integración `web`.
- **Visual:** si el borde de 3:1 de V1 es del gusto del diseño (3:1 es el mínimo normativo).

## 5. Despliegue controlado (primer entorno real)

Aplica a cada entorno nuevo; ninguno se ha ejecutado contra producción (no existe).

| Id | Qué falta | Se cierra cuando |
|---|---|---|
| P1 | Dominio con HTTPS para `APP_URL` y `TRUSTED_PROXIES` | `https://<dominio>/api/webhooks/whatsapp` responde la verificación GET de Meta |
| P2 | Variables del servidor (`META_*`, `N8N_*`, `ADMIN_*`, `HORIZON_ALLOWED_EMAILS`, `ALERT_LOG_CHANNELS`, `CHATBOT_FAILURE_NOTICE`, `MAIL_*`) | `docker compose -f docker-compose.prod.yml config` sin error con el archivo real e `integrations:diagnose` con 0 bloqueos |
| P3 | Migraciones y semillas en orden: `migrate --force`, `db:seed --class=RolesAndPermissionsSeeder`, `--class=ModuleCatalogSeeder`, y revisar los roles | `migrate:status` sin pendientes y cada rol con solo lo que debe |
| P4 | Primer administrador y rol `plataforma` en `Desarrollo_DEV` | Un superusuario administra Planes en el entorno |
| P5 | Un worker (Horizon) y un scheduler (una réplica) en marcha | `integrations:diagnose` lee latido, Horizon y comandos programados al día |
| P6 | Copia de seguridad programada (`cron`) y restauración probada en el servidor real | Una restauración verificada de una copia del servidor |
| P7 | Responsable que revise a diario `alerts.log`, `integrations:diagnose`, `executions:list --failed` y la bandeja «Pendientes» | Responsable nombrado |
| P8 | Workflow de n8n adaptado y registrado (sección 3, n8n) | El workflow responde `{execution_id, reply, handoff}`, verifica `X-Ava-Signature` y no envía por WhatsApp por su cuenta |
| P9 | Confirmar que el bucket de Spaces es privado y sin CDN público | Comprobado en el servicio real |
| P10 | Primera prueba real de punta a punta (`RECOMENDACIONES.md` §3.2 y §11.8) | Ver Meta y WhatsApp |
| P11 | Revisar los roles reales de clientes tras `RolesAndPermissionsSeeder` en cada entorno con roles anteriores | Cada rol conserva lo que podía hacer |
| P13 | **Dependencia con aviso de seguridad (§67.8):** `composer audit` reporta CVE-2026-45070 (gravedad media, inyección de cabeceras en `symfony/mime`) y la versión instalada es 8.1.6 (corregida desde 8.1.10) | `composer update symfony/mime` (y dependientes) con la suite en verde, y `composer audit` sin avisos |
| P12 | Auditoría de seguridad externa antes de abrir a clientes reales; cifrado y procedimiento de incidentes | Informe recibido |

**Operación:** quién figura en `HORIZON_ALLOWED_EMAILS` (vacío = nadie); proxy propio o balanceador y Postgres/Redis gestionados; no-root completo en `app`; alternativa a la imagen congelada `bitnamilegacy/minio` (solo desarrollo); en Windows, `docker compose restart node` si Vite no ve cambios; retención y archivado de `audit_logs`, `agent_tool_calls`, `api_call_logs` y mensajes (solo crecen); si vuelven a desaparecer membresías de `DESARROLLO_DEV` (ocurrió dos veces sin causa), comparar con una instantánea previa.

**Entorno del anfitrión:** dos copias de la aplicación pueden compartir `ccg_ava` con colas y discos distintos (§49.1, §50.2); revísalo antes de depurar correos, imágenes o colas. No proceses ni borres `jobs` atascados sin autorización: enviarían correos reales.
