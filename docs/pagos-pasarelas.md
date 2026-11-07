# Pasarelas de pago por Workspace

Estado: **núcleo auditado y probado con un doble de proveedor; CardNET preparado (configuración y estructura) pero sin protocolo; Wompi por Workspace implementado y probado con la API de Wompi simulada, sin ninguna prueba en Sandbox real.** Azul todavía no empieza. Este documento se amplía en cada entrega.

> No confundir con el Wompi **global** de Ava (`config/wompi.php`, `App\Payments\Wompi*`, `payment_provider_settings`, `POST /api/webhooks/wompi`), que cobra los registros y suscripciones de Ava. Es independiente y no se toca.

## 1. Evidencia: qué está probado y cómo

| Nivel | Qué significa | Estado |
|---|---|---|
| Implementado y probado automáticamente | PHPUnit en SQLite en memoria con un doble de proveedor (`tests/Support/FakeGatewayAdapter.php`). Prueba las reglas de Ava, **no** ningún proveedor | Núcleo: `tests/Feature/PaymentCoreTest.php` |
| Migración probada en PostgreSQL | Base desechable, aplicar, revertir y volver a aplicar | Hecho (2026-11-06) |
| Probado en Sandbox real | Pago real contra el laboratorio de un proveedor | **Nada todavía** (ni Wompi ni CardNET) |
| Pendiente de credenciales | Faltan datos del comercio | Los tres proveedores |
| Pendiente de documentación oficial | Los protocolos no están verificados contra la fuente | CardNET y Azul (ver §9). **El protocolo de CardNET no está implementado a propósito** |
| Pendiente de aprobación del proveedor | Producción | Los tres proveedores |

## 2. Arquitectura

Código en `app/Payments/Gateways/`. El núcleo es neutro; cada proveedor es un adaptador.

- `GatewayAdapter`: contrato de un proveedor (configuración, referencia propia del proveedor, crear sesión, reanudar, **consultar** el estado desde el servidor). `RefundsPayments` lo implementan solo los que de verdad anulan. `GatewayCapabilities` declara lo que el proveedor soporta; Ava no ofrece ni simula lo demás.
- `PaymentGatewayManager`: qué pasarelas conoce Ava (`config/payments.php` `gateways`: clave => clase).
- `GatewayAvailability`: quién puede cobrar con qué (§4).
- `PaymentService`: **único** lugar que crea y mueve transacciones (§3 y §5).
- `PaymentLinks` / `Payables`: enlace del cliente y búsqueda del concepto cobrable. Hoy solo `order`; un depósito de cita sería otra entrada en `config/payments.php` `payables` que implemente `Payable`.
- `GatewayOverrides`: concesión o revocación administrativa por Workspace (auditada, con motivo obligatorio). Pantalla en §4b.
- `ReconcilePaymentTransaction` (job) y `payments:reconcile` (cada minuto).

### Tablas (migración `2026_11_25_100000_create_payment_gateway_tables`, aditiva)

Por qué no sirven las existentes: `payment_events` es del registro público (apunta a `signup_requests`, sin Workspace); `payment_provider_settings` es la cuenta única de plataforma; `workspace_module_overrides` apunta a `modules` y una pasarela no es un módulo; `integrations` guarda credenciales pero no los pagos.

- `payment_links`: solo guarda el SHA-256 del token (48 caracteres, caduca a las 72 h, revocable).
- `payment_transactions`: un intento de cobro. `watch_until`: hasta cuándo se sigue preguntando por un intento que Ava cerró por su cuenta (§5b). Claves compuestas `(id, workspace_id)` hacia `integrations` y `payment_links`: la base rechaza una conexión de otro Workspace. `active_key` único mientras la transacción está abierta: **un pedido no puede tener dos cobros abiertos**. `secrets` (sesión del proveedor) cifrado y oculto.
- `payment_transaction_events`: historial; `event_key` único por transacción (el mismo evento del proveedor se procesa una vez).
- `workspace_payment_gateway_overrides` y `plans.payment_gateways` (JSON; **NULL = ninguna**).

### Identificadores (nunca se mezclan)

