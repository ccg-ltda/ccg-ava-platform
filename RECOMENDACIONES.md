# Recomendaciones: integraciones, despliegue y pruebas reales de Ava

Documento complementario ÚNICO. Las decisiones permanentes están en `reglas.md`, lo pendiente en `pendientes.md`, el plan en `PLAN_TRABAJO_AVACHAT.md`, la infraestructura en `INFRASTRUCTURE.md`, la política de datos en `docs/privacidad.md` y el mapa de módulos y planes en `docs/modulos-y-planes.md`. Aquí vive todo lo demás: estado de las integraciones, contrato del workflow, despliegue y pruebas reales, diagnóstico, riesgos y requisitos externos. **Los hallazgos y recomendaciones nuevos se añaden aquí; no se crea otro Markdown por bloque.**

Estado verificado el 2026-10-16. Vocabulario: **Implementado** (existe en el código) · **Simulado** (probado con pruebas automáticas y simuladores: `Http::fake`, agenda simulada) · **Navegador** (probado con Playwright en un entorno desechable, con un n8n de PRUEBAS local) · **Real** (probado contra el servicio de verdad) · **Decisión** (necesita aprobación). **Nada está probado en modo Real: Meta, n8n y Booker siguen pendientes de acceso.**

> **Referencias antiguas.** `reglas.md`, `pendientes.md` y `PLAN_TRABAJO_AVACHAT.md` aún citan `docs/integraciones.md` y `docs/reservas-pruebas-reales.md`, retirados al consolidarlos aquí. Equivalencias: integraciones §1 → §2.1; §2 → §2.2; §3 → §2.7; §4 (contrato) → §3.1; §5 (requisitos) → §6.1; §6 (pasos) → §3.2; §7 (bloqueos) → §5 y §6. Reservas §1 (flujo) → §2.3; §2 (D-IA) → §6.2; §3 → §2.5 y §2.7; §3b → §7.1; §3c → §7.2; §4 y §4b → §3.1 y §3.3; §5 → §6.1; §6 → §3.2; §7 → §5.

## 1. Estado técnico actual

| Área | Estado |
|---|---|
| Reservas y Servicios con la agenda propia de Ava (servicios, profesionales, horarios, disponibilidad, reservar, cancelar, reprogramar, página pública, herramientas de Ava Chat) | Implementado y Simulado; concurrencia verificada a mano en PostgreSQL (8 procesos, 1 reserva) |
| WhatsApp por Meta (webhook firmado, ejecución, envío, estados) | Implementado y Simulado. **Real: pendiente** |
| n8n de la plataforma (Ava inicia la ejecución) | Implementado y Simulado. **Real: pendiente** (credenciales, workflow, licencia) |
| n8n por Workspace (n8n → Ava con el token del chatbot) | Implementado y Simulado. **Real: pendiente** |
| Canal Web (widget) con workflow de la plataforma | Implementado, Simulado y Navegador (Chromium, entorno desechable) |
| Canal Web sin workflow (webhook propio del Workspace) | Implementado, Simulado y Navegador |
| Booker | **Parcial:** token, «Probar conexión», servicios, profesionales, leer y cancelar (solo Simulado; se basa en un cliente Ruby de código abierto, no en un Sandbox). **NO implementados:** horarios disponibles, reservar y reprogramar (`capabilities()` los declara en falso a propósito) |
| Atención humana, estados de entrega, duplicados, reintentos y recuperación | Implementado y Simulado |
| Permisos, planes, aislamiento y privacidad | Implementado y Simulado; ver `reglas.md` §7 y `docs/privacidad.md` |

Última validación completa (2026-10-16): PHPUnit 1067/1067, Pint, `npm run test:js` 14/14 y `npm run build` correctos. La suite usa SQLite en memoria; una instalación limpia en PostgreSQL con `CACHE_STORE=database` se verificó aparte (esquema idéntico al de desarrollo).

## 2. Arquitectura e integraciones

### 2.1 Cuál es la primera integración (análisis, no suposición)

