# Guía de la colección Postman

Archivo único: `postman/ava-platform.postman_collection.json` (Collection v2.1). No hay otra colección.

## 1. Importar y configurar
1. Postman → *Import* → el archivo. (Validado: JSON válido, 11 carpetas, ~370 solicitudes.)
2. Crear un *Environment* con `base_url` y, para el grupo 01, `agent_token` (token del chatbot de PRUEBAS). El resto de variables tiene valores ficticios o vacíos; las **secretas** (`agent_token`, `meta_app_secret`, `wompi_events_secret`, `user_password`, `woo_consumer_*`, `woo_webhook_secret`) nunca vienen con valor.
3. El Workspace **no** se envía en las APIs del agente: lo determina el token (`workspace_id` es solo informativa).

## 2. Autenticación por grupo
| Carpeta | Mecanismo real |
|---|---|
| 00 Acceso, 06 Web autenticada, 07 Administración | Sesión web (cookies) + CSRF (`xsrf_token`) + permiso y módulo |
| **01 API del agente (n8n)** | **Bearer** con el token del chatbot (`agent_token`) |
| 02 Widget, 03 Reserva pública | Clave pública en la URL (`widget_key`, `booking_key`), sin sesión |
| 04 Webhooks entrantes | Firma del proveedor (Meta `X-Hub-Signature-256`, Wompi) o `X-Ava-Signature` (endpoints entrantes) |
| 05 Invitados | Sin sesión (token del enlace) |
| 08 Horizon | Sesión + `HORIZON_ALLOWED_EMAILS` |
| 10 WooCommerce | API **externa** de la tienda, HTTP Basic (`woo_consumer_key` / `woo_consumer_secret`) |

## 3. Ejecutar el grupo 01 (el flujo n8n)
Orden: Configuración → Mensaje entrante → **Reintento** (espera 200 y `created:false`) → Contexto → Contexto solo nuevos → Guardar contexto → Authorize → **Handoff** (`outcome: requested`) → **Reintento de handoff** (`executed:false`) → Paso técnico inicio/fin → Mensaje saliente → Reserva → Encuestas → Errores esperados (401, 422, 404). Los scripts guardan `message_id`, `control_version` y `last_message_id`, y validan código HTTP, estructura e idempotencia.

Con Newman (la colección ya se ejecutó así contra un servidor local desechable: 36 solicitudes, 52 aserciones, 0 fallos, dos veces seguidas):
```bash
npx newman run postman/ava-platform.postman_collection.json \
  --folder "01 API del agente (n8n) — Bearer del chatbot" \
  --env-var base_url=http://127.0.0.1:8011 --env-var agent_token=<token de prueba> \
  --env-var contact_id=<un id nuevo para empezar de cero>
```
**Nunca** apuntes la colección a `ccg_ava` ni a datos reales: escribe mensajes, pasos, reservas y encuestas. Procedimiento para un servidor desechable: crear una base `*_testing`, `php artisan migrate`, sembrar un Workspace y un chatbot, `php -S 127.0.0.1:8011 -t public`, con las variables `DB_*` apuntando a esa base.

## 4. Notas
- Las solicitudes de reservas y pedidos del grupo 01 devuelven **403** si el plan del Workspace no incluye esos módulos; sus scripts lo aceptan y lo dicen.
- Booker no tiene solicitudes: las reservas externas llegan por `POST /api/agent/booking-records`.
- Los grupos 06 y 07 son un inventario de solo lectura de rutas web; sus solicitudes no traen scripts y requieren sesión.
