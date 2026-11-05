# Datos de la empresa por completar (fuente única para los documentos legales)

**Cómo usarlo:** CCG completa la columna «Valor» (y «Fuente/Responsable» y «Fecha»). Nada se inventó: todo está vacío a propósito. Cuando un dato quede completo, se carga en el entorno (variable indicada) y se sustituye `[POR CONFIRMAR: …]` en los documentos de `docs/legal/` (el campo «Dónde se usa» dice cuáles). **No publicar ningún documento mientras queden filas críticas (⚠) vacías.**
Estado de este archivo: 0 de 54 campos completados. Fecha de creación: 2026-11-04.

## 1. Identidad legal

| # | Campo | Valor | Variable / lugar | Dónde se usa | Fuente / responsable | Fecha |
|---|---|---|---|---|---|---|
| 1 ⚠ | Razón social completa | | `LEGAL_COMPANY_NAME` | Todos | | |
| 2 | Nombre comercial del producto (ver sección 5) | | `BRAND_NAME` | Todos | | |
| 3 ⚠ | NIT | | `LEGAL_COMPANY_TAX_ID` | Todos | | |
| 4 ⚠ | Domicilio principal | | `LEGAL_COMPANY_ADDRESS` | 01, 02, 03, 06, 09 | | |
| 5 | Ciudad y departamento | | — | 01, 06, 09 | | |
| 6 | País | | — | 06 | | |
| 7 ⚠ | Representante legal (nombre y documento) | | contrato | 06, 09 | | |
| 8 | Cargo | | — | 06 | | |
| 9 ⚠ | Correo de contacto general | | `LEGAL_CONTACT_EMAIL` | 01, 09, 12 | | |
| 10 | Teléfono | | — | 12 | | |
| 11 | Sitio web | | `APP_URL` | 03, 12 | | |

## 2. Protección de datos personales

| # | Campo | Valor | Variable / lugar | Dónde se usa | Responsable | Fecha |
|---|---|---|---|---|---|---|
| 12 ⚠ | Responsable del tratamiento (¿la misma sociedad? ¿otra?) | | — | 02, 03 | | |
| 13 ⚠ | Correo para consultas y reclamos de datos personales | | `LEGAL_PRIVACY_CONTACT` | 02, 03, 11, 12 | | |
| 14 | Dirección para notificaciones | | `LEGAL_COMPANY_ADDRESS` | 02, 11, 12 | | |
| 15 ⚠ | Persona o área encargada de protección de datos | | — | 02, 11 | | |
| 16 | Procedimiento interno de consultas y reclamos (quién recibe, quién responde, cómo se registra, plazos internos) | | — | 11 | | |
| 17 ⚠ | Plazos de conservación aprobados: cuentas desactivadas | | — | 02 | | |
| 18 ⚠ | … auditoría y aceptaciones legales | | — | 02 | | |
| 19 ⚠ | … conversaciones y mensajes (valor por defecto para Workspaces) | | — | 02, 05 | | |
| 20 | … copias de seguridad | | — | 02, 06 | | |
| 21 | … actividad técnica (propuesto 30 días contenido / 180 metadatos; confirmar) | | `chatbots.tool_trace_*` | 02, 05 | | |
| 22 ⚠ | **RNBD:** ¿CCG está obligada a inscribirse? Datos para el análisis: total de activos al último cierre (en UVT del año), si trata bases de datos como responsable, resultado y, si aplica, número y fecha de inscripción | | — | 02 | Contador + abogado | |
| 23 | Bases de datos personales existentes (nombre, finalidad, titulares, categorías, encargados) | | — | 02 | | |
| 24 | Categorías de datos tratados | (propuesta: ver doc. 02 §4) | — | 02 | | |
| 25 ⚠ | ¿Se tratarán datos sensibles (p. ej. salud) a través de la plataforma? ¿en qué escenarios? | | — | 02, 06, 13 | | |
| 26 ⚠ | Lista de subencargados y proveedores (nombre, función, datos que ve, país) — nube/AWS, correo, Meta, n8n, modelos de IA, Wompi, otros | | — | 02, 06 Anexo A | | |
| 27 | Países donde se almacenan o procesan datos | | — | 02, 06 | | |
| 28 | Política de incidentes: quién decide, plazo de aviso al cliente (propuesto ≤ 72 h) y a la SIC | | — | 02, 06 | | |
| 29 | Plazo para devolver o eliminar los datos al terminar el contrato | | — | 01, 05, 06 | | |

**Análisis del RNBD (para el abogado):** el registro es obligatorio para sociedades y entidades sin ánimo de lucro con activos totales superiores a 100.000 UVT y para entidades públicas (Decreto 1074 de 2015, Cap. 26, y Decreto 090 de 2018, a verificar), sin perjuicio de la obligación de cumplir la Ley 1581 en cualquier caso. Falta el dato de activos de CCG para decidir. Si CCG no está obligada, igual debe poder demostrar el cumplimiento (responsabilidad demostrada).

## 3. Condiciones comerciales