| Columna | Qué es |
|---|---|
| `reference` | Referencia interna de Ava, única (`AVA` + 13 caracteres) |
| `provider_reference` | Lo que Ava envía al proveedor como id de la orden (lo define el adaptador según el límite del proveedor) |
| `external_id` | Lo que el proveedor responde (por ejemplo `AzulOrderId`) |
| `authorization_code` | Código de autorización |
| `refund_external_id` | Id de la anulación en el proveedor |
| `secrets` (cifrado) | Material de sesión (por ejemplo `SESSION` y `session-key` de CardNET) |
| `callback_token_hash` | Token opaco de las URLs de retorno de **esa** transacción |

## 3. Estados

`pending`, `processing`, `approved`, `declined`, `cancelled`, `expired`, `verification_required`, `refund_pending`, `refunded` (`PaymentStatus`).

- Un pago aprobado **no vuelve** a pendiente ni rechazado: solo `refund_pending` y `refunded`.
- Una consulta fallida, una respuesta ilegible o un importe distinto dejan la transacción en `verification_required`, **nunca** en rechazo.
- Una transacción cerrada (rechazada, cancelada, expirada) solo se reabre a `approved` por una respuesta **verificada** del proveedor.
- Cada movimiento y cada señal quedan en `payment_transaction_events`.
- El estado de pago de un **pedido se deriva** de sus transacciones (`PaymentService::summary`); `orders` no tiene columna de pago, así no puede contradecirse.

## 4. Disponibilidad (todo en el servidor)

Orden de decisión (`GatewayAvailability::describe`):

1. **Plan y administración.** El plan debe listar la pasarela y la administración no debe haberla **revocado**; o la administración la **concedió**. Solo la administración de plataforma escribe concesiones; un Workspace no puede habilitársela. Una revocación gana al plan.
2. **Conexión.** Existe, está completa, **activa**, apunta a un ambiente permitido (Producción solo si `PAYMENTS_ALLOW_PRODUCTION=true`) y fue **validada** con una prueba real. Una concesión nunca hace usable una conexión incompleta o sin validar.
3. **Compatibilidad.** El proveedor atiende la moneda del pedido y el país fiscal del Workspace.

Estados que ve el cliente en las tarjetas: `plan_disabled`, `admin_disabled`, `not_configured`, `incomplete`, `saved` (guardada, no validada), `error`, `inactive`, `blocked_environment`, `incompatible`, `validated`.

`plans.payment_gateways` es **cerrado por defecto** (NULL = ninguna), a diferencia de `plans.channels` (NULL = todos). Los planes existentes no ganan ninguna pasarela.

## 5. Flujo de pago de un pedido

1. Personal con `request-order-payments` genera el enlace desde el pedido (`POST /orders/{id}/payment-link`). El enlace se muestra una vez.
2. El cliente abre `/pay/{token}`. El servidor decide importe, moneda, Workspace y métodos; el navegador solo elige un método.
3. `POST /pay/{token}/start` → `PaymentService::checkout`: valida Workspace, módulo, pedido abierto y disponibilidad; crea o reutiliza la transacción (idempotente por intento); pide la sesión al proveedor **fuera** del bloqueo de base de datos; envía al cliente al proveedor (redirección o formulario).
4. El proveedor devuelve al navegador a `/api/payments/return/{token}` o `/cancel/{token}` (token de la transacción). **No prueba nada**: se registra como pista y se **consulta al proveedor desde el servidor**.
5. La respuesta se aplica con `PaymentService::apply`: compara importe, moneda, referencia e id externo; si algo no coincide, `verification_required` y alerta operativa.
6. Si el cliente no vuelve, `payments:reconcile` pregunta al proveedor (§5b). Nunca cobra.
7. `/pay/status/{token}` muestra el estado que conoce el servidor y se refresca solo mientras esté abierto.

Cancelar un pedido: un cobro pendiente que el cliente nunca abandonó se cierra con él; con un pago aprobado, en proceso o en verificación se **rechaza** la cancelación hasta resolverlo o reembolsarlo.

## 4b. Excepciones por Workspace (pantalla)