| Integración | Qué existe | Qué falta para una prueba real |
|---|---|---|
| **Meta WhatsApp + n8n de la plataforma** | Webhook firmado, asociación número → integración → chatbot → Workspace, ejecuciones idempotentes, envío a la Graph API con un solo intento (`accepted/failed/uncertain`), recuperación, auditoría, bandeja con atención humana | Credenciales de Meta; un n8n con un workflow que cumpla §3.1; entrada en `config/n8n.php` |
| **n8n de cada Workspace** | `/api/agent/*` (configuración, mensajes, estados, autorización, transferencia), tarjeta con «Probar conexión» | Un n8n real con WhatsApp Trigger y nodos de Ava. No puede convivir con el webhook de Meta sobre el mismo número |
| **Booker** | Ver §1 | Sandbox y credenciales; implementar horarios, reservar y reprogramar cuando su respuesta esté verificada |

La ruta con más infraestructura ya construida es Meta WhatsApp con el n8n de la plataforma; no es una decisión de negocio, y la infraestructura común (§2.2) es la misma para cualquier otra.

### 2.2 Piezas que ya existen (y dónde)

- **Contratos y adaptadores:** `App\Integrations\IntegrationType` (+ `ChannelIntegrationType`, `CalendarProviderType`, `SendsMessages`), registro en `config/integrations.php`; `WhatsAppType`, `N8nType`, `WebType`, `BookerType`, `HttpApiType`. `ChatbotExecutor` (`N8nExecutor`) y `CalendarProvider` (`InternalCalendar`, `BookerCalendar`, `UnavailableCalendar`).
- **Configuración por Workspace:** una fila de `integrations` por conexión con claves foráneas compuestas; credenciales cifradas (`encrypted:array`), de solo escritura, fuera de logs, auditoría y respuestas.
- **Pruebas de conexión:** reales y de solo lectura (`WhatsAppType::test` lee el número en la Graph API; `N8nType::test` llama a `GET /api/v1/workflows?limit=1`), con mensajes fijos que no repiten secretos, límite de 20/min y salida solo por `SafeHttpTarget` (sin red interna ni redirecciones).
- **Tiempos de espera y reintentos:** Graph 10 s; n8n de ejecución 25 s (`N8N_EXECUTION_TIMEOUT`) con 3 intentos y esperas de 10 s y 60 s solo ante fallos temporales; el job de cola tiene un límite de 50 s por intento; las lecturas de Booker se repiten una vez, **las escrituras nunca**.
- **Idempotencia:** un mensaje entrante = una ejecución (`message_id` único); un evento repetido de Meta se guarda una vez; el envío a WhatsApp tiene a lo sumo un intento y, si el resultado es incierto, queda `unconfirmed` y nunca se reenvía solo (etiqueta `ava:{id}` en `biz_opaque_callback_data` para reconocerlo después).
- **Webhook de Meta** (`/api/webhooks/whatsapp`): 404 hasta definir `META_APP_SECRET` y `META_WEBHOOK_VERIFY_TOKEN`; verificación GET con el token; cada POST firmado (`X-Hub-Signature-256`, HMAC-SHA256 del cuerpo en bruto, comparación en tiempo constante) y rechazado con 403 antes de leer nada; cuerpo máximo 1 MB (413); JSON inválido 400; límite 600/min por IP; el Workspace nunca sale del evento (el `phone_number_id` solo sirve para BUSCAR la integración que Ava guarda: número desconocido, ambiguo o apagado = ignorado). Meta no firma una marca de tiempo: la protección contra repetición es la idempotencia por `external_id`, no una ventana de tiempo.
- **Aislamiento:** todo lleva `workspace_id` y la base rechaza cruces (verificado en PostgreSQL); un estado de entrega de un número no toca mensajes de otro Workspace.
- **Estados de entrega que solo avanzan:** `sin entregar < sent < delivered < read`; `failed` solo sustituye lo no entregado (`Message::replaceableBy`, actualización condicional), para Meta y para lo que reporta n8n.
- **Recuperación:** `executions:recover` cada minuto (scheduler de una sola réplica) y `--retry=ID` para una ejecución fallida sin respuesta.

### 2.3 El flujo de extremo a extremo

