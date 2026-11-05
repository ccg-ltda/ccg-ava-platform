# Recomendaciones: pendientes, riesgos y decisiones

Único documento de pendientes del proyecto. Solo lo abierto: cuando algo se cierra, se borra de aquí (el historial está en Git). Reglas permanentes: `reglas.md`. Formato: **Pendiente** · Acción · Bloqueo · Estado.

## 1. Estado actual del proyecto

- **Implementado y probado en local:** multi-Workspace con aislamiento, roles y permisos por operación, planes y módulos, conversaciones con IA y operadores humanos, API de agentes para n8n (mensajes, contexto, handoff, actividad técnica, reservas, encuestas), reservas (agenda propia y registro unificado de Booker/Lava/Ava), catálogo, pedidos, inventario por sede, clientes, campañas (sin envío real), recordatorios, reportes, auditoría, webhooks de salida y entrantes, contratación pública, invitaciones por Workspace, documentos legales (borradores).
- **Niveles de evidencia:** *automatizada* (PHPUnit en SQLite en memoria, PostgreSQL desechable, JS, build, Pint, Newman), *simulada* (`Http::fake`, dobles de Meta, n8n, Booker, Wompi) y *real* (servicio externo). **No existe ninguna validación real** de n8n, WooCommerce, Booker, Meta, Wompi, SIGO, correo ni AWS: una prueba simulada que pasa no cierra un pendiente de integración.
- **Última verificación (2026-11-04):** suite PHP completa 2 638 pruebas, 0 fallos, 15 omitidas (9 por límites de SQLite/GD/build y 6 de concurrencia que solo corren en PostgreSQL); PostgreSQL desechable 75/75 (carreras de reservas, inventario, handoff, mensajes, pasos, reservas y encuestas); JS 138/138; build y Pint correctos; Postman carpeta 01 con Newman 36 solicitudes, 52 aserciones, 0 fallos.
- **Base `ccg_ava` (desarrollo):** migraciones al día (0 pendientes) y permiso `view-technical-activity-bodies` sembrado (solo `admin`). Copias en `../backups-ccg-ava/` (fuera del repositorio).
- **Documentación:** `README.md` (arranque), `reglas.md` (reglas y decisiones permanentes), `INFRASTRUCTURE.md` (operación y AWS), `N8N_WORKFLOW_REFERENCIA.md` (Ava → n8n), `docs/api-n8n-contratos.md` (n8n → Ava y WooCommerce), `docs/modulos-y-planes.md`, `docs/privacidad.md`, `docs/legal/` (entregables legales), `postman/` y `docs/n8n/`.

## 2. Pendientes prioritarios (resolubles sin servicios externos)

| Pendiente | Acción | Bloqueo | Estado |
|---|---|---|---|
| Revisión visual de la interfaz con sesión (claro/oscuro, 390 y 1440 px): contraste de bordes `--color-field` ≥ 3:1, Pedidos, Catálogo, Campañas, Regional, mensajes de Agenda, Configuraciones, Integraciones | Recorrido con Playwright sobre una base desechable y anotar defectos | Una persona que lo haga | Abierto |
| Flujo antiguo del widget web (webhook del Workspace, respuesta en la misma petición) no está unificado con las ejecuciones medidas | Confirmar si sigue vigente; si sí, unificarlo | Confirmación de producto | Abierto |
| `payment_events` no deduplica un evento repetido de Wompi (la confirmación del pago sí es idempotente) | Índice único `(provider, transaction_id, status)` en una migración aditiva | — | Abierto (bajo) |
| `VITE_APP_NAME` se fija en el build: la imagen de producción muestra «Laravel» en el título del navegador | `ARG VITE_APP_NAME` en el `Dockerfile` | Autorización para tocar el `Dockerfile` | Abierto |
| CI: `.github/workflows/ci.yml` (tests SQLite, Pint, `composer audit`, JS, build y un job PostgreSQL con las carreras) | Comprobar el primer run verde en GitHub | Primer push | En progreso (sin ejecutar) |
| Datos `[TEST]` y demo en `ccg_ava` | Decidir qué se conserva y limpiar con `demo:data clean` / `demo:conversations clean --confirm=<código>` | Orden expresa y copia previa | Abierto |
| Retención: `audit_logs`, `api_call_logs`, `agent_tool_calls` (ya con purga), mensajes y reservas solo crecen salvo la purga de actividad técnica | Definir plazos y programar el archivado | Decisión de plazos (sección 5) | Abierto |
| Al desplegar en cada entorno: aplicar `2026_11_15…23` (webhooks, recordatorios, reglas, entrantes, actividad, encuestas), `db:seed --class=RolesAndPermissionsSeeder` y `ModuleCatalogSeeder`, reiniciar workers y scheduler, asignar permisos a roles y Recordatorios a los planes | Con copia previa y `integrations:diagnose` | Entorno real | Abierto |