`Planes > Suscripción y límites del Workspace > Pasarelas de pago` (`GET /workspaces/{id}/modules`, `PUT /workspaces/{id}/payment-gateways`). Es la misma pantalla y el mismo permiso que las excepciones de módulos: ruta tras `workspace.platform:assign-plans` (solo la administración de plataforma dentro de `Desarrollo_DEV`; un administrador de Workspace, incluso superusuario, recibe 403).

- Por pasarela muestra: si el plan la incluye, la excepción (concedida o revocada, **motivo, quién y cuándo**) y el estado efectivo con su razón (el mismo cálculo que usa el cobro).
- Conceder, revocar o quitar la excepción. El **motivo es obligatorio** (mínimo 5 caracteres) al conceder o revocar. Cada cambio queda en auditoría (recurso «Pasarelas de pago»), con el motivo.
- Precedencia: revocación > concesión > plan. **Una concesión solo permite la pasarela**: la conexión sigue necesitando estar completa, activa y validada, y el ambiente autorizado. El Workspace administrativo no admite excepciones.

## 5b. Los cuatro relojes (nada de esto significa «el pago falló»)

| Reloj | Valor | Qué hace |
|---|---|---|
| Enlace de pago | 72 h (`payments.link_ttl_hours`) | Solo controla el acceso a la **página**. No afecta una transacción ya iniciada |
| Sesión del proveedor | La que declare el adaptador (`GatewayCheckout::$expiresAt`) | Ava no la adivina. Hoy ninguna: CardNET no está implementado |
| Ventana del cliente | 60 min (`transaction_ttl_minutes`) | Si el cliente **nunca volvió** y el proveedor sigue esperándolo, Ava libera el pedido (`expired`). Cierra la oferta de Ava, **no el dinero** |
| Vigilancia tardía | 72 h (`late_watch_hours`) con pausas de 10, 30, 60, 180, 360 y 720 min | Un intento que Ava cerró sola (expirado, sustituido por otro, pedido cancelado) se sigue consultando: si el proveedor lo aprueba, se reabre y el pedido queda pagado. Si ya había otro intento en curso, se cierra y se avisa (`payment_duplicate_risk`) |
| Incertidumbre | 48 h o 20 consultas (`give_up_hours`, `max_attempts`) | Un pago al que el cliente **volvió**, o cuya consulta falló, **nunca caduca por tiempo**: se sigue preguntando con *backoff* (1, 2, 5, 10, 20, 40, 60, 120 min) y, al agotarse, queda para revisión manual (`manual_review`, alerta `payment_needs_review`) |

Si el cliente vuelve a un intento que Ava cerró, se consulta al proveedor en ese momento.

**Después de la vigilancia** Ava deja de preguntar por su cuenta, pero **no supone que una aprobación posterior sea imposible**: (1) una notificación del proveedor (webhook) siempre se verifica y se aplica, tenga la antigüedad que tenga; (2) el personal puede pulsar **«Verificar»** en cualquier intento del pedido, en cualquier estado y en cualquier momento (`PaymentService::reverify`, permiso `request-order-payments`, solo dentro de su Workspace y pedido), y un intento en revisión manual se vuelve a mirar desde cero; (3) un cliente que vuelve a un intento cerrado dispara una consulta. Para un proveedor sin webhook (CardNET) solo valen (2) y (3) pasadas las 72 h.

## 5c. Pagos duplicados: detección y resolución

Dos intentos del mismo pedido pueden terminar aprobados (el cliente paga el intento viejo y el nuevo). Ava **no pierde ni fusiona nada**:

1. Se bloquean todos los intentos del pedido en el mismo orden (`apply`), así dos aprobaciones simultáneas hacen cola y no se bloquean entre sí (probado con 2 procesos en PostgreSQL: ambos aprobados, sin error).
2. Al aprobarse un intento, los demás intentos abiertos del pedido se cierran (con vigilancia) y, si alguno ya había llegado al proveedor, se lanza la alerta `payment_duplicate_risk`.
3. Si ya había otro pago aprobado, el segundo queda aprobado con `failure_code = duplicate_payment`, se lanza `payment_duplicate_approved` y el pedido pasa a «Pago duplicado» (en rojo en la tarjeta de pago, con una insignia «Duplicado» en el intento sobrante).
4. **Resolución:** devolver el cobro sobrante desde el portal del proveedor. Cuando el proveedor lo informe (Wompi: evento `VOIDED`), Ava marca ese intento como `refunded`, la alerta `payment_returned_by_provider` queda registrada y el pedido vuelve a «Pagado». Mientras haya un pago aprobado, en proceso o en verificación el pedido no se puede cancelar.
5. Ava no devuelve dinero por su cuenta: ningún proveedor conectado tiene una anulación por API verificada.