| # | Paso | Componente | Estado |
|---|---|---|---|
| 1 | Entra el mensaje | WhatsApp: `/api/webhooks/whatsapp` (`WhatsAppInbound`). Web: widget → `/api/widget/{clave}/messages` (`WebWidget::relay`) | Simulado (Web: también Navegador) |
| 2 | Ava identifica Workspace, chatbot y conversación | Desde la integración que Ava guarda para el número o desde la clave pública del canal; nunca desde el cuerpo. `ConversationService::record` (idempotente) | Simulado |
| 3 | **Decidir si responde Ava o se delega en n8n** | **No existe un decisor propio de Ava** (§6.2). Con un workflow asignado, TODO mensaje entrante con la IA al mando corre ese workflow; sin workflow no hay respuesta automática (solo bandeja y personas) | **Decisión D-IA** |
| 4 | Ava invoca el workflow de la empresa | `ChatbotExecutions` → `N8nExecutor` (firmado, con tiempo de espera, reintentos y control de versión de la conversación) | Simulado |
| 5 | La respuesta vuelve, se valida y Ava la entrega | Contrato estricto (§3.1). WhatsApp por la Graph API con un solo intento; web por recogida del widget. **n8n nunca envía por WhatsApp** | Simulado |
| 6 | Quedan registrados conversación, estados, errores y acciones humanas | `conversations`/`messages`, `chatbot_executions`, auditoría, `executions:list`, `integrations:diagnose`, `executions:recover` | Implementado y Simulado |
| 7 | Reservas desde la conversación | El workflow llama a `/api/agent/booking/*` con el token de ejecución (§2.5). Ava aplica agenda, reglas y solapamientos y dice el estado REAL | Simulado y Navegador (con una agenda propia) |

### 2.4 Canal Web

Si el chatbot tiene un workflow del catálogo asignado y el n8n de la plataforma está configurado (`ChatbotExecutions::available`), el widget usa el MISMO camino que WhatsApp: guarda el mensaje, crea la ejecución y responde `{reply: null, pending: true}`; el widget recoge la respuesta (`sender: ai`) con su sondeo (1,2 s y luego cada 3 s mientras espera, máximo 90 s) y el servidor informa `answering` para que el widget deje de esperar cuando la ejecución falló o se descartó. Sin workflow asignado conserva el camino anterior (webhook del Workspace, respuesta en la misma petición; esa respuesta se guarda `delivered` para que el sondeo no la repita). La integración `web` sigue exigiendo una URL de webhook aunque con workflow asignado no se llame.

### 2.5 Token de ejecución

`App\Services\ExecutionAccess`: Ava entrega al workflow, en cada ejecución, un token de vida corta (`ava.token`, 10 min, sin estado: HMAC con la clave de la aplicación) y los datos `ava.api_url` y `ava.tools.booking`. Solo funciona mientras esa ejecución está `running` Y la IA tiene la conversación tal como estaba al encolarse (si una persona la toma, la pide o la resuelve, deja de valer), solo en las herramientas de reservas (`agent.booking.*`) y solo para la conversación de esa ejecución (el contacto sale de la ejecución: nombrar otro da 403). No lee la configuración, no reporta mensajes ni cambia el control. Evita copiar un token permanente del chatbot dentro de n8n; los tokens permanentes siguen funcionando para quien conecte su propio n8n.

### 2.6 Reservas: idempotencia y resultados inciertos

- `POST /booking/appointments` es idempotente por conversación + servicio + instante: repetirlo devuelve `200` con `replayed: true` y la misma cita (sin columna nueva). Tras cancelar, el mismo instante puede reservarse de nuevo.
- Una escritura a una agenda externa cuya respuesta se perdió (timeout, 5xx, error desconocido: `ProviderException::uncertain()`) deja la cita `requested` con `sync_status = uncertain`, responde `409 reason=uncertain` con `appointment_id` y **no se escribe de nuevo hasta que alguien la verifique** (también desde la página pública, por cliente + instante). El personal, tras comprobar en la agenda externa, la cierra con «Cancelar» (queda `cancelled`, `not_found`). En cancelar y reprogramar, una respuesta perdida deja la cita activa marcada `uncertain` (`refresh` pregunta a la agenda).
- Los rechazos ciertos (credenciales, datos inválidos, límite de tasa, conflicto) son `failed` y se pueden repetir.
- Hoy Booker no reserva: esto solo se probó con la agenda simulada.

### 2.7 Cambios de preparación (2026-10-16)

Estados de entrega monótonos; `integrations:diagnose` y `executions:list`; token de ejecución; canal web sobre las ejecuciones de la plataforma; idempotencia de reservas y estado `uncertain`; latido del scheduler; `docker-compose.prod.yml` reenvía todas las variables de integración (`META_*`, `N8N_*`, `WHATSAPP_GRAPH_URL`/`VERSION`, `WORKSPACE_ADMIN_CODE`, `ADMIN_*`; la imagen no trae `.env`, así que lo que no se reenvía no existe en producción) y fija `INTEGRATIONS_ALLOW_PRIVATE_HOSTS=false`; menú General · Operación · Administración (`docs/modulos-y-planes.md`).

