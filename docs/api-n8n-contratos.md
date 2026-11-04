# Contratos de la API para n8n (n8n → Ava)

**Estado:** todos los contratos están implementados y probados **solo con pruebas automatizadas locales** (HTTP de Laravel sobre SQLite). **Ningún endpoint se ha ejecutado desde un n8n real.** El sentido Ava → n8n (ejecución firmada y evento `resume`) está en `N8N_WORKFLOW_REFERENCIA.md`; la colección Postman (`postman/ava-platform.postman_collection.json`, carpeta «01 API del agente (n8n)») trae un ejemplo de cada solicitud.

## 1. Reglas comunes

| Punto | Regla |
|---|---|
| Autenticación | `Authorization: Bearer ava_…` = token del chatbot (se guarda solo su SHA-256; vence a los 90 días; rotación sin corte). El Workspace y el chatbot salen **del token**: ningún campo del cuerpo puede cambiarlos |
| Credencial alterna | El token de ejecución de vida corta que Ava entrega en `ava.token` solo sirve para `booking.*`, `orders.*` y `reminders.*` de esa ejecución |
| Cabeceras | `Accept: application/json`, `Content-Type: application/json`. Opcional `X-Ava-Engine: n8n\|lava` (declarar el motor) |
| Módulos del plan | Todas exigen **Asistentes**; las de conversación, **Conversaciones**; reservas y encuestas del reporte, **Reportes**. Si falta, `403`/`404` |
| Límites | Por Workspace: 1 800 solicitudes/min, 240 escrituras/min, 25 concurrentes, cuerpo ≤ 256 KB (`config/api_protection.php`) |
| Errores comunes | `401` token ausente, inválido, vencido (`reason: token_expired`) o revocado (`token_revoked`); `403` módulo no contratado; `404` conversación o recurso inexistente en el Workspace del token; `422` validación; `429` límite |
| Aislamiento | Toda referencia (cliente, sede, conversación, reserva, envío) se resuelve **dentro del Workspace del token**; una de otro Workspace da el mismo error que una inexistente |
| Idempotencia | Se indica por endpoint; las claves son del llamante y únicas por Workspace |
| Reintentos | Seguros solo donde se dice «idempotente». n8n: reintentar con la misma clave; nunca regenerarla |
| Correlación | `execution_id` (id de la ejecución de n8n) y `step_ref`; `ava_execution_id` enlaza con la ejecución que Ava inició |

## 2. Endpoints

Abreviaturas: **A** Asistentes, **C** Conversaciones, **R** Reportes. Canal válido: los que tienen conversaciones (`config/chatbots.php`, hoy `whatsapp` y `web`).

### 2.1 Conversación y control humano/IA

| Método y ruta | Finalidad | Obligatorios | Opcionales | Respuesta | Idempotencia | Pruebas |
|---|---|---|---|---|---|---|
| `GET /api/agent/config` | Configuración del chatbot (instrucciones, canales activos) | — | — | `200` | Lectura | `AgentApiTest` |
| `POST /api/agent/messages` (A+C) | Reportar un mensaje entrante o saliente | `channel`, `contact_id`, `direction` (`in`/`out`) | `contact_name`, `type`, `body` (≤ 4096), `media_mime`, `external_id` (≤ 128), `status`, `timestamp` | `201` creado / `200` repetido: `{id, created, control:{mode, ai_allowed, version}}` | **Por `external_id`** (un reintento no duplica y `ai_allowed` queda `false`) | `AgentApiTest`, `N8nContractFlowTest` |
| `POST /api/agent/messages/status` | Estado de entrega de un mensaje saliente | `channel`, `contact_id`, `external_id`, `status` | — | `200 {updated:true}`; `404` si no existe | Solo avanza (`sent < delivered < read`) | `AgentApiTest` |
| `POST /api/agent/conversations/authorize` (A+C) | «¿Puedo responder?» justo antes de enviar | `channel`, `contact_id` | `version` | `200 {control}`; `ai_allowed=false` si una persona tiene la conversación o la versión cambió | Lectura | `HumanHandlingTest`, `N8nContractFlowTest` |
| `POST /api/agent/conversations/handoff` (A+C) | Pedir una persona | `channel`, `contact_id` | `contact_name`, `reason` (≤ 255) | `200 {control:{mode:'pending'…}}` | **Idempotente**: si ya está `pending`/`human` no cambia | `ChatbotExecutionTest`, `N8nContractFlowTest` |
| `GET /api/agent/conversations/context` (A+C) | Recuperar control, contexto estructurado e historial | `channel`, `contact_id` (query) | `limit` (1–60) | `200 {control, context:{facts,steps,next_step,operations,conflicts,summary,handoff,resume}, history:[{id,sender,role,type,text,at}], last_message_id}` | Lectura | `N8nContractFlowTest` |
| `POST /api/agent/conversations/context` (A+C) | Guardar lo aprendido | `channel`, `contact_id` | `facts{clave:valor\|{value,confirmed}}`, `steps[]`, `next_step`, `operations{clave:id}`, `summary` | `200 {context}`; partes mal formadas se ignoran | Las **operaciones** no se reemplazan (la primera gana) | `N8nContractFlowTest` |

