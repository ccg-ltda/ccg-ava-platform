# Integración WooCommerce ⇄ n8n ⇄ Ava

**Estado (2026-11-04): preparada y probada solo en local con respuestas simuladas. NO se probó contra ninguna tienda WooCommerce real ni contra n8n.** Las rutas de WooCommerce de este documento vienen de la documentación pública conocida y de fuentes secundarias consultadas en la sesión; **hay que confirmarlas con la documentación oficial y con una tienda real (sección 7)**.

## 1. Qué existe hoy en Ava y qué no

| Capacidad | Estado | Evidencia |
|---|---|---|
| Registrar una tienda y probar la conexión (URL + clave y secreto de cliente, Basic Auth) | **Hecho** con la conexión HTTP genérica (cifrado de secretos, `SafeHttpTarget`, historial de llamadas con redacción) | `WooCommerceIntegrationTest` |
| Verificar la firma de un webhook de WooCommerce | **Hecho** (`App\Integrations\WooCommerceWebhook`) — aún no hay receptor propio | `WooCommerceIntegrationTest` |
| Que n8n consuma WooCommerce y lo reporte a Ava (pasos técnicos, conversación, contexto) | **Hecho** con los endpoints genéricos de `docs/api-n8n-contratos.md` | `AgentActivityReportTest`, `N8nContractFlowTest` |
| Tipo de integración propio «WooCommerce» con formulario guiado | **No existe** (se usa la conexión HTTP genérica) | — |
| Receptor de webhooks de WooCommerce en Ava | **No existe**: n8n los recibe (nodo *WooCommerce Trigger*) | — |
| Espejo de productos, inventario, clientes y pedidos de Woo dentro de Ava (módulos Catálogo/Pedidos/Clientes) | **No existe.** Ava tiene su propio Catálogo y Pedidos; sincronizar los de Woo exige decidir cuál es la fuente de verdad | — |
| Reportes de pedidos de Woo en Ava | **No existe** | — |

## 2. Decisión de arquitectura propuesta

**WooCommerce es la fuente de verdad de la tienda; n8n ejecuta las operaciones contra Woo; Ava conserva conversación, contexto, trazabilidad y reportes.**

| Operación | Quién la ejecuta | Por qué |
|---|---|---|
| Consultar productos, variaciones, categorías, precios y existencias | **n8n** (nodo WooCommerce o HTTP) | Evita duplicar el catálogo en Ava y mantiene una sola fuente de verdad |
| Consultar o crear un pedido para el cliente de la conversación | **n8n** | Idem; el asistente decide, n8n llama a Woo |
| Registrar cada llamada a Woo en la conversación | **n8n → Ava** `POST /api/agent/activity/steps` | La actividad técnica muestra solicitud, respuesta, estado y duración (con credenciales eliminadas) |
| Guardar lo que se aprendió (nombre, correo, nº de pedido) | **n8n → Ava** `POST /api/agent/conversations/context` | Contexto compartido; evita repreguntar |
| Pasar a un humano y volver a la IA | Ava (`handoff`, `authorize`, reanudar) | Control de concurrencia IA/humano |
| Recibir eventos de Woo (pedido creado, actualizado, cancelado) | **n8n** (*WooCommerce Trigger*, o webhook propio de Woo hacia n8n) | n8n ya verifica la firma del webhook; Ava no necesita exponer otra URL |
| Notificar al cliente por WhatsApp un cambio de estado | n8n → Ava `POST /api/agent/messages` (mensaje saliente) | Queda en el historial |

**Si en el futuro se quiere reportería de pedidos de Woo en Ava:** reutilizar el patrón de `booking_records` (tabla `order_records` con `source=woocommerce`, `external_id`, estado, total, moneda, sede, cliente; idempotente por `(workspace, source, external_id)`; un endpoint `POST /api/agent/order-records` y `ConsolidatedOrders`). No se implementó porque exige decidir antes la relación con el módulo Pedidos y su inventario.

## 3. WooCommerce: lo que se consume (a verificar con la documentación oficial)