## 3. Preparación para despliegue y pruebas reales

### 3.1 Contrato del workflow de n8n (lo que Ava envía y espera)

Ava hace `POST {N8N_EXECUTION_URL}/{webhook_path}` con las cabeceras `X-Ava-Execution` (id de la ejecución, estable en reintentos), `X-Ava-Timestamp` y `X-Ava-Signature: sha256=` + HMAC-SHA256 de `"{timestamp}.{cuerpo en bruto}"` con `N8N_EXECUTION_SECRET`. Ava no rechaza marcas de tiempo (las pone ella): el workflow debe verificar la firma y rechazar marcas viejas antes de hacer nada.

**Cuerpo:** `execution_id`, `event: "message"`, `workflow {key,id}`, `chatbot {id,name,instructions}`, `conversation {id,channel,memory_key}`, `message {id,text}`, `history [{role,text}]` (hasta 10 mensajes de ESA conversación) y el bloque `ava` (`api_url`, `token`, `token_expires_at`, `tools.booking`: si el plan incluye Servicios y Reservas). No llegan credenciales permanentes, número del contacto ni datos del Workspace; `memory_key` es opaca y distinta por Workspace, chatbot y conversación. Con un workflow asignado, el canal web usa este mismo contrato.

**Respuesta** (HTTP 200, ≤ 64 KB, JSON): `{"execution_id": "<el mismo>", "reply": "texto", "handoff": false}`; vale `reply` (≤ 4000 caracteres, se recorta) y/o `handoff: true` (pedir una persona; Ava cambia el control y la IA calla). 408/425/429/5xx y fallos de conexión se reintentan (3 intentos, esperas de 10 s y 60 s); cualquier otro error, o una respuesta que no repita el `execution_id`, es definitivo (`invalid_response`). El workflow no debe llamar a Meta.

**Adaptaciones que debe comprobar el workflow existente de la empresa** (no se conoce ni se sustituyó):
1. **Entrada:** recibir el `POST` anterior y **verificar `X-Ava-Signature`**, rechazando marcas de tiempo viejas.
2. **Salida:** responder el contrato en la misma respuesta HTTP, dentro de `N8N_EXECUTION_TIMEOUT`. **Dejar de enviar mensajes a WhatsApp desde n8n:** el envío es de Ava; si el workflow envía por su cuenta, el contacto recibiría dos mensajes y Ava no sabría de ello.
3. **Memoria:** usar `conversation.memory_key`, no el número del contacto (no se envía).
4. **Reservas:** llamar a `ava.api_url` con `Authorization: Bearer <ava.token>` en `/booking/services`, `/professionals`, `/availability`, `/appointments` (POST reservar, GET listar, `/{id}/cancel`, `/{id}/reschedule`), sin enviar `channel` ni `contact_id`. Decir al contacto el estado que devuelve Ava (`confirmed` solo si la agenda lo confirmó; `requested`/`failed` se comunican tal cual) y respetar `capabilities` (con Booker, hoy no hay horarios ni reservar).
5. **Reintentos y respuestas de reservas:** una ejecución puede llamar al workflow hasta 3 veces con el MISMO `X-Ava-Execution`. Tratar así las respuestas: `201`/`200` con `confirmed: true` → confirmada; `confirmed: false` → «por confirmar»; `409 slot_unavailable` → ofrecer otro horario; **`409 uncertain` → NO repetir ni inventar un resultado** (decir que el negocio verificará la solicitud); `422 unsupported` → la agenda conectada no lo permite; `502 provider` → rechazo cierto, se puede reintentar más tarde; `401` (el token murió porque una persona tomó la conversación) → terminar sin responder.
6. **Transferencia a una persona:** `handoff: true` en la respuesta (el token de ejecución solo llega a reservas).
7. **Instrucciones:** vienen en `chatbot.instructions`; Ava es la única fuente (no duplicarlas en n8n).

### 3.2 Pasos cuando haya credenciales (WhatsApp + n8n de la plataforma)