Ejemplo (recuperar contexto tras devolver a la IA):
```bash
curl -s -H "Authorization: Bearer $AVA_TOKEN" -H "Accept: application/json" \
  "$AVA/api/agent/conversations/context?channel=whatsapp&contact_id=573001112233&limit=20"
```
```json
{"control":{"mode":"ai","ai_allowed":true,"version":7},
 "context":{"facts":{"name":{"value":"Ana","source":"customer","status":"confirmed"}},"steps":["intake_done"],"next_step":"ask_email","operations":{},"conflicts":[],"summary":null,"handoff":{"returned_at":"2026-11-04T16:00:00+00:00"},"resume":true},
 "history":[{"id":1,"sender":"contact","role":"user","type":"text","text":"Hola, soy Ana","at":"2026-11-04T15:00:00+00:00"}],"last_message_id":4}
```
Estados de atención (`control.mode`): `ai` (IA atendiendo, también «devuelta a IA»), `pending` (pendiente de atención humana), `human` (humano asignado/en curso), `resolved` (finalizada; el contacto la reabre al escribir). Un mensaje automático solo sale si `ai_allowed` es `true`; Ava guarda igual lo que n8n reporta como enviado (el historial es fiel) pero no puede impedir que n8n envíe sin preguntar.

### 2.2 Actividad técnica

**`POST /api/agent/activity/steps`** (A+C) — reportar un paso (una llamada a una API).
Obligatorios: `channel`, `contact_id`, `engine` (`n8n`|`lava`), `step_ref` (8–120, `[A-Za-z0-9:._-]`), `tool`.
Opcionales: `tool_label`, `execution_id`, `ava_execution_id`, `trigger_message_external_id`, `method`, `endpoint` (se guardan host y ruta; los parámetros sensibles se enmascaran), `request_headers`, `request_query`, `request_body`, `status` (`started`|`succeeded`|`failed`|`rejected`|`uncertain`), `http_status`, `response_headers`, `response_body`, `error_class`, `error_message`, `duration_ms`, `started_at`, `finished_at`.
```json
{"channel":"whatsapp","contact_id":"573001112233","engine":"n8n","step_ref":"4821:woo_products:0","execution_id":"4821","tool":"woocommerce.products","method":"GET","endpoint":"https://tienda.ejemplo.com/wp-json/wc/v3/products?search=camiseta","status":"succeeded","http_status":200,"duration_ms":412,"response_body":{"count":3}}
```
Respuesta: `201 {"id":12,"step_ref":"…","status":"succeeded","created":true,"finished":true}`; `200` con `created:false` al completar o repetir.
- **Idempotencia:** misma `step_ref` en el mismo Workspace = mismo paso. Un paso terminado no se reabre ni pierde su respuesta. Una clave de otra conversación → `409`. La misma clave en otro Workspace es otro paso.
- **Privacidad:** se eliminan credenciales (por nombre de campo y por patrón de valor: Bearer, JWT, `ck_/cs_`, Basic, llaves AWS), se enmascaran correos y teléfonos y se limita cada cuerpo a 64 KB. Con «Guardar el contenido» desactivado en Configuraciones del Workspace, solo se guardan los datos técnicos. Retención: contenido 30 días, datos técnicos 180.
- Pruebas: `AgentActivityReportTest`, `ActivityPrivacyPolicyTest`. Las herramientas que Ava sirve (`booking.*`, `orders.*`, `reminders.*`, `knowledge.search`) se trazan automáticamente, sin que n8n las reporte.

### 2.3 Reservas de cualquier sistema

**`POST /api/agent/booking-records`** (A+R). Obligatorios: `source` (`booker`|`lava`|`ava`), `external_id`, `status` (`booked|confirmed|completed|no_show|cancelled`), `starts_at`. Opcionales: `booked_at`, `cancelled_at`, `updated_at`, `location_id` (id de Ava del Workspace), `location_ref`, `service_name`, `professional_name`, `customer_id`, `origin`.
```json
{"source":"booker","external_id":"BK-100","status":"confirmed","starts_at":"2026-11-05T15:00:00Z","updated_at":"2026-11-01T10:00:00Z","service_name":"Corte","professional_name":"Ana","location_ref":"3749"}
```
Respuesta: `201 {"id":1,"created":true,"ignored":false,"status":"confirmed"}`; `200` al repetir; `ignored:true` si el reporte es más viejo que el guardado. `422` si `location_id`/`customer_id` no son del Workspace. **Idempotencia:** `(Workspace, source, external_id)` + `updated_at`. Una reserva que Ava creó en Booker y Booker reporta de vuelta se cuenta una sola vez. Pruebas: `ReportingIngestionTest`.