## 6. Seguridad aplicada en el núcleo

- Importe y moneda salen del pedido; el cuerpo de la petición se ignora.
- Token del enlace: 48 caracteres aleatorios, solo su hash en base de datos, caduca y se revoca. Enlace desconocido, caducado o revocado: el mismo 404.
- Token de retorno por transacción (40 caracteres, solo hash). Un retorno o una cancelación falsificados no cambian el estado: lo decide la consulta al proveedor.
- Reintento del mismo evento del proveedor (`event_key`): una sola vez. Aprobación tardía o repetida: no revierte nada.
- Límites de frecuencia: `payment-page`, `payment-start`, `payment-callback`.
- Destino del cliente: solo `https` (y `http` únicamente en pruebas/local), sin usuario en la URL y con un host que el adaptador declaró (`hosts()`). Esos mismos hosts son los únicos que se añaden al `form-action` de la política de seguridad de contenido.
- Errores del proveedor nunca llegan al cliente ni a los registros (códigos normalizados).
- No existe modo de pago simulado en la aplicación. El doble de pruebas vive solo en `tests/`.
- Auditoría: enlaces, aprobaciones, reembolsos, cambios de pasarelas del plan y concesiones/revocaciones por Workspace.

## 7. Cómo añadir un proveedor

1. Clase que implemente `GatewayAdapter` (y `RefundsPayments` solo si anula de verdad).
2. Un `IntegrationType` con la clave del proveedor (credenciales cifradas, solo escritura) registrado en `config/integrations.php` (`dedicated`).
3. Registrar la clave en `config/payments.php` `gateways`.
4. Hosts del proveedor en `config/security.php` `form_hosts` si usa formulario `POST`.
5. Pruebas con `Http::fake` y, cuando haya credenciales, pruebas de laboratorio documentadas aparte.

## 8. Cómo ejecutar las pruebas

```
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: FILESYSTEM_DISK=local CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync BROADCAST_CONNECTION=null php -d memory_limit=2G vendor/bin/phpunit --do-not-cache-result tests/Feature/PaymentCoreTest.php
```

Otras: `tests/Feature/PaymentGatewayAdministrationTest.php`, `tests/Feature/CardnetConfigurationTest.php` y `tests/Feature/WompiWorkspaceTest.php` (la API y los eventos de Wompi están simulados con `Http::fake`: no son una prueba de Sandbox).

Regresión del Wompi global: `WompiPaymentTest`, `WompiConfigTest`, `PaymentSettingsTest`, `WebhooksTest`.

## 9. CardNET

### Estado real

| Parte | Estado |
|---|---|
| Tarjeta en Integraciones (sección «Pasarelas de pago») y formulario por secciones | Hecho, probado |
| Guardado cifrado, valores enmascarados (`••••1300`), auditoría sin valores, aislamiento por Workspace | Hecho, probado |
| Control por plan, estados (`No configurada`, `Configuración incompleta`, `Guardada, sin validar`, `Validada`, `Error`, `Deshabilitada por el plan`, `Desactivada por administración`) | Hecho, probado |
| Guardar una configuración incompleta sin activarla ni validarla | Hecho, probado |
| Prueba de conexión | **No existe** (`supportsTest() = false`): la tarjeta lo dice y la ruta lo rechaza. Una configuración guardada nunca llega a «Validada» |
| Crear sesión, enviar al cliente, consultar el resultado, anular | **No implementado**: `CardnetAdapter` lanza `protocol_pending` y su consulta responde «desconocido». `capabilities()` declara consulta y reembolso en falso |
| Pago de CardNET ofrecido a clientes | **Nunca** mientras no esté validado; aun forzando la validación en la base de datos, el cobro se rechaza y no deja nada abierto |

