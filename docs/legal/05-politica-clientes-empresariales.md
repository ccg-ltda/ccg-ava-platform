# Política de tratamiento de datos para clientes empresariales y Workspaces

**Versión 0.2 (borrador) · BORRADOR PARA REVISIÓN DE ABOGADO COLOMBIANO. No aprobado.**

Documento dirigido a la organización que contrata Ava Platform («Cliente») y que usa el Servicio para atender a sus propios clientes finales. Explica el reparto de responsabilidades; el detalle contractual está en el *Acuerdo de tratamiento de datos* (documento 06).

## 1. Reparto de papeles

El Cliente es el **Responsable** (Ley 1581 de 2012, art. 3.e) de los datos personales de sus clientes finales: decide para qué se recolectan y cómo se usan. CCG es **Encargado** (art. 3.d): trata los datos por cuenta del Cliente y solo para prestarle el Servicio. Cada Workspace está aislado de los demás.

## 2. Obligaciones del Cliente como Responsable

1. Tener su **propia política de tratamiento y aviso de privacidad** (Decreto 1074 de 2015, arts. 2.2.2.25.3.1 y 2.2.2.25.3.3) y publicarlos donde sus clientes finales tengan contacto con él (por ejemplo el sitio donde se instala el widget o el canal de WhatsApp).
2. Obtener la **autorización previa, expresa e informada** de sus clientes finales, o contar con otra base legal prevista en el art. 10 de la Ley 1581, y poder probarla. El módulo Clientes de Ava registra el consentimiento de comunicaciones (concedido, revocado o desconocido) y cómo se obtuvo, **pero no sustituye** la autorización de tratamiento que el Cliente debe recolectar.
3. **Informar** al titular, antes de la conversación, que interactúa con un asistente de inteligencia artificial cuando sea el caso (documento 10) y las finalidades del tratamiento.
4. No tratar **datos sensibles** (salud, biométricos, origen racial, orientación sexual, etc.) ni de **menores** sin cumplir los arts. 5 a 7 de la Ley 1581: autorización expresa, información de que responder es facultativo y protección reforzada.
5. Atender los **derechos de los titulares** (consultas, reclamos, rectificación, supresión). Ava ofrece herramientas (anonimizar una ficha de Cliente, exportar auditoría), pero la decisión y la respuesta son del Cliente.
6. Cumplir las reglas de **comunicaciones comerciales** (documento 08) antes de enviar mensajes masivos o de promoción; Ava no envía campañas reales hoy.
7. Definir **cuánto tiempo conserva** conversaciones, clientes, reservas y pedidos, y ejecutar su eliminación.
8. Evaluar si debe **inscribir sus bases de datos en el RNBD** (Decreto 1074, Capítulo 26 y Decreto 090 de 2018: depende de sus activos totales) y si le aplican normas sectoriales (salud, financiero).
9. Tener contratos de **transmisión** con sus propios proveedores adicionales (por ejemplo su instancia de n8n o su tienda WooCommerce, si son de un tercero).

## 3. Lo que hace CCG como Encargado

Tratar los datos solo conforme a las instrucciones del Cliente; mantener la confidencialidad y la seguridad (Ley 1581, art. 18); aislar los datos por Workspace; no usar los datos para fines propios ni para entrenar modelos de terceros por su cuenta; no ceder los datos; ayudar al Cliente a atender solicitudes de titulares; notificar los incidentes de seguridad que afecten los datos del Cliente sin demora injustificada **[POR CONFIRMAR: plazo, p. ej. 72 h]**; y suprimir o devolver los datos al terminar el contrato.

## 4. Acceso excepcional de soporte

Por regla general, el personal de CCG **no** lee el contenido de las conversaciones de un Workspace. Solo puede hacerlo mediante un acceso de soporte temporal, con motivo registrado, por tiempo limitado y auditado en el Workspace del Cliente (documento `docs/privacidad.md`). Este acceso no está asignado a nadie por defecto.

## 5. Proveedores y subencargados

El Cliente autoriza a CCG a usar los subencargados de la lista vigente: **[POR CONFIRMAR: alojamiento/AWS, correo, mensajería, n8n, modelo de IA, pagos]**. CCG informará los cambios con antelación razonable y el Cliente podrá oponerse por motivos fundados.

## 6. Actividad técnica de los asistentes

Los registros técnicos de las llamadas del asistente se guardan sin credenciales y con correos y teléfonos enmascarados; el contenido se conserva 30 días y los metadatos 180 (el Cliente puede desactivar la persistencia del contenido en Configuraciones). Solo ven el contenido los usuarios con el permiso específico.

## 7. Terminación

Al terminar el contrato el Cliente puede pedir la devolución o exportación de sus datos dentro de **[POR CONFIRMAR]** días; luego se eliminan o anonimizan, salvo lo que la ley obligue a conservar.