1. Elegir `META_WEBHOOK_VERIFY_TOKEN` (texto largo aleatorio propio) y tomar el App Secret de Meta. Definir en el entorno de producción, nunca en el repositorio: `META_APP_SECRET`, `META_WEBHOOK_VERIFY_TOKEN`, `N8N_EXECUTION_URL`, `N8N_EXECUTION_SECRET` (y `ADMIN_EMAIL`/`ADMIN_PASSWORD` solo para sembrar el primer administrador). Con Docker, el archivo de variables que pasas a `--env-file`; vacío = no configurado.
2. Construir el workflow según §3.1 y registrarlo en `config/n8n.php` (`workflows`); desplegar.
3. `up -d --build`, `php artisan migrate --force`, `db:seed --class=RolesAndPermissionsSeeder` y `--class=ModuleCatalogSeeder`, y `horizon:terminate` para que los workers carguen el código. `APP_URL` con `https://`, `TRUSTED_PROXIES`, HTTPS delante del servicio `proxy`, worker (Horizon) y **un** scheduler corriendo.
4. En Meta, configurar el webhook `https://<APP_URL>/api/webhooks/whatsapp` con el mismo token, verificarlo (Meta hace el GET) y suscribir el campo `messages`.
5. En Ava, con un Workspace de pruebas cuyo plan incluya Asistentes y Conversaciones (y Servicios y Reservas para probar reservas): crear el chatbot, abrir Integraciones › WhatsApp, guardar `phone_number_id` y token, pulsar «Probar conexión» (debe quedar «Conexión verificada»), activar el canal y asignar el workflow con `php artisan workflows:manage assign …`.
6. `php artisan integrations:diagnose`: sin bloqueos.
7. Enviar un mensaje desde un número de prueba: debe aparecer en Conversaciones, ejecutarse el workflow y llegar la respuesta. Comprobar los estados (`sent` → `delivered` → `read`), responder como agente dentro de la ventana de 24 h, repetir un evento firmado para confirmar que no se duplica y hacer una conversación de reserva completa con la agenda de Ava.
8. Probar los fallos a propósito: token inválido (canal en «Error de conexión» y envío `failed` sin repetirse), n8n apagado (reintentos y `failed` con código) y revisar `integrations:diagnose` y `executions:list --failed`.
9. Antes de datos de clientes reales: D-P1 y las decisiones de privacidad (`docs/privacidad.md`), contrato y subencargados (Meta, n8n) y licencia de n8n.

### 3.3 Operaciones de agenda disponibles

Agenda propia de Ava: servicios, profesionales, disponibilidad, reservar, listar, cancelar y reprogramar. Con un proveedor externo, solo lo que su adaptador declare en `capabilities` (Booker hoy: servicios, profesionales, leer y cancelar).

## 4. Procedimientos operativos y diagnóstico

- **Revisión de preparación:** `php artisan integrations:diagnose [--workspace=ID]`. No llama a servicios externos. Revisa entorno (APP_URL https, debug, hosts privados), base de datos, cola, caché, **latido del scheduler** (cada minuto; en producción su ausencia bloquea), **Horizon** (con Redis) y tamaño de la cola, variables de Meta y n8n, catálogo de workflows, canales que no recibirán mensajes (integración apagada, plan sin Asistentes y Conversaciones, número reclamado por dos integraciones), ejecuciones atascadas o fallidas por código y envíos sin confirmar. Imprime solo booleanos, conteos, ids y códigos fijos: nunca secretos, números, nombres ni texto (prueba automática). Salida 1 si hay un bloqueo.
- **Ejecuciones:** `php artisan executions:list [--failed] [--status=] [--workspace=] [--hours=] [--limit=]` y `--show=ID` (qué significa cada código y qué hacer). Solo ids, estados, códigos y horas.
- **Recuperación:** `php artisan executions:recover` (automático cada minuto) y `--retry=ID` para repetir una ejecución FALLIDA que no guardó respuesta. Nunca reenvía un mensaje: una entrega cuyo resultado se desconoce queda `uncertain`/`unconfirmed` y una persona la verifica en WhatsApp.
- **Cita `uncertain`:** el personal la ve en la Agenda; tras comprobar en la agenda externa, la cierra con «Cancelar».
- **Workflows:** `php artisan workflows:manage assign|unassign|list` (solo catálogo de `config/n8n.php`).
- **Códigos de ejecución más comunes:** `not_configured` (faltan `N8N_EXECUTION_URL`/`SECRET`), `insecure_target` (URL sin https en producción), `unsafe_target` (resuelve a red interna), `unreachable`/`request_failed` (red o tiempo de espera), `n8n_unavailable` (408/425/429/5xx, se reintentó), `n8n_rejected` (4xx: ¿firma o ruta del webhook?), `invalid_response` (no cumple el contrato), `retries_exhausted`, `late_response`/`superseded*` (cambió el control: se descartó), `workflow_changed`.