### Campos del formulario

La columna «Fuente» es honesta: ningún campo está confirmado por la especificación oficial (no pudo leerse). Los nombres vienen del formulario de laboratorio que usa el equipo de CCG.

| Campo (nombre de CardNET) | Dónde se guarda | Obligatorio para considerarla completa | Validación local | Estado |
|---|---|---|---|---|
| Número de comercio (`MerchantNumber`) | Cifrado | Sí | Solo dígitos, máx. 15 | Nombre y obligatoriedad: formulario de CCG y guía parcial. Longitud exacta por confirmar |
| Terminal (`MerchantTerminal`) | Cifrado | Sí | Solo dígitos, máx. 12 | Igual |
| Terminal Amex (`MerchantTerminal_amex`) | Cifrado | No | Solo dígitos, máx. 12 | Por confirmar si existe y cuándo se usa |
| Categoría (`MerchantType`) | Configuración | Sí | 4 dígitos | MCC; por confirmar |
| Nombre del comercio (`MerchantName`) | Configuración | Sí | Máx. 40 | Límite y formato por confirmar |
| Ambiente | Configuración | Sí | Laboratorio / Producción | Hosts dados por CCG: `labservicios.cardnet.com.do` y `ecommerce.cardnet.com.do`. **Las rutas no están confirmadas** |
| Tipo de transacción (`TransactionType`) | Configuración | No | 3 o 4 dígitos | Por confirmar: `200` o `0200` |
| Idioma | Configuración | No | ES / EN | Por confirmar el nombre del campo (`PageLanguage` o `PageLanguaje`) |
| Correo, teléfono, dirección, ciudad, provincia, país, código postal | Configuración (auditoría sin valores) | No | Formato básico | Por confirmar si CardNET exige AVS/3DS y cuáles |

No se piden claves secretas porque ninguna está confirmada: la `session-key` que parece existir sería **por transacción** (se guardaría cifrada en `payment_transactions.secrets`). La moneda no es un campo: el código numérico de `DOP` es `214` (ISO 4217) y se toma de la moneda del pedido; el importe lo calcula el servidor y su formato por CardNET **está por confirmar**.

### Puntos bloqueados por la documentación oficial

1. Petición de creación de sesión: ruta, método, campos, obligatoriedad y nombres exactos.
2. Unidad y formato de `Amount` (y si hay un campo de impuesto).
3. Cómo se envía al cliente a CardNET (formulario `POST`, ruta, campos).
4. Retorno y cancelación: qué recibe Ava y cómo se relaciona con la transacción.
5. Consulta del resultado: ruta, autenticación, campos y **códigos de respuesta** (aprobado, rechazado, pendiente).
6. Vigencia de la sesión.
7. Anulaciones y reembolsos (hoy se declaran no soportados).
8. Si el laboratorio atiende `POST` desde un navegador con CSP.

Cuando exista la especificación, solo cambian `createSession`, `resume` y `query` de `CardnetAdapter`, `supportsTest()`/`test()` de `CardnetType` y las reglas de validación de §9; el núcleo, los estados, la conciliación y la interfaz no cambian.

### Cómo obtener las credenciales de laboratorio

1. Escribir al ejecutivo de cuenta de CardNET pidiendo acceso al **ambiente de laboratorio de comercio electrónico (Botón de pago)** y a su portal de desarrolladores.
2. Pedir: número de comercio y terminal(es) del laboratorio, categoría del comercio, tarjetas de prueba (aprobada, rechazada, 3DS) y la especificación vigente (versión 1.2 o posterior) en PDF.
3. Preguntar expresamente por: unidad del importe, campos AVS/3DS obligatorios, códigos de respuesta, vigencia de la sesión y si existe anulación por API.
4. Para producción: el proceso de certificación del comercio y la aprobación de CardNET antes de activar `PAYMENTS_ALLOW_PRODUCTION`.

### Qué puede probar tu compañera ahora

