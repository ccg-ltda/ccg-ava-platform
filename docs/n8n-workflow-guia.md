# Guía: conectar un workflow de n8n a Ava (mensaje → IA → herramientas → handoff → humano → devolución)

**Estado honesto:** el contrato n8n ⇄ Ava está implementado y probado con **pruebas automatizadas locales** (HTTP de Laravel; PostgreSQL desechable para la concurrencia; Postman/newman contra un servidor local desechable). **No se ejecutó contra una instancia real de n8n**; `docs/n8n/ava-referencia.workflow.json` es una **plantilla sin probar** en n8n. Complementa a `N8N_WORKFLOW_REFERENCIA.md` (contrato Ava → n8n, firmado) y a `docs/api-n8n-contratos.md` (contratos de cada endpoint).

## 1. Dos formas de conectar (elige una por canal)

| | A. Ava inicia la ejecución | B. n8n inicia (su propio trigger) |
|---|---|---|
| Quién recibe el mensaje del cliente | Ava (webhook de Meta o widget) | n8n (p. ej. *WhatsApp Trigger*) |
| Cómo llega a n8n | Ava hace `POST` **firmado** al webhook del workflow con mensaje, historial corto, contexto y un token de ejecución | El trigger de n8n |
| Cómo responde | n8n devuelve `{execution_id, reply, handoff}` y Ava envía y guarda | n8n envía y reporta a Ava con `POST /api/agent/messages` |
| Cómo se entera n8n de que volvió la IA | Ava manda un evento `resume` | `GET /api/agent/conversations/context` (consulta) o un **webhook de salida** `conversation.handling_changed` |
| Documento | `N8N_WORKFLOW_REFERENCIA.md` | este documento |

**Un número de WhatsApp no puede tener a la vez el webhook de Ava y el *WhatsApp Trigger* de n8n.**

## 2. Antes de empezar
1. En Ava: Asistentes → crear el chatbot, activar el canal, **IA y comportamiento → Token de acceso** (se muestra una sola vez).
2. En n8n (instancia de PRUEBAS): variable de entorno `AVA_URL` (`https://<dominio de Ava>`) y credencial *Header Auth* «Ava Agent Token (Bearer)» con `Authorization: Bearer <token>`. **El token no va dentro del workflow.**
3. Las **instrucciones de la IA** (incluida la regla de escalamiento) se editan en Ava (IA y comportamiento) y se leen con `GET /api/agent/config`, o se escriben en el nodo de IA. **Ava no codifica reglas comerciales de escalamiento**: la decisión de escalar la toma el workflow/IA y Ava solo ejecuta y valida el cambio de estado.

Ejemplo de instrucción de escalamiento (en el prompt del nodo de IA):
> Cuando el cliente mencione un problema, una queja, una situación que no puedas resolver o pida hablar con una persona, responde solo «ESCALAR» con el motivo; el flujo transferirá la conversación al equipo humano.

## 3. Secuencia del workflow de referencia (`docs/n8n/ava-referencia.workflow.json`)

| # | Nodo | Llamada a Ava | Notas |
|---|---|---|---|
| 0 | Trigger + normalizar | — | Obtiene `channel`, `contact_id`, texto e id del mensaje |
| 1 | Reportar mensaje | `POST /api/agent/messages` (`direction: in`, `external_id` = id del mensaje) | **Idempotente**: un reintento no duplica y devuelve `ai_allowed:false` |
| 2 | ¿La IA puede responder? | usa `control.ai_allowed` | Si es `false` (humano al mando o duplicado) el flujo termina sin responder |
| 3 | Contexto e historial | `GET /api/agent/conversations/context?channel=…&contact_id=…&limit=20` | Hechos ya conocidos y últimos mensajes; con `after_id` solo lo nuevo |
| 4 | IA | — | Decide `reply`, `escalate`, `reason`, datos aprendidos |
| 5 | ¿Escalar? | — | Rama configurable |
| 6a/6b | Handoff y confirmación | `POST /api/agent/conversations/handoff` | `handoff.outcome` = `requested`/`already_pending`/`already_human`; el nodo 6b falla si Ava no confirma |
| 7/8 | Autorizar antes de enviar | `POST /api/agent/conversations/authorize` con `version` | Si cambió el control, **no se envía** |
| 9 | Enviar al cliente | (canal propio) | |
| 10 | Reportar lo enviado | `POST /api/agent/messages` (`direction: out`) | Ava lo guarda aunque otra persona tenga el control: por eso el paso 7 es obligatorio |
| 11 | Registrar el paso técnico | `POST /api/agent/activity/steps` (`step_ref` estable) | Una vez al inicio de cada llamada externa y otra al terminar, con el mismo `step_ref` |
| — | Guardar lo aprendido | `POST /api/agent/conversations/context` | `facts`, `steps`, `next_step`, `operations` |