## 3. Riesgos de seguridad y estabilidad

- **Actividad técnica con datos personales.** Guarda cuerpos de solicitud/respuesta (30 días) y metadatos (180). Credenciales y datos de contacto se enmascaran, pero el redactor es heurístico: un secreto con forma corriente en un campo de nombre no sensible puede pasar. Acción: afinar la lista con el primer flujo real; plazos definitivos con jurídica.
- **n8n puede enviar sin llamar a `authorize`.** Ava no puede impedirlo; el contrato lo obliga y el historial guarda lo reportado. Acción: validarlo en el workflow real.
- **Disco `s3` con `throw => false`:** un fallo devuelve `false` sin lanzar. Acción: que cada escritura importante compruebe el resultado.
- **`TRUSTED_PROXIES=*` solo avisa** (`integrations:diagnose`). Acción: decidir si se prohíbe y comprobar la IP real tras el balanceador (el límite por IP depende de ello).
- **CSP en `report-only`.** Sin violaciones en las pantallas revisadas; faltan Clientes, Reservas, Catálogo, Pedidos, Campañas, Sedes, Servicios, la vista previa de plantillas de correo y el widget de Canales. Acción: revisarlas y pasar a `enforce`.
- **Envío por Meta sin idempotencia:** un resultado incierto queda «Sin confirmar» y lo decide una persona.
- **Ley 2300 de 2023:** antes de activar el envío real de campañas hay que verificar en el texto oficial horarios, frecuencia y baja, y validarlos en el código.
- **Dos instancias comparten `ccg_ava`** (`artisan serve` del anfitrión y Docker) con colas y discos distintos: no procesar ni borrar `jobs` atascados sin autorización (enviarían correos reales).
- **Sesión y «Recordar sesión»** (12 h absolutas) sin comprobar tras el proxy real. **Horizon:** `HORIZON_ALLOWED_EMAILS` vacío = nadie entra; nombrar quién. Alertas sobre `api.limit_exceeded` y `api.limit_store_unavailable` sin destino definido.
- **SQLite no demuestra PostgreSQL:** la concurrencia y las restricciones de exclusión solo se prueban en PostgreSQL (job de CI y base `*_testing`).
- **Membresías de `DESARROLLO_DEV` desaparecieron dos veces sin causa conocida:** si se repite, comparar con una copia previa.

## 4. Integraciones pendientes de validación

| Integración | Qué existe | Falta | Bloqueo |
|---|---|---|---|
| **n8n** | Contrato firmado Ava → n8n, token por chatbot, contexto, handoff, `resume`, actividad técnica, plantilla en `docs/n8n/` | Prueba real de punta a punta (escenario en `docs/api-n8n-contratos.md` §3); workflow registrado en `config/n8n.php` (hoy vacío); licencia de n8n para este modelo | Instancia HTTPS de pruebas, token, autorización |
| **WooCommerce** | Conexión HTTP genérica con prueba real; verificador de firma | Probar contra una tienda; decidir si se espejan pedidos | Tienda de pruebas y claves `ck_/cs_` |
| **Booker** | Merchant (disponibilidad/reserva) simulado; `booking_records` para reservas externas | Confirmar supuestos A1–A4, scope del token y un endpoint de listado o webhook (no documentados públicamente); `BOOKER_MERCHANT_VERIFIED` | Credenciales de Staging y respuesta de Booker |
| **Meta** (WhatsApp, Instagram, Messenger) | App global de CCG, webhooks firmados, refresco de tokens | Ciclo real: mensaje firmado, respuesta, estados, duplicado, fallo y traspaso; plantillas fuera de 24 h; dominio HTTPS | App de Meta, número, plantillas |
| **Wompi** | Webhook firmado, idempotencia, estados | Sandbox: pago aprobado/rechazado, reenvío del evento | Llaves de Sandbox, dominio público |
| **Correo** | Envío en línea de invitaciones, recordatorios y códigos (probado a mano por CCG) | SMTP de producción con SPF/DKIM; `mail:test` | Credenciales |
| **SIGO / renovaciones y cobros recurrentes** | No existen | Reglas comerciales y API | Decisión y documentación |
| **AWS** | Código compatible con S3 y rol IAM (verificado en el código); nada creado | `INFRASTRUCTURE.md`, sección AWS | Cuenta y recursos |

## 5. Decisiones que necesita tomar CCG

