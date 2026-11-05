# API para n8n (n8n → Ava): contratos, flujo de conexión y WooCommerce

Sentido Ava → n8n (ejecución firmada, evento `resume`): `N8N_WORKFLOW_REFERENCIA.md`. Ejemplos ejecutables: `postman/ava-platform.postman_collection.json`, carpeta 01 (Newman: 36 solicitudes, 0 fallos contra un servidor local desechable). Plantilla de workflow: `docs/n8n/ava-referencia.workflow.json` (**sin probar en una instancia real de n8n**). Todo lo de abajo está probado solo con pruebas automáticas locales.

## 1. Reglas comunes

- **Autenticación:** `Authorization: Bearer ava_…` = token del chatbot (IA y comportamiento → Token; solo se guarda su SHA-256; vence a los 90 días, rotación sin corte). El Workspace y el chatbot salen **del token**; ningún campo del cuerpo los cambia. Opcional `X-Ava-Engine: n8n|lava`.
- **Módulos del plan:** todas exigen Asistentes; las de conversación, Conversaciones; reservas y encuestas, Reportes. Si falta: `403`/`404`.
- **Errores:** `401` token inválido/vencido (`reason`), `403` módulo no contratado, `404` recurso inexistente **en ese Workspace** (el de otro da el mismo error), `422` validación, `429` límite (1 800 solicitudes/min y 240 escrituras/min por Workspace, cuerpo ≤ 256 KB: `config/api_protection.php`).
- **Idempotencia:** claves del llamante, únicas por Workspace. Reintentar con la **misma** clave; nunca regenerarla.

## 2. Endpoints

`canal` = `whatsapp` o `web`. Pruebas: `AgentApiTest`, `N8nContractFlowTest`, `N8nConcurrencyTest` (PostgreSQL), `AgentActivityReportTest`, `ReportingIngestionTest`, `HumanHandlingTest`.

