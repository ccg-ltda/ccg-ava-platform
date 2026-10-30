# Workflow de referencia para n8n: contrato Ava ⇄ n8n

Documento de referencia para quien construya o revise un workflow del catálogo de la plataforma (`config/n8n.php`, `workflows`). **No contiene credenciales ni activa nada.** Describe lo que Ava ya envía y acepta hoy; si el código cambia, el código manda y las pruebas lo fijan (`ConversationContinuityTest::test_the_requests_to_n8n_follow_the_documented_contract_for_message_and_resume`, `ChatbotExecutionTest`).

## 1. Quién llama a quién

Ava inicia cada ejecución con un `POST` firmado a `{URL de la instancia del Workspace}/webhook/{webhook_path}` (ver §8 para saber de dónde salen la URL y el secreto) y recibe la respuesta **en la misma petición HTTP** (espera `N8N_EXECUTION_TIMEOUT`, 25 s). n8n no recibe credenciales del Workspace. Sin conexión válida no se ejecuta nada: el mensaje se guarda y queda en la bandeja.

**Estado de este contrato:** implementado en Ava y probado solo con HTTP simulado (`ConversationContinuityTest`, `ChatbotExecutionTest`, `N8nWorkspaceExecutionTest`). No existe ningún workflow real que lo cumpla ni se ha ejecutado contra un n8n real; el catálogo `workflows` de `config/n8n.php` está vacío. Los requisitos para la primera prueba real están en `pendientes.md` (sección 3, n8n).

Cabeceras: `X-Ava-Execution` (= `execution_id`), `X-Ava-Timestamp` (segundos), `X-Ava-Signature` = `sha256=` + HMAC-SHA256 de `"{timestamp}.{cuerpo}"` con el secreto de ejecución del Workspace (ver §8). El workflow **debe** verificar la firma y rechazar timestamps antiguos.

## 2. Petición (Ava → n8n)

```json
{
  "execution_id": "uuid",
  "event": "message | resume",
  "workflow": {"key": "soporte", "id": "AbC123"},
  "chatbot": {"id": 1, "name": "…", "instructions": "…", "instructions_version": 3, "instructions_mode": "…"},
  "conversation": {"id": 10, "channel": "whatsapp", "memory_key": "40 hex, distinto por Workspace+chatbot+conversación"},
  "message": {"id": 55, "text": "…", "sender": "contact | ai | agent"},
  "history": [{"role": "user | assistant", "sender": "contact | ai | agent", "text": "…"}],
  "context": {
    "facts": {"name": {"value": "Ana", "source": "customer", "status": "confirmed"}},
    "unknown_facts": [], "steps": ["intake_done"], "next_step": "ask_email",
    "operations": {"booking": "RES-1"}, "conflicts": [], "summary": null, "handoff": null, "resume": false
  },
  "ava": {"api_url": "…/api/agent", "token": "token de esta ejecución", "token_expires_at": "ISO-8601", "guidelines": "…", "tools": {"booking": true, "orders": false}}
}
```

- **`event`**: `message` = el contacto escribió (`message.sender = contact`). `resume` = una persona devolvió la conversación a la IA con «Que la IA continúe ahora»: **el contacto no escribió nada nuevo**; `message` es el último mensaje de la persona o de la IA (`sender = agent | ai`) y `history` es más ancha (40 mensajes frente a 10).
- **`history[].sender`** distingue `contact`, `ai` y `agent`. `role` (`user`/`assistant`) se conserva para workflows antiguos.
- **`context`**: estado persistido de la conversación. `facts` trae valor, **origen** (`source`: `customer`, `agent` o `ai`) y **estado** (`confirmed` = lo dijo el cliente; `inferred` = propuesto por la IA, nunca se muestra como confirmado). `operations` = acciones ya completadas (`clave → id`). `resume` indica que la conversación vuelve de una persona.
- **`ava.token`**: credencial limitada a esta ejecución (herramientas de reserva/pedido); muere al terminar. No hay token de larga duración.

## 3. Respuesta (n8n → Ava)

```json
{
  "execution_id": "eco obligatorio",
  "reply": "texto para el cliente (obligatorio salvo handoff, máx. 4000)",
  "handoff": false,
  "context": {
    "facts": {"email": {"value": "ana@example.com", "confirmed": true}},
    "steps": ["intake_done"], "next_step": "ask_reason",
    "operations": {"booking": "RES-1"}, "summary": "…"
  }
}
```

- `execution_id` distinto o ausente, o ni `reply` ni `handoff: true` → respuesta inválida → la conversación pasa a una persona.
- `facts`: valor plano o `{value, confirmed}`; solo es «confirmado» con `confirmed: true` (lo dijo el cliente). Una inferencia nunca pisa un dato confirmado: el conflicto queda registrado.
- `operations`: la primera clave gana; no se reemplaza ni se repite.
- `handoff: true` pide una persona (transición válida, no se aplica a todo mensaje).
- Un `context` mal formado se ignora **sin perder `reply`**.

## 4. Reglas del workflow

1. Verificar firma y frescura del timestamp.
2. **Idempotencia:** si ya vio ese `execution_id`, devolver la misma respuesta sin repetir efectos.
3. **No repetir preguntas:** no pedir lo que está en `context.facts` con estado `confirmed`.
4. **No repetir acciones:** si la clave está en `context.operations`, no volver a ejecutarla.
5. **`resume`:** continuar el proceso desde `context.next_step` e `history`; no saludar de nuevo ni responder a un cliente que no escribió.
6. Devolver siempre `context` actualizado y el eco de `execution_id`.
7. Usar `conversation.memory_key` como única clave de memoria conversacional.
8. Responder antes del timeout; el trabajo largo se hace asíncrono y se contesta con lo que haya.

