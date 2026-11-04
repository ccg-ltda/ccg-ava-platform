# Política de cookies de Ava Platform

**Versión 0.2 (borrador) · BORRADOR PARA REVISIÓN DE ABOGADO COLOMBIANO. No aprobado.**

## 1. Qué son

Las cookies son pequeños archivos que el navegador guarda cuando visitas un sitio. También hay tecnologías equivalentes, como el almacenamiento local del navegador (`localStorage`). Cuando permiten identificar o hacer identificable a una persona, su uso es tratamiento de datos personales y queda sujeto a la Ley 1581 de 2012.

## 2. Qué usa Ava Platform hoy (inventario verificado en el código)

| Nombre / tecnología | Finalidad | Categoría | Duración | Requiere consentimiento |
|---|---|---|---|---|
| Cookie de sesión de Laravel (`…_session`) | Mantener la sesión iniciada; el servidor la limita a 12 horas | **Estrictamente necesaria** | Sesión / 12 h | No |
| `XSRF-TOKEN` y token CSRF | Proteger los formularios contra solicitudes falsificadas | **Estrictamente necesaria** | Sesión | No |
| Cookies de «Recordar sesión» (correo y Workspace, cifradas y `HttpOnly`) | Prellenar el acceso si el usuario lo pide marcando la casilla | Funcional, **activada solo por el usuario** | `AUTH_REMEMBERED_ACCESS_MINUTES` | Se activa por una acción expresa del usuario (la casilla) |
| `localStorage`: `ccg-theme`, `ava.app-theme` | Recordar modo claro/oscuro | Preferencia de interfaz | Hasta que el usuario la borre | No contiene datos personales; informativa |
| Cookies/almacenamiento del widget de chat de un Workspace (identificador de sesión del visitante) | Mantener la conversación del visitante con el asistente del Cliente | Funcional del Cliente (Responsable: la organización cliente) | **[POR CONFIRMAR]** | Lo define el Cliente en su sitio; ver 05 |

Ava Platform **no** usa hoy cookies de analítica, publicidad ni seguimiento de terceros, y no carga scripts de seguimiento. (Dato a mantener actualizado: si se agrega cualquier cookie no necesaria, hay que activar antes un mecanismo de consentimiento previo.)

## 3. Consentimiento y preferencias

- Las cookies **estrictamente necesarias** no requieren consentimiento porque sin ellas el servicio solicitado no funciona; esta política cumple el deber de informar.
- Si en el futuro se incorporan cookies de análisis, personalización o publicidad, se mostrará un aviso con opciones **«Aceptar» y «Rechazar» de igual visibilidad**, sin casillas premarcadas, y no se instalarán hasta que el usuario decida. El usuario podrá cambiar su elección en cualquier momento desde el pie de página.
- Mientras solo existan cookies necesarias y preferencias de interfaz, **no se muestra un banner de consentimiento**, porque no habría una decisión que pedir. (Decisión a validar por el abogado.)

## 4. Cómo controlarlas

El usuario puede borrar o bloquear cookies desde la configuración de su navegador; bloquear las necesarias impedirá iniciar sesión o enviar formularios.

## 5. Responsable y contacto

[POR CONFIRMAR: razón social, NIT, correo]. Para las cookies o el almacenamiento del chat de un sitio de un Cliente, el Responsable es ese Cliente.

## 6. Cambios

Se publicará la nueva versión con su fecha. La versión vigente se consulta en `/legal/politica-de-cookies`.