- **Nombre público:** «Ava Platform» o «Zonno Platform by CCG» (inventario de cambio en `docs/legal/datos-empresa-por-completar.md` §6). Titular de marca y de derechos de software; logotipo y favicon (`public/brand/`: `favicon.ico` 16/32/48 px, `favicon.svg`, `apple-touch-icon.png` 180×180; el sitio los usa solos al colocarlos).
- **Legal:** abogado colombiano que revise `docs/legal/` (15 documentos y matriz) y los 54 datos de `datos-empresa-por-completar.md` (razón social, NIT, domicilio, representante, canal de datos, plazos, RNBD, subencargados, jurisdicción, política de reembolsos). Luego `LEGAL_*`, `LEGAL_DOCUMENTS_REVIEWED`, `LEGAL_EFFECTIVE_DATE` y cuándo activar `LEGAL_ACCEPTANCE_REQUIRED`. Decidir si se ofrece el servicio a salud, y el procedimiento de solicitudes de titulares (¿módulo o manual?). Hash de contenido y revocación en `legal_acceptances`; página/banner de cookies solo si se añaden no necesarias.
- **Comercial:** planes, módulos y precios reales; límites (qué cuenta y si son duros), prueba gratuita, vencimiento, cambio de plan, renovación y cobro recurrente (consentimiento, calendario, prorrateo, reintentos, reembolsos), ofertas cambiadas tras aceptar, excepciones por cliente.
- **IA y conversaciones:** responder directo o delegar en n8n; qué hace la IA con una conversación `pending` que nadie toma; asignación automática; texto de `CHATBOT_FAILURE_NOTICE`; destino de `ALERT_LOG_CHANNELS`; qué acciones de riesgo puede ejecutar el chatbot sin confirmación; mensajes proactivos por plantillas de WhatsApp (gestión y costos); selector de workflow por el cliente (`select-workflow`, auditado).
- **Retención y privacidad:** plazos para mensajes, conversaciones, clientes, reservas, auditoría, trazas, verificaciones, pagos, logs y copias; si se auditan inicios de sesión y exportaciones; roles/subencargados y notificación de incidentes; acceso excepcional de soporte (`access-private-data` no está asignado a nadie).
- **Registro público:** proveedor de CAPTCHA y de pagos; si `/registro/disponibilidad` exige correo verificado; si se programa `signup:reconcile --retry`.
- **Permisos y plataforma:** roles por defecto de los permisos nuevos; si `Desarrollo_DEV` puede editar otro Workspace (hoy solo lee); permisos sueltos por persona; visibilidad del personal de un cliente (`docs/privacidad.md`).
- **Catálogo y pedidos:** cancelación por el cliente, alta automática de fichas, avisos de pedido, cobertura y tarifas de Delivery, pagos, variantes, precios publicados por el chatbot, datos clínicos de la cita; si el chatbot cierra ventas o solo deriva; origen del catálogo (carga, importación, sincronización); si Woo se espeja.
- **Meta, Dashboard y canal web:** secreto de aplicación por Workspace, mensajes que no son texto, costo de plantillas, límite del webhook (600/min por IP, estimado), si un rol sin `view-conversations` ve agregados de conversaciones, URL del webhook opcional del canal `web`.
- **Alcance futuro (no iniciado, sin requisito aprobado):** módulos propios de Salud, Delivery y eCommerce, Combos, Cupones, Domicilios y Caja; envío real de Campañas; devoluciones de pedidos; reintentar un mensaje `failed`; multimedia descargable; tiempo real con Reverb en vez de refresco; Instagram/Messenger en producción; informes de citas y pedidos adicionales y Preguntas en Reportes; exportaciones PDF/Excel; filtros guardados de Usuarios/Workspaces/Organizaciones; multidioma y configuración fiscal por jurisdicción; terminología por solución; carga de la base de conocimiento por el cliente; reserva temporal de huecos.
- **Visual:** si el borde de 3:1 es del gusto del diseño (3:1 es el mínimo normativo).

## 6. Preparación para entrega y despliegue

Ver `INFRASTRUCTURE.md` (operación, copia y restauración, supervisión, despliegue y marcha atrás, AWS). Lista corta del primer entorno real, todo **pendiente**:

1. Dominio con HTTPS para `APP_URL` y `TRUSTED_PROXIES`; `https://<dominio>/api/webhooks/whatsapp` responde la verificación de Meta.
2. Variables del servidor (`META_*`, `N8N_*`, `ADMIN_*`, `HORIZON_ALLOWED_EMAILS`, `ALERT_LOG_CHANNELS`, `CHATBOT_FAILURE_NOTICE`, `MAIL_*`, `LEGAL_*`, `BRAND_*`); `docker compose -f docker-compose.prod.yml config` sin error e `integrations:diagnose` con 0 bloqueos.
3. Migraciones y semillas en orden, primer administrador y rol `plataforma`, revisión de roles tras `RolesAndPermissionsSeeder`.
4. Un worker (Horizon) y un scheduler (una réplica); responsable que revise a diario `alerts.log`, `integrations:diagnose`, `executions:list --failed` y la bandeja «Pendientes».
5. Copia programada y restauración probada en el servidor real; bucket privado y sin CDN público.
6. Primera prueba real de punta a punta (sección 4) y auditoría de seguridad externa antes de abrir a clientes reales.
7. Documentos legales aprobados y datos corporativos cargados; favicon colocado; nombre público decidido.