## 5. Mensajes pendientes, tardíos, duplicados y errores (lado Ava)

| Situación | Comportamiento (probado) |
|---|---|
| Varios mensajes del cliente mientras corre una ejecución | Se responden sin duplicar; una ejecución por mensaje |
| Una persona tiene la conversación | La IA calla; no hay `resume` |
| «Continuar ahora» pedido dos veces | Una sola ejecución `resume` |
| Devolver sin «continuar ahora» | No se llama a n8n; la IA espera al próximo mensaje |
| Respuesta tardía (llega tras un cambio de control o de contexto más nuevo) | No escribe el contexto ni responde |
| 401/403 | Fallo permanente (revisar secreto) |
| 408/425/429/5xx o timeout | 3 intentos con espera (10 s, 60 s), luego una persona + notificación |
| Respuesta inválida | Una persona + notificación |
| Mensaje con más de 10 minutos | No se contesta automáticamente |
| Workflow deshabilitado o retirado tras asignarlo | La ejecución se descarta, el mensaje queda en la bandeja; la asignación no se borra |

## 6. Workflow de prueba (diseño; crearlo en una instancia de PRUEBAS)

Un workflow mínimo de intake con datos sintéticos, sin modelo de lenguaje, para validar el contrato:

1. **Webhook** (POST, `Respond: Using 'Respond to Webhook' node`).
2. **Code**: verificar HMAC y `|now − timestamp| ≤ 300 s`; si falla, responder 401.
3. **Code** `decide`: leer `context.facts`. Si el `next_step` es `ask_<k>` y `<k>` falta, proponer `facts[k] = {value: message.text, confirmed: true}` (solo si `event = message`). Pedir el primer dato que falte entre `name`, `phone`, `email`. Si faltan todos los datos y `event = resume`, **no** guardar `message.text` como dato (es del agente).
4. Si están los tres y `operations.booking` no existe: `operations.booking = "SIM-<execution_id>"` (simulado) y pedir el motivo.
5. **Respond to Webhook**: `{execution_id, reply, context}`.

Criterios de aceptación (datos sintéticos, canal web o doble): nombre → teléfono → agente pide correo → devuelve (con y sin «continuar ahora») → ninguna pregunta repetida, `booking` una sola vez, una respuesta por mensaje, historial del agente visible en `history`, contexto actualizado en el panel «Datos de la conversación». La misma lógica ya está probada con un doble en `ConversationContinuityTest`; lo pendiente es validarla contra un n8n real.

## 7. El soporte de Zonno usa este mismo contrato

Las solicitudes de soporte de los clientes son conversaciones `web` normales de un chatbot de `DESARROLLO_DEV` (§64 de `RECOMENDACIONES.md`). No hay configuración de IA propia: el chatbot debe estar activo en Asistentes y tener un workflow del catálogo asignado (`php artisan workflows:manage assign --chatbot=<id> --key=<clave>`). Recibe `event: message` y `event: resume`, `context` e `history[].sender` exactamente como cualquier otro canal.

## 8. Dónde corre cada Workspace (desde §65)

Cada Workspace ejecuta sus conversaciones en **su propio n8n**, configurado en Integraciones → n8n: URL de la instancia, API Key (solo para verificar la API) y **secreto de ejecución** (solo escritura, mínimo 16 caracteres). Ava llama a `{URL}/webhook/{webhook_path}` y firma con ese secreto: el workflow debe verificar `X-Ava-Signature` con **el mismo** secreto y existir con ese `webhook_path` en esa instancia. Un Workspace nunca usa el secreto de otro. Las variables `N8N_EXECUTION_URL/SECRET` son solo una transición para `DESARROLLO_DEV` (`config('n8n.legacy_environment')`) y nunca sirven a un Workspace con conexión propia. Cada entrada del catálogo (`config/n8n.php`) declara a qué Workspaces se publica (`workspaces`); sin ese dato es exclusiva del administrativo. El contrato de los apartados 2 a 5 no cambió.

## 9. Token de acceso del chatbot a `/api/agent/*`: vigencia y renovación (§75 de `RECOMENDACIONES.md`)

El token `ava_…` con el que n8n llama a Ava (`GET /api/agent/config`, `POST /api/agent/messages`, `authorize`, `handoff`, herramientas) **vence a los 90 días**. Los tokens anteriores a esta regla se adoptaron con un margen mínimo de 30 días. Un token vencido o revocado recibe `401` con `{"message": …, "reason": "token_expired" | "token_revoked"}`: el workflow debe tratar ese `reason` como un fallo visible (no reintentar en bucle) y avisar a la persona responsable. Ava avisa en la campanita del Workspace a los 30, 14, 7, 3 y 1 día antes.

**Renovar sin interrumpir** (Asistentes → IA y comportamiento → «Rotar token»): Ava emite un token de reemplazo y **el actual sigue funcionando**. Pega el nuevo en la credencial «Header Auth» de n8n (`Authorization: Bearer <token>`); en cuanto n8n lo use con éxito, Ava retira el anterior sola. **La actualización de la credencial dentro de n8n es manual**: Ava no tiene (ni se ha verificado que la API de n8n de cada cliente ofrezca) un mecanismo para cambiarla. «Generar nuevo token (reemplaza ya)» corta el anterior al instante y sirve para un token comprometido.

## 10. Qué no cubre este documento

La elección de workflow por clientes (autoservicio) y su propuesta de entidades están en `RECOMENDACIONES.md` §63.