- Con CCG, en `Integraciones > Pasarelas de pago`: configurar CardNET (completa e incompleta), ver el estado «Guardada, sin validar», comprobar que los datos se muestran enmascarados y que «Activar» no la valida.
- En `Planes`, incluir CardNET en un plan; en `Suscripción y límites del Workspace`, conceder o revocar con motivo.
- Ejecutar las pruebas automáticas (§8): `PaymentCoreTest`, `PaymentGatewayAdministrationTest`, `CardnetConfigurationTest`.

### Qué todavía no puede probarse

Cualquier pago, retorno, consulta, anulación o prueba de conexión con CardNET, y la conciliación contra un proveedor real.

## 10. Wompi por Workspace

Código: `WompiType` (formulario y validación), `WompiAdapter` (cobro y consulta), `WompiClient` (llamadas a la API), `WompiEventHandler` + `WompiEventSignature` + `WompiEventToken` (eventos), `WompiWorkspaceWebhookController`. Tarjeta en `Integraciones > Pasarelas de pago`.

### Diferencias con el Wompi global

| | Wompi global (registro y suscripciones) | Wompi por Workspace |
|---|---|---|
| Cuenta | La de CCG (una) | La de cada comercio (una por Workspace) |
| Credenciales | `payment_provider_settings` / `WOMPI_*` | `integrations` (tipo `wompi`), cifradas |
| Cobra | Contratación de planes (`SignupRequest`) | Pedidos (`payment_transactions`) |
| Eventos | `POST /api/webhooks/wompi` | `POST /api/webhooks/payments/wompi/{token}` (uno por conexión) |
| Historial de llamadas | `wompi` | `wompi_workspace` (con el Workspace) |
| Tablas de eventos | `payment_events` | `payment_transaction_events` |

No comparten clases, tablas, rutas ni configuración (el verificador de firma se escribió aparte a propósito). Los archivos del Wompi global no cambiaron.

### Flujo

1. El cliente elige Wompi en `/pay/{token}`. Ava arma la URL del Web Checkout (`https://checkout.wompi.co/p/`) con `public-key`, `currency=COP`, `amount-in-cents` (del pedido), `reference` (referencia propia de la transacción), `signature:integrity` = SHA-256(`reference + amount + currency + secreto de integridad`) y `redirect-url`. No envía `expiration-time` (no se pudo verificar su formato).
2. El cliente paga en Wompi. Wompi devuelve el navegador a `redirect-url` con `?id=<transacción de Wompi>` y envía un evento firmado `transaction.updated`.
3. **El retorno no aprueba nada.** El `id` de la URL es solo un candidato: Ava lo consulta (`GET /v1/transactions/{id}`) y lo acepta únicamente si la referencia de lo que Wompi responde es la de esta transacción; un `id` ajeno no aprueba ni rechaza.
4. **El evento tampoco aprueba:** se comprueba la firma (SHA-256 de las propiedades indicadas en `signature.properties` + `timestamp` + secreto de eventos, contra `signature.checksum` y `X-Event-Checksum`), se ignora el ambiente contrario, se busca la transacción **dentro de esa conexión y Workspace** por su referencia, se vuelve a leer la transacción en la API de Wompi y se comparan referencia, importe y moneda. Si Wompi no responde, se contesta 503 (Wompi reintenta a los 30 min, 3 h y 24 h), el pago queda «en verificación» y el evento no se consume.
5. Estados de Wompi → Ava: `APPROVED` → aprobado; `DECLINED` y `ERROR` → rechazado; `PENDING` → en proceso; `VOIDED` → reembolsado (devuelto en el portal de Wompi); otro valor → desconocido, nunca un supuesto.
6. Eventos repetidos (mismo id, estado y `timestamp`) se aplican una vez; los desordenados son inofensivos porque cada uno provoca una lectura nueva y un pago aprobado no retrocede. Si el cliente reintenta dentro del mismo checkout de Wompi (misma referencia, otra transacción), el evento de la que se aprueba reabre el intento rechazado.
7. Sin `id` ni evento, Ava no tiene nada que consultar: el intento sigue «pendiente» (no «en proceso»), caduca a los 60 min y se sigue vigilando (§5b); el evento tardío lo encuentra.

### Qué se pide al comercio y dónde