- **Autenticación:** con HTTPS, HTTP Basic con `consumer_key` como usuario y `consumer_secret` como contraseña (claves creadas en WooCommerce → Ajustes → Avanzado → API REST, con permiso de **solo lectura** para consulta o **lectura/escritura** si el flujo crea pedidos). No usar claves en la URL.
- **Base:** `https://<tienda>/wp-json/wc/v3/`. Recursos: `products`, `products/<id>/variations`, `products/categories`, `customers`, `orders`, `orders/<id>/notes`, `webhooks`, `system_status`.
- **Paginación:** parámetros `per_page` (máx. 100) y `page`; cabeceras `X-WP-Total` y `X-WP-TotalPages`.
- **Inventario:** `stock_quantity`, `manage_stock`, `stock_status` por producto o variación; actualizar con `PUT products/<id>`.
- **Pedidos:** estados `pending`, `processing`, `on-hold`, `completed`, `cancelled`, `refunded`, `failed`; crear con `POST orders` y cambiar estado con `PUT orders/<id>`.
- **Webhooks de WooCommerce:** temas como `order.created`, `order.updated`, `product.updated`; cabeceras `X-WC-Webhook-Topic`, `X-WC-Webhook-Delivery-ID` y `X-WC-Webhook-Signature` = base64(HMAC-SHA256(cuerpo crudo, secreto)). Woo reintenta si el receptor no responde 2xx, por lo que **hay que deduplicar por `X-WC-Webhook-Delivery-ID`**.
- **Límites:** WooCommerce no impone un límite propio estándar; lo imponen el hosting, el plugin de seguridad o el CDN de la tienda. Tratar 429/5xx con reintento con espera.

## 4. Registrar una tienda en Ava (conexión HTTP genérica)

Integraciones → Nueva conexión → tipo «HTTP / REST»:

| Campo | Valor |
|---|---|
| URL base | `https://tienda.ejemplo.com` |
| Endpoint | `/wp-json/wc/v3/products` |
| Método | `GET` |
| Parámetros | `per_page=1` |
| Autenticación | Basic Auth: usuario = `ck_…`, contraseña = `cs_…` |

«Probar conexión» hace una solicitud real; 200 con una lista JSON = credenciales válidas y API habilitada; 401 = claves incorrectas o sin permiso; 404 = enlaces permanentes de WordPress mal configurados o API REST deshabilitada. El secreto se guarda cifrado, nunca se vuelve a mostrar y las llamadas quedan en el historial con las credenciales y los cuerpos redactados. **Aislamiento entre tiendas:** cada conexión pertenece a un Workspace; ningún Workspace ve las de otro.

## 5. Flujo completo propuesto (WooCommerce → n8n → Ava)

1. **Mensaje entrante** (WhatsApp) → Ava lo guarda y arranca la ejecución en n8n (`POST` firmado con `execution_id`).
2. n8n consulta el contexto si necesita más (`GET /api/agent/conversations/context`).
3. El asistente decide consultar Woo. Nodo A (HTTP antes): `POST /api/agent/activity/steps` con `step_ref=<execution>:woo_products:0` y la solicitud, sin `status`.
4. n8n llama a `GET https://tienda/wp-json/wc/v3/products?search=…` con Basic Auth.
5. Nodo B (HTTP después): mismo `step_ref`, con `status`, `http_status`, `duration_ms` y `response_body`. **Reintento de n8n → mismo `step_ref` → mismo paso, sin duplicar.**
6. Para **crear un pedido**: antes de `POST /orders` guardar en el contexto una operación idempotente (`operations: {"woo_order": "<id>"}` al recibir el id) y, si la ejecución se reintenta, comprobar primero `context.operations.woo_order` o buscar el pedido en Woo por el número de teléfono/`meta_data` con una clave de referencia propia; **WooCommerce no ofrece idempotencia en la creación de pedidos**, por lo que n8n debe incluir una referencia única en `meta_data` (por ejemplo la `execution_id`) y buscarla antes de reintentar.
7. Respuesta al usuario por `POST /api/agent/messages` (`direction: out`).
8. Si el asistente no puede, `POST /api/agent/conversations/handoff`; un agente responde desde Ava y devuelve a la IA; n8n recupera el historial y el contexto y continúa sin repreguntar.

**Webhook de Woo → n8n → Ava:** *WooCommerce Trigger* recibe `order.updated`, verifica la firma, deduplica por `X-WC-Webhook-Delivery-ID` (por ejemplo con un nodo de «Remove Duplicates» o una tabla/clave en n8n) y, si hay una conversación asociada al cliente, envía el aviso por `POST /api/agent/messages` y reporta el paso con `step_ref=<delivery-id>`.

Verificación de firma dentro de n8n (nodo *Code*, equivalente a `WooCommerceWebhook::verify`):
```js
const crypto = require('crypto');
const sig = crypto.createHmac('sha256', $env.WOO_WEBHOOK_SECRET).update($input.first().binary_raw ?? rawBody).digest('base64');
if (sig !== $headers['x-wc-webhook-signature']) throw new Error('Firma inválida');
```
(Se debe usar el cuerpo **crudo**; el nombre exacto de la propiedad depende de la versión de n8n: comprobar.)