| # | Campo | Valor | Dónde se usa | Responsable | Fecha |
|---|---|---|---|---|---|
| 30 ⚠ | Razón social que factura | | 09 | | |
| 31 | Métodos de pago aceptados (hoy: Wompi) | | 09 | | |
| 32 | Condiciones de facturación (facturación electrónica, SIGO, periodicidad) | | 09 | | |
| 33 ⚠ | Política de cancelación (inmediata/fin de periodo) | | 09 | | |
| 34 ⚠ | Política de renovación (¿se implementa cobro recurrente?) | | 09 | | |
| 35 ⚠ | Política de suspensión (plazo de gracia, conservación de datos) | | 09 | | |
| 36 ⚠ | Reembolsos y retracto (cómo aplica a un SaaS de activación inmediata) | | 09 | Abogado | |
| 37 | Canal de soporte y horarios | | 01, 09, 12 | | |
| 38 | Planes, precios, impuestos y límites | | 09 | Comercial | |
| 39 | Nivel de servicio (disponibilidad) si se ofrece | | 01, 09 | | |
| 40 ⚠ | Jurisdicción, ciudad y mecanismo de solución de controversias | | 01, 06, 09 | Abogado | |
| 41 | Criterio de limitación de responsabilidad (admisible) | | 01 | Abogado | |
| 42 | Canal y datos para PQR de consumidores | | 09, 12 | | |
| 43 | ¿Se ofrecerá el servicio a entidades de salud? ¿con qué condiciones? | | 13 | Dirección + abogado | |

## 4. Aprobación y publicación

| # | Campo | Valor |
|---|---|---|
| 44 ⚠ | Fecha de vigencia de cada versión (`LEGAL_EFFECTIVE_DATE`) | |
| 45 ⚠ | Abogado que revisa y aprueba (nombre, tarjeta profesional) | |
| 46 | Fecha de aprobación | |
| 47 | ¿Cuándo se activa `LEGAL_ACCEPTANCE_REQUIRED=true`? | |
| 48 | URL pública definitiva de los documentos | |

## 5. Marca

| # | Campo | Valor |
|---|---|---|
| 49 ⚠ | **Nombre público del producto: ¿«Ava Platform» o «Zonno Platform by CCG»?** | |
| 50 | Nombre de la sociedad titular de la marca | |
| 51 | Titular previsto de los derechos de software | |
| 52 | Logotipo y elementos visuales (archivos, licencia, autor) | |
| 53 | Favicon (`public/brand/favicon.ico`, `favicon.svg`, `apple-touch-icon.png`) | |
| 54 | Resultado de la búsqueda de antecedentes marcarios | |

## 6. Recomendación técnica para el cambio de nombre (una vez confirmado el definitivo)

**No se cambió nada.** Hoy el nombre aparece así (inventario hecho con búsqueda dirigida el 2026-11-04):

| Dónde | Qué dice | Cómo cambiarlo |
|---|---|---|
| `config/branding.php` (`BRAND_NAME`, `BRAND_SHORT_NAME`) | «Zonno Platform by CCG» / «Zonno by CCG» | Es el **punto único** de la interfaz: cambiar la variable de entorno (sin tocar código). Lo usan la pestaña, la barra, los correos y `useBrand.js` |
| `.env.example`, `docker-compose.yml`, `docker-compose.prod.yml` | Valores de ejemplo de `BRAND_*` y de `CCG_SUPPORT_*` | Actualizar el ejemplo y los valores por defecto |
| `resources/legal/*.php` (términos, aviso, política de datos, cookies, IA) | «Zonno Platform by CCG» escrito en el texto | Sustituir por el nombre definitivo (idealmente con un marcador `{{brand}}` leído de `config('branding.name')`) |
| `docs/legal/*.md` y PDF | «Ava Platform» | Reemplazar y **regenerar los PDF** (`docs/legal/build_pdfs.py`) |
| `resources/js/Components/HelpPanel.jsx`, `SupportDesk.jsx`, `Pages/Chatbots/Partials/*`, `Pages/Conversations/Partials/ChatPanel.jsx`, `Pages/Integrations/Partials/ChannelCard.jsx` | Textos con el nombre | Usar `useBrand()` en lugar de texto fijo, o reemplazar |
| `app/Services/HelpCenter.php`, `KnowledgeBase.php`, `SupportContext.php`, `ChatbotInstructions.php`, `ChatbotExecutions.php`, `WhatsAppType.php`, `WhatsAppConnection.php`, `WebChannelConnection.php`, `ChatbotInstructionController.php` | Nombre en textos de ayuda, instrucciones por defecto y mensajes | Reemplazar o leer de `config('branding')` |
| `config/modules.php`, `config/cors.php`, `config/services.php`, `config/support.php` | Comentarios y valores por defecto | Revisar |
| `public/widget/ava-widget.js` | Nombre interno `AvaWidget` | Es un identificador técnico: **no cambiar** sin una migración para los sitios que ya lo cargan |
| `N8N_WORKFLOW_REFERENCIA.md`, `RECOMENDACIONES.md`, `docs/legal/15-marca-guia-informativa.md` | Documentación | Actualizar al final |
| Nombres internos (`ava_` en los tokens, rutas `/api/agent`, tablas, repositorio, `DESARROLLO_DEV`) | «ava» | **No cambiar**: son identificadores técnicos |

Pasos: (1) confirmar el nombre; (2) cambiar `BRAND_NAME`/`BRAND_SHORT_NAME`; (3) sustituir en `resources/legal` y `docs/legal`; (4) regenerar PDF; (5) `grep -ri "<nombre viejo>"` para verificar; (6) correr la suite (`LegalTest` y las pruebas de interfaz que comparan textos).