## 5. Riesgos, limitaciones y recomendaciones

- **Nada probado contra servicios reales:** el comportamiento de `biz_opaque_callback_data` y de los códigos de error de la Graph API se basa en su documentación, no en una prueba.
- **Booker:** sin horarios, reserva ni reprogramación; sus rutas salen de un cliente Ruby de código abierto. Recuperación de `uncertain` y escritura incierta solo probadas con la agenda simulada; falta una acción «confirmar que sí existe» (pegar el id externo) y un aviso.
- **Workflow empresarial sin adaptar:** el mayor riesgo es que siga enviando por WhatsApp por su cuenta (mensajes duplicados). Hasta adaptarlo, no probar con contactos reales.
- **Un workflow que falla de forma definitiva** deja el mensaje sin responder (consta en `chatbot_executions` y `failed_jobs`); sin interfaz gráfica de ejecuciones, solo consola.
- **Un solo App Secret** para todos los números de Meta de la plataforma (Meta por Workspace con su propio secreto es decisión abierta); un mensaje que no es texto no inicia una ejecución; el límite del webhook (600/min por IP) es una estimación.
- **Canal Web:** el widget no vuelve a mostrar los mensajes anteriores tras recargar (la conversación sí continúa en el servidor); no se probó en móvil real ni en navegadores distintos de Chromium; la integración `web` exige una URL de webhook que con workflow asignado no se usa.
- **Idempotencia de reservas por clave natural:** con una agenda externa se compara por conversación + instante; no hay clave de idempotencia en la base (exigiría una columna nueva).
- **Mensaje entrante y plan:** ninguna integración debe activarse en un Workspace cuyo plan no incluya Asistentes y Conversaciones (el diagnóstico lo avisa).
- **Recomendaciones:** probar primero con números y datos de prueba; mantener `INTEGRATIONS_ALLOW_PRIVATE_HOSTS=false` en producción; ejecutar `integrations:diagnose` tras cada despliegue; no asignar `access-private-data` a nadie hasta resolver D-P1.

## 6. Requisitos externos y decisiones pendientes

### 6.1 Requisitos externos (los pone el proveedor o CCG)

- **Meta:** app con el producto WhatsApp; número de prueba o real con su `phone_number_id`; **App Secret**; **token permanente** (usuario del sistema) con permiso de mensajería; plantillas aprobadas si se escribe fuera de la ventana de 24 h (Ava no envía plantillas); decidir qué número usa el webhook de Ava (un número no puede tener a la vez este webhook y el WhatsApp Trigger de n8n).
- **n8n:** instancia accesible por HTTPS desde el servidor de Ava, el workflow adaptado (§3.1) y **confirmación de que su licencia permite este modelo de servicio** antes de uso comercial.
- **Infraestructura:** HTTPS público con certificado válido, Redis, worker (Horizon) y scheduler (una réplica).
- **Booker (si fuera la primera):** Client ID, Client Secret, Subscription Key y Location ID del Sandbox y acceso al portal de desarrolladores.
- **Despliegue de producción:** variables obligatorias de `docker-compose.prod.yml` (ver `INFRASTRUCTURE.md`) más las de integración (§3.2).

### 6.2 Decisiones que no se resuelven por suposición

- **D-IA — quién decide «responder directo» o «delegar en n8n».** Hoy Ava no tiene motor de IA ni clasificador, y no se añadió uno: el workflow es el asistente. Ava decide solo lo verificable: quién tiene la conversación (IA o persona), si el chatbot, el Workspace y el plan lo permiten y si la respuesta sigue siendo válida al llegar. **Opción A (construida):** el workflow de la empresa contiene la lógica de IA y decide cuándo responder, consultar la agenda de Ava o pasar a una persona; Ava es la autoridad sobre control, envío y datos. **Opción B (no construida):** Ava decide primero (reglas o un modelo propio) y llama a n8n solo para acciones; exige proveedor de modelos, costes, privacidad de los datos enviados y evaluación, y es un módulo nuevo. Hasta decidir se asume A.
- **D-P1:** quién autoriza el acceso excepcional de soporte y si exige aprobación del cliente (`docs/privacidad.md` §6). Hoy denegado por defecto.
- **Retención y borrado, contrato con Meta, licencia de n8n, subencargados, consentimiento:** requieren decisión jurídica o comercial (`docs/privacidad.md`).
- **Meta por Workspace con su propio secreto, mensajes que no son texto, interfaz para ver ejecuciones, qué hacer con una ejecución que falla definitivamente, plantillas aprobadas:** sin decidir.