### 2.4 Encuestas (A+R)

| Ruta | Obligatorios | Opcionales | Respuesta | Idempotencia |
|---|---|---|---|---|
| `POST /api/agent/surveys` | `key`, `name` | `description`, `type` (`satisfaction`/`nps`/`custom`), `status`, `scale_min`+`scale_max`, `questions[{key,label,type,options?}]` | `201/200 {id,key,created}` | Por `key` |
| `POST /api/agent/surveys/dispatches` | `survey_key`, `external_ref` | `customer_id`, `channel`+`contact_id`, `booking_source`+`booking_external_id`, `location_id`, `status`, `sent_at` | `201/200 {id,external_ref,created}` | Por `external_ref` |
| `POST /api/agent/surveys/responses` | `survey_key`, `external_ref` | `dispatch_ref`, `rating` (dentro de la escala), `comment`, `answers{pregunta:valor}`, `is_complete`, `answered_at` + vínculos del envío | `201/200 {id,external_ref,created,is_complete}` | Por `external_ref` (un reintento devuelve la respuesta guardada) |

Errores: `422` encuesta no definida, calificación fuera de escala, pregunta desconocida, envío o referencia que no es del Workspace. Una respuesta hereda cliente, conversación, reserva y sede de su envío. `is_complete` se calcula: todas las preguntas contestadas. Pruebas: `ReportingIngestionTest`.

### 2.5 Herramientas de negocio que Ava sirve (resumen)

`GET /api/agent/booking/{services,professionals,locations,availability,appointments}`, `POST /api/agent/booking/appointments[/…/{cancel,confirm,reschedule}]`, `GET|POST /api/agent/orders[/options|/products]`, `POST /api/agent/reminders[/{id}/cancel]`, `GET /api/agent/knowledge/search`. Contratos detallados en `N8N_WORKFLOW_REFERENCIA.md`, §62 y Postman; pruebas `AgentBookingTest`, `AgentOrderTest`, `AgentToolTraceTest`, `KnowledgeBaseTest`.

## 3. Ejemplo de ejecución desde n8n (nodos)

1. **HTTP Request** «Reportar inicio»: `POST {{$env.AVA}}/api/agent/activity/steps`, credencial *Header Auth* `Authorization: Bearer ava_…`, cuerpo JSON con `step_ref = {{$execution.id}}:{{$node.name}}:{{$runIndex}}` y la solicitud, sin `status`.
2. **HTTP Request** (o nodo nativo) hacia la API externa.
3. **HTTP Request** «Reportar fin»: mismo `step_ref`, con `status`, `http_status`, `duration_ms` y `response_body`. En error, ruta de error del nodo 2 con `status: failed` y `error_message`.
4. Configurar **reintentos del nodo** (Retry on fail) solo en los nodos 1 y 3 (son idempotentes). No reintentar a ciegas un nodo que crea recursos externos.

## 4. Pruebas

| Tipo | Qué hay |
|---|---|
| Automatizada local | `AgentActivityReportTest`, `ActivityPrivacyPolicyTest`, `ReportingIngestionTest`, `N8nContractFlowTest`, `AgentApiTest`, `HumanHandlingTest`, `ChatbotExecutionTest` |
| Simulada | `N8nWorkspaceExecutionTest`/`ChatbotExecutionTest` usan `Http::fake` para la ejecución Ava → n8n |
| Contra n8n real | **Ninguna.** Requiere una instancia con HTTPS, el secreto de ejecución y un workflow del catálogo (`config/n8n.php`, hoy vacío) |

**Procedimiento reproducible contra un n8n real:** (1) instancia de n8n de pruebas por HTTPS y su API Key en Integraciones → n8n; (2) Workspace de pruebas con Asistentes y Conversaciones y un chatbot activo con token; (3) registrar el workflow (`php artisan workflows:manage assign`); (4) `curl` de los ejemplos de arriba con ese token; (5) un mensaje real de WhatsApp/web y comprobar mensaje → paso técnico → respuesta → handoff → respuesta humana → devolución → contexto sin repreguntas; (6) reintentar cada solicitud con la misma clave y comprobar que no hay duplicados.