| Método y ruta | Obligatorios | Opcionales | Respuesta / idempotencia |
|---|---|---|---|
| `GET /api/agent/config` | — | — | Instrucciones, canales activos |
| `POST /api/agent/messages` | `channel`, `contact_id`, `direction` (`in`/`out`) | `contact_name`, `type`, `body` (≤ 4096), `media_mime`, `external_id`, `status`, `timestamp` | `201` creado / `200` repetido: `{id, created, control:{mode, ai_allowed, version}}`. **Idempotente por `external_id`**; un repetido devuelve `ai_allowed:false` |
| `POST /api/agent/messages/status` | `channel`, `contact_id`, `external_id`, `status` | — | `{updated:true}`; solo avanza (`sent < delivered < read`) |
| `POST /api/agent/conversations/authorize` | `channel`, `contact_id` | `version` | `{control}`; `ai_allowed:false` si una persona la tiene o la versión cambió |
| `POST /api/agent/conversations/handoff` | `channel`, `contact_id` | `contact_name`, `reason` | `{control, handoff:{outcome: requested\|already_pending\|already_human, executed}}`. **Idempotente** |
| `GET /api/agent/conversations/context` | `channel`, `contact_id` (query) | `limit` (1–60), `after_id` | `{control, context:{facts,steps,next_step,operations,conflicts,summary,handoff,resume}, history:[{id,sender,role,type,text,at}], last_message_id}`. `after_id` = solo lo posterior a ese mensaje |
| `POST /api/agent/conversations/context` | `channel`, `contact_id` | `facts{clave:valor\|{value,confirmed}}`, `steps[]`, `next_step`, `operations{clave:id}`, `summary` | `{context}`; lo mal formado se ignora; una operación ya registrada no se reemplaza |
| `POST /api/agent/activity/steps` | `channel`, `contact_id`, `engine` (`n8n`\|`lava`), `step_ref` (8–120 `[A-Za-z0-9:._-]`), `tool` | `tool_label`, `execution_id`, `ava_execution_id`, `trigger_message_external_id`, `method`, `endpoint`, `request_headers/query/body`, `status` (`started\|succeeded\|failed\|rejected\|uncertain`), `http_status`, `response_headers/body`, `error_class`, `error_message`, `duration_ms`, `started_at`, `finished_at` | `201`/`200 {id, step_ref, status, created, finished}`. **Misma `step_ref` = mismo paso** (inicio sin `status`, fin con él); un paso terminado no se reabre; clave de otra conversación → `409` |
| `POST /api/agent/booking-records` | `source` (`booker\|lava\|ava`), `external_id`, `status` (`booked\|confirmed\|completed\|no_show\|cancelled`), `starts_at` | `booked_at`, `cancelled_at`, `updated_at`, `location_id`, `location_ref`, `service_name`, `professional_name`, `customer_id`, `origin` | `{id, created, ignored, status}`. Idempotente por (`source`, `external_id`); un `updated_at` anterior al guardado se ignora; ids de otro Workspace → `422` |
| `POST /api/agent/surveys` | `key`, `name` | `description`, `type`, `status`, `scale_min`+`scale_max`, `questions[{key,label,type,options?}]` | Idempotente por `key` |
| `POST /api/agent/surveys/dispatches` | `survey_key`, `external_ref` | `customer_id`, `channel`+`contact_id`, `booking_source`+`booking_external_id`, `location_id`, `status`, `sent_at` | Idempotente por `external_ref` |
| `POST /api/agent/surveys/responses` | `survey_key`, `external_ref` | `dispatch_ref`, `rating` (dentro de la escala), `comment`, `answers{pregunta:valor}`, `is_complete`, `answered_at` | `201`/`200 {id, external_ref, created, updated, is_complete}`. Idempotente por `external_ref`; hereda vínculos del envío. **Por partes:** la misma `external_ref` fusiona las respuestas nuevas en la respuesta incompleta; completa es final (un duplicado tardío no la cambia). Cada respuesta se valida contra el tipo de su pregunta (`rating` dentro de la escala, `choice` entre las opciones, `boolean`, `number`, `text`) → `422 answers.{pregunta}`; una `external_ref` de otra encuesta → `422`. El envío pasa a `started` mientras responde y a `answered` al completarse |
| `GET /api/agent/surveys/responses/progress` | `survey_key`, `external_ref` | — | `{is_complete, answers, rating, next_question{key,label,type,options}, remaining[]}`: dónde quedó una encuesta interrumpida, para retomarla en vez de empezar de nuevo. `422` si no existe en el Workspace del token |

Las encuestas (y las reservas externas) usan **el mismo contrato para n8n y Lava Studio y para cualquier canal**: `X-Ava-Engine` solo etiqueta quién lo envió; el motor conversa por el canal que tiene y Ava guarda y suma. Exigen el módulo Reportes del plan (además de Asistentes).

Herramientas que Ava sirve y traza sola: `GET /api/agent/booking/{services,professionals,locations,availability,appointments}`, `POST /api/agent/booking/appointments[/{id}/{cancel,confirm,reschedule}]`, `GET|POST /api/agent/orders[/options|/products]`, `POST /api/agent/reminders[/{id}/cancel]`, `GET /api/agent/knowledge/search` (contratos en el workflow de referencia y Postman).

**Actividad técnica:** se eliminan credenciales (por nombre y por patrón), se enmascaran correos y teléfonos, cada cuerpo ≤ 64 KB; contenido 30 días y datos técnicos 180 (`agent:tools:prune`); el contenido solo lo ve el permiso `view-technical-activity-bodies`; un Workspace puede desactivar el guardado del contenido en Configuraciones → Conversaciones.

## 3. Conectar un workflow (pasos)