Cada llamada a una API externa (WooCommerce, Booker, etc.) se envuelve con dos llamadas a `activity/steps` (inicio y fin, mismo `step_ref`, p. ej. `{{$execution.id}}:{{nodo}}:{{runIndex}}`).

## 4. Qué hace Ava en el handoff (y qué no)
- **Valida** el token (identifica Workspace y chatbot), el canal activo, el módulo del plan, la forma del cuerpo.
- **Cambia** el estado bajo bloqueo de fila: `ai` → `pending` (pendiente de atención humana); es idempotente.
- **Registra** el evento (auditoría «Asistente (automático)»), notifica al equipo y la conversación aparece en la bandeja «Pendientes» con todo el historial.
- **Asigna** según la lógica existente: queda disponible para que un agente la tome (`pending` → `human` al tomarla; el administrador puede asignarla).
- **Impide** respuestas simultáneas: con `pending`/`human`, `authorize` responde `ai_allowed:false`; una respuesta preparada antes del cambio queda obsoleta por la versión de control.
- **No deja** que n8n cambie estados arbitrariamente: n8n solo puede **pedir** una persona; **devolver a la IA lo hace una persona** (o un gestor) desde Ava. No existe endpoint de n8n para «quitarle» la conversación a un humano.

## 5. Devolver a la IA y reanudar
1. El agente pulsa «Devolver a la IA» (opcionalmente «que la IA continúe ahora»).
2. Ava cambia `human`/`pending` → `ai`, deja la marca `handoff.returned_at` en el contexto (`context.resume = true`) y emite `conversation.handling_changed` (webhook de salida firmado, si hay uno suscrito).
3. **Modo A:** Ava envía a n8n un evento `resume` con historial ampliado y contexto; la IA responde si el cliente esperaba, o continúa solo si el agente lo pidió.
4. **Modo B:** el workflow detecta el retorno por (a) el webhook de salida `conversation.handling_changed` (Integraciones → Webhooks de salida; verificar `X-Ava-Signature`) o (b) la siguiente consulta de contexto (`control.mode = ai`, `context.resume = true`). Entonces lee el contexto e historial y continúa **sin repetir preguntas** (los `facts` ya confirmados no se vuelven a pedir).

## 6. Errores y reintentos
- 4xx de validación: no reintentar; corregir. 401: token vencido o revocado (rotar). 403: módulo no contratado. 404: conversación inexistente en este Workspace.
- 429/5xx/timeouts: reintentar con espera **solo** las llamadas idempotentes (`messages` con `external_id`, `handoff`, `activity/steps`, `context`, encuestas y reservas con su clave). Configurar «Retry On Fail» en esos nodos.
- No reintentar a ciegas un nodo que cree recursos externos (pedido en WooCommerce): guardar antes la operación en `context.operations` y consultar primero.

## 7. Prueba real (lo que falta)
1. Instancia de n8n de pruebas con HTTPS, `AVA_URL` y la credencial.
2. Importar `docs/n8n/ava-referencia.workflow.json`, reemplazar el nodo 4 por tu nodo de IA y el trigger por el real.
3. Enviar «Hola» → comprobar mensaje y respuesta en la conversación de Ava y el paso técnico en «Actividad técnica».
4. Enviar «Tengo un problema, quiero hablar con una persona» → conversación en «Pendientes», sin respuesta de la IA.
5. Tomar la conversación, responder como agente, escribir como cliente (la IA no responde).
6. «Devolver a la IA» → el cliente escribe → la IA responde usando lo ya conocido.
7. Repetir cada paso con el mismo `external_id`/`step_ref`: no debe haber duplicados.
**Hasta ejecutar esto, el flujo n8n real no está validado.**

## 8. Pruebas automatizadas que respaldan este contrato
`N8nContractFlowTest` (ciclo completo vía HTTP), `ConversationContinuityTest` (Ava → n8n con `resume`, HTTP simulado), `HumanHandlingTest`, `AgentActivityReportTest`, `ReportingIngestionTest`, `N8nConcurrencyTest` (PostgreSQL desechable, procesos separados), y la colección Postman (`docs/postman-guia.md`).