## 7. Validaciones realizadas y próximas comprobaciones

### 7.1 Auditoría de riesgos previa a las pruebas reales (corregidos)

| Riesgo | Resultado | Corrección |
|---|---|---|
| Token de ejecución tras una transferencia | Seguía valiendo con una persona al mando | Muere al cambiar el control (`handling_version`). Probado |
| Token: expiración, falsificación, fin de la ejecución, otro contacto/conversación/Workspace, otras rutas | Correcto | 401 al terminar, expirar o alterarse; 403 al nombrar otro contacto o salir de reservas; 404 a citas ajenas |
| Token en respuestas, logs, auditoría, ejecuciones, `failed_jobs`, mensajes | No aparece (prueba con un workflow que falla y repite lo recibido) | Prueba de no filtración |
| Reintento de una reserva | Una segunda llamada intentaba reservar otra vez | Idempotencia por clave natural (§2.6) |
| Timeout o respuesta incierta de una agenda externa | Un timeout dejaba la cita `failed`; reintentar podía duplicarla | Estado `uncertain`, `409`, verificación antes de repetir (§2.6) |
| Widget con ejecución fallida | Seguía «Escribiendo…» 90 s | `answering` en el sondeo; aviso en ~3 s |
| Compose de producción | No reenviaba `META_*`/`N8N_*`/`WHATSAPP_GRAPH_URL`/`ADMIN_*` | Reenvío completo y prueba que compara la configuración con el compose |
| Scheduler y Horizon | El diagnóstico no comprobaba que corrieran | Latido del scheduler, consulta de Horizon y de la cola |
| Estados de entrega fuera de orden | Un `delivered` tardío deshacía un `read` | Estados monótonos |

Pruebas automáticas (todas simuladas): `IntegrationReadinessTest` (estados, firma, replay, aislamiento, diagnóstico, compose), `PlatformRunTest` (token, canal web, reservas inciertas, listado de ejecuciones), `ChatbotExecutionTest`, `AgentBookingTest`, `CalendarProviderTest`, `PublicBookingTest`, `WebWidgetTest`, `BookerTest` (respuestas simuladas, no un Sandbox).

### 7.2 Canal web en navegador (entorno desechable)

Servidor de Laravel propio, base PostgreSQL desechable, un worker de cola real, una página estática que incrusta el widget y un **n8n de PRUEBAS** local (verifica la firma de Ava y responde según el texto; no es el workflow de la empresa ni un n8n real). Todo se destruyó al terminar. Verificado con Playwright: respuesta inmediata (~3 s), respuesta lenta (6 s), workflow que rechaza (aviso en ~3 s), espera prolongada (tras 90 s aparece «El asistente está tardando…» y el sondeo sigue), **reserva real por HTTP con el token de ejecución** (primera llamada `201`, reintento `200 replayed`, una sola cita), conversación continua tras recargar, chatbot sin workflow (webhook propio, respuesta en la misma petición) y aislamiento (otro widget y otra sesión no reciben mensajes ajenos). Planes, acceso de soporte, Usuarios y Roles y el sidebar se revisaron en navegador en escritorio, tablet y móvil (`pendientes.md`).

### 7.3 Próximas comprobaciones

1. Pasos de §3.2 con credenciales reales: «Probar conexión», mensaje firmado por Meta, respuesta de n8n, envío, estados y fallos a propósito.
2. Adaptar y probar el workflow empresarial contra §3.1 (firma, memoria, no enviar por WhatsApp, respuestas de reservas).
3. Con el Sandbox de Booker: verificar servicios y empleados, e implementar horarios, reservar y reprogramar solo cuando su respuesta esté confirmada; ampliar `BookerTest`.
4. Probar el widget en móvil real y otros navegadores; decidir si el historial debe mostrarse al recargar.
5. Decidir D-IA y D-P1 antes de datos de clientes reales.