En el panel de Wompi del comercio (Desarrolladores): **llave pública** (`pub_test_…` / `pub_prod_…`), **secreto de integridad** (`test_integrity_…` / `prod_integrity_…`) y **secreto de eventos** (`test_events_…` / `prod_events_…`). No se pide la llave privada: Ava no la usa. En `Integraciones > Pasarelas de pago > Wompi > Configurar`: elegir el ambiente, pegar las tres, guardar, copiar la **dirección de eventos** que muestra el formulario y pegarla en el panel de Wompi (Desarrolladores > Eventos), una por ambiente. Cambiar el ambiente obliga a escribir las llaves de nuevo; «¿Generar una dirección nueva?» revoca la anterior.

La conexión nace inactiva. Para que se ofrezca a los clientes: plan (o concesión) + configuración completa + **Activar** + **Probar conexión** satisfactoria + moneda COP y país CO.

### La prueba de conexión (real, y qué demuestra)

Pide a Wompi `GET /v1/merchants/{llave pública}` en el ambiente elegido. Si lo reconoce, la conexión pasa a «Validada». **No puede probar los dos secretos** (Wompi no ofrece cómo): esos se prueban con el primer pago de prueba y su evento. Cualquier cambio de configuración descarta la validación.

### Procedimiento de laboratorio para quien tenga las credenciales (Sandbox)

1. En un Workspace con plan Colombia (o concesión) crear un pedido en COP.
2. Configurar Wompi en Sandbox con las tres claves, copiar la dirección de eventos a Wompi (ambiente Sandbox), **Activar** y **Probar conexión** → debe quedar «Validada».
3. Generar el enlace de pago desde el pedido y pagar con una tarjeta de prueba aprobada (los datos de prueba están en la guía «Datos de prueba» de Wompi). Esperar el evento: la tarjeta de pago del pedido debe pasar a «Pagado» y el intento mostrar el evento `notification`.
4. Repetir con una tarjeta rechazada: el intento queda «Rechazado» y se puede reintentar.
5. Cerrar el navegador antes de volver y comprobar que el evento solo basta para marcar el pago.
6. Reenviar el mismo evento desde el panel de Wompi: no debe duplicar nada. Con la dirección de eventos mal escrita Wompi debe recibir 404.
7. Pagar el mismo pedido dos veces (dos ventanas): debe aparecer «Pago duplicado»; devolver el sobrante desde el portal de Wompi y comprobar que Ava lo marca reembolsado.
8. Anotar qué devuelve Wompi en `GET /v1/transactions/{id}` (¿pide la llave pública como `Bearer`?) y si `GET /v1/transactions?reference=` existe: son los dos puntos que la documentación leída no confirma.

### Pendientes reales para Producción (no se declara lista)

- Ninguna prueba en Sandbox real; los puntos del paso 8.
- Anulación por API: documentada (`POST /v1/transactions/{id}/void` con la llave privada) pero su respuesta y si admite importe parcial no se pudieron verificar; no se implementó.
- `PAYMENTS_ALLOW_PRODUCTION=true` solo tras la aprobación del comercio y de Wompi, con llaves de Producción y la dirección de eventos de Producción.
- Decidir el plazo de retención de las tablas de pagos (§ RECOMENDACIONES).

## 10b. Azul

Sin empezar. Necesita su documentación (orden de los campos del `AuthHash` y de la firma de respuesta, si la Payment Page envía un webhook o solo un `POST` del navegador, `ResponseCode`/`IsoCode`, método de verificación, `VoidTransaction`), credenciales, hosts y el certificado mTLS.

## 11. Migración

`2026_11_25_100000_create_payment_gateway_tables`. La fecha continúa la secuencia del repositorio (las migraciones existentes ya llegan a `2026_11_24`, por delante de la fecha real; el orden de ejecución es el del nombre): es posterior a todas, ninguna otra depende de ella ni de sus tablas, y las suyas dependen solo de `workspaces`, `integrations` y `users`, que existen antes. Reversible (`down()` quita las cuatro tablas y la columna). Verificada en PostgreSQL 16 desechable: aplicar, revertir y volver a aplicar; la base rechaza una transacción con la conexión de otro Workspace, un segundo cobro abierto del mismo pedido y una clave de idempotencia repetida. **No está aplicada en `ccg_ava`**.