1. **Ava:** Asistentes → crear chatbot, activar el canal, IA y comportamiento → Token (se muestra una vez).
2. **n8n (instancia de pruebas):** variable `AVA_URL`; credencial *Header Auth* con `Authorization: Bearer <token>` (nunca dentro del workflow). Importar la plantilla.
3. **Secuencia:** `POST /messages` (in) → si `control.ai_allowed` es `false`, terminar → `GET /conversations/context` → nodo de IA → ¿escalar? sí: `POST /conversations/handoff` y comprobar `control.mode` ∈ {`pending`,`human`}; no: `POST /conversations/authorize` con `version` y, si `ai_allowed`, enviar y `POST /messages` (out). Cada llamada externa: `POST /activity/steps` al inicio y al fin con el mismo `step_ref` (p. ej. `{{$execution.id}}:{{nodo}}:{{runIndex}}`).
4. **Escalamiento:** la regla vive en las instrucciones/workflow, no en Laravel. n8n **solo puede pedir** una persona; devolver a la IA lo hace una persona en Ava («Devolver a la IA»). Mientras `pending`/`human`, `authorize` da `ai_allowed:false`.
5. **Reanudar:** Ava emite el evento `resume` (modo en que Ava inicia la ejecución) o el webhook de salida `conversation.handling_changed` (Integraciones → Webhooks de salida, firmado). Un workflow con trigger propio también puede consultar `context` (`control.mode = ai`, `context.resume = true`) y seguir sin repetir preguntas.
6. **Reintentos:** reintentar con espera solo lo idempotente (`messages` con `external_id`, `handoff`, `activity/steps`, `context`, encuestas, reservas). No reintentar a ciegas un nodo que crea recursos externos: guardar antes la operación en `context.operations`.
7. **Límite:** Ava no puede impedir que n8n envíe sin llamar antes a `authorize`; el contrato lo obliga y el historial guarda lo reportado.

**Prueba real pendiente:** instancia de n8n con HTTPS, `AVA_URL`, token de pruebas, workflow registrado (`config/n8n.php` `workflows`, hoy vacío). Escenario: «Hola» → respuesta; «tengo un problema, quiero hablar con una persona» → conversación en «Pendientes» sin respuesta de la IA → el agente responde → «Devolver a la IA» → el cliente escribe → la IA responde sin repetir preguntas; repetir cada llamada con la misma clave sin duplicados.

## 4. WooCommerce

Arquitectura: **WooCommerce es la fuente de verdad, n8n orquesta, Ava registra** (conversación, contexto, trazabilidad, aislamiento, reportes). Estado: conexión registrable y probable; verificador de firma de webhooks; **nada probado contra una tienda**. No existen tipo de integración propio, receptor de webhooks ni espejo de catálogo/pedidos en Ava.

- **Registrar la tienda:** Integraciones → conexión HTTP: URL base `https://<tienda>`, endpoint `/wp-json/wc/v3/products`, método `GET`, parámetro `per_page=1`, Basic Auth con `ck_…` como usuario y `cs_…` como contraseña (solo lectura). «Probar conexión»: 200 con lista = válido; 401 = claves; 404 = enlaces permanentes o API REST deshabilitada. El secreto se guarda cifrado y no se muestra.
- **Lo que consume n8n (confirmar contra la documentación oficial y una tienda):** `GET/POST/PUT /wp-json/wc/v3/{products, products/<id>/variations, products/categories, customers, orders}`; paginación `per_page` ≤ 100 y `X-WP-TotalPages`; estados de pedido `pending, processing, on-hold, completed, cancelled, refunded, failed`.
- **Webhooks de Woo:** los recibe n8n (*WooCommerce Trigger*): firma `X-WC-Webhook-Signature` = base64(HMAC-SHA256(cuerpo crudo, secreto)) (`App\Integrations\WooCommerceWebhook::verify`) y deduplicar por `X-WC-Webhook-Delivery-ID`.
- **Evitar pedidos duplicados:** WooCommerce no ofrece idempotencia al crear pedidos: n8n incluye una referencia única en `meta_data` (p. ej. el `execution_id`), la busca antes de reintentar y guarda el id en `context.operations`.
- **Registro:** cada llamada a Woo se reporta con `POST /api/agent/activity/steps`.
- **Datos para la prueba real:** URL HTTPS de una tienda de pruebas, claves `ck_/cs_` de solo lectura (y de escritura si se prueba crear pedidos), secreto de webhook, URL de la instancia de n8n, 2–3 productos (uno variable) y un cliente de prueba.