## 6. Seguridad

- Claves de solo lectura salvo que el flujo cree pedidos; una clave por tienda; rotar si se sospecha fuga.
- Secretos solo en las credenciales de n8n y en la conexión cifrada de Ava; nunca en los cuerpos de los pasos (se redactan por nombre y por patrón `ck_…`/`cs_…`).
- HTTPS obligatorio hacia la tienda; `SafeHttpTarget` bloquea destinos internos.
- Datos personales del cliente de la tienda: la actividad técnica enmascara correos y teléfonos y conserva el contenido 30 días.

## 7. Prueba real (reproducible; requiere una tienda)

1. Tienda WooCommerce de pruebas con HTTPS y enlaces permanentes ≠ «Simple».
2. Crear claves REST (lectura) y un webhook `order.updated` hacia la instancia de n8n con un secreto propio.
3. En Ava: crear la conexión de la sección 4 y pulsar «Probar»: esperar «correcta».
4. En n8n: flujo con los nodos A/B de la sección 5; enviar un mensaje de prueba; comprobar en **Actividad técnica** el paso con solicitud y respuesta.
5. Repetir la ejecución con el mismo `step_ref`: debe seguir habiendo **un** paso.
6. Cambiar el estado de un pedido en Woo: n8n recibe el webhook una vez (aunque Woo reintente) y avisa por la conversación.
7. Anotar: versión de WooCommerce y de n8n, permisos de las claves y resultados. **Hasta entonces esta integración no se considera validada.**

## 8. Mapa de cobertura (actualizado 2026-11-04, segunda ronda)

Decisión: **no se construyó** un tipo de integración propio, un receptor de webhooks ni un espejo de catálogo/pedidos de WooCommerce en Ava, porque la arquitectura acordada deja a WooCommerce como fuente de verdad y a n8n como orquestador. Ava aporta lo que le corresponde (conexión registrada y cifrada, trazabilidad, contexto, conversación, aislamiento, reportes). Si se decide espejar pedidos para reportería, el patrón es `booking_records` (sección 2).

| Capacidad pedida | Dónde vive | Estado |
|---|---|---|
| Configuración de tienda por Workspace, URL, credenciales API | Ava: conexión HTTP genérica (Integraciones), una por Workspace | **Hecho** (probado con HTTP simulado) |
| Almacenamiento seguro de secretos | Ava: cifrado en reposo, solo escritura, nunca en logs; n8n: credenciales propias | **Hecho** |
| Validación de conexión | Ava: «Probar conexión» real (`GET /wp-json/wc/v3/products?per_page=1`) | **Hecho** (simulado; falta tienda real) |
| Productos, variaciones, categorías, precios, inventario | **n8n → WooCommerce** (nodos o HTTP) | Diseñado; **sin probar** |
| Clientes, pedidos, estados, creación/actualización | **n8n → WooCommerce**; el pedido nuevo debe llevar una referencia única en `meta_data` (WooCommerce no tiene idempotencia de creación) | Diseñado; **sin probar** |
| Webhooks de WooCommerce | **n8n** (*WooCommerce Trigger*): firma y `X-WC-Webhook-Delivery-ID` | Diseñado; Ava solo trae el verificador `WooCommerceWebhook` y su prueba |
| Sincronización | No hay copia en Ava: WooCommerce es la fuente | Decisión de arquitectura |
| Paginación y límites | n8n (`per_page` ≤ 100, `X-WP-TotalPages`); el nodo debe respetar 429/5xx con espera | Diseñado |
| Reintentos e idempotencia | n8n reintenta solo lecturas y `activity/steps`; los pedidos se protegen con `context.operations` y la referencia en `meta_data` | Diseñado; reglas probadas en Ava (`N8nContractFlowTest`, `N8nConcurrencyTest`) |
| Logs y errores | Ava: `POST /api/agent/activity/steps` por cada llamada (con credenciales eliminadas y datos de contacto enmascarados) + historial de llamadas de la conexión | **Hecho** |
| Aislamiento entre tiendas | Cada conexión y cada token pertenecen a un Workspace; un Workspace no ve otro | **Hecho** (pruebas multi-tenant) |

**Datos exactos que necesito para la prueba real:** URL HTTPS de una tienda de PRUEBAS; versión de WooCommerce y de WordPress; un usuario administrador temporal para crear claves REST; la clave `ck_…` y el secreto `cs_…` de solo lectura (y otra de lectura/escritura si se probará crear pedidos); un secreto de webhook; la URL pública de la instancia de n8n de pruebas; 2 o 3 productos (uno variable con existencias) y un cliente de prueba.
