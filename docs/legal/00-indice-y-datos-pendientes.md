# Documentos legales de Ava Platform — índice, estado y datos pendientes

**Versión del paquete:** 0.2 (borrador) · **Fecha de elaboración:** 2026-11-04 · **Estado: BORRADOR PARA REVISIÓN Y APROBACIÓN DE ABOGADO COLOMBIANO. No es asesoría legal ni está aprobado. No debe publicarse ni presentarse como aprobado hasta esa revisión.**

Nombre del servicio: estos documentos usan **«Ava Platform»**. La interfaz actual muestra el nombre configurado en `BRAND_NAME` (hoy «Zonno Platform by CCG», `config/branding.php`). Antes de publicar hay que decidir un solo nombre comercial y reemplazarlo en todos los documentos y en los textos de `resources/legal/`.

## 1. Documentos

| N.º | Documento | Archivo | Se publica en la plataforma |
|---|---|---|---|
| 01 | Términos y condiciones de uso | `01-terminos-y-condiciones.md` | Sí (existe borrador v0.1 en `resources/legal/terms.php`) |
| 02 | Política de tratamiento y protección de datos personales | `02-politica-tratamiento-datos.md` | Sí (borrador v0.1 en `data_policy.php`) |
| 03 | Aviso de privacidad | `03-aviso-de-privacidad.md` | Sí (borrador v0.1 en `privacy_notice.php`) |
| 04 | Política de cookies | `04-politica-de-cookies.md` | Sí (nuevo: `resources/legal/cookies.php`) |
| 05 | Política de tratamiento de datos para clientes empresariales y Workspaces | `05-politica-clientes-empresariales.md` | Anexo contractual |
| 06 | Acuerdo de tratamiento de datos (encargo / transmisión) | `06-acuerdo-tratamiento-datos.md` | Anexo contractual (se firma o acepta con el contrato) |
| 07 | Autorización para el tratamiento de datos personales | `07-autorizacion-tratamiento-datos.md` | Texto para formularios |
| 08 | Comunicaciones comerciales y consentimiento | `08-comunicaciones-comerciales.md` | Texto para formularios |
| 09 | Condiciones de contratación, pagos, planes, renovaciones, cancelación y suspensión | `09-condiciones-contratacion.md` | Sí (contratación pública) |
| 10 | Condiciones de uso de funcionalidades de inteligencia artificial | `10-condiciones-uso-ia.md` | Sí (nuevo: `resources/legal/ai_use.php`) |
| 11 | Procedimiento de consultas, reclamos, actualización, rectificación y supresión | `11-procedimiento-consultas-reclamos.md` | Sí |
| 12 | Información de contacto y canales para ejercer derechos | `12-contacto-y-canales.md` | Sí |
| 13 | Anexos sectoriales (salud, comercio electrónico, delivery, reservas) | `13-anexos-sectoriales.md` | Anexo contractual |
| 14 | Matriz normativa y de correspondencia | `14-matriz-normativa.md` | Interno |

Los PDF están en `docs/legal/pdf/` con el mismo nombre. El Markdown es la fuente editable: **si se cambia un texto, se vuelve a generar el PDF** con `python docs/legal/build_pdfs.py` (requiere `uv`, que descarga `markdown` y `pymupdf` de forma temporal).

## 2. Datos corporativos que faltan (no se inventó ninguno)

**El formulario completo para que CCG los llene está en `datos-empresa-por-completar.md` (54 campos, 0 completados), que es la fuente única; la tabla de abajo es un resumen.**

Cada uno aparece en los documentos como `[POR CONFIRMAR: …]` y debe cargarse también en el entorno (`LEGAL_*`, ver `config/legal.php`):

| Dato | Variable / lugar | Usado en |
|---|---|---|
| Razón social completa de la empresa responsable | `LEGAL_COMPANY_NAME` | Todos |
| NIT | `LEGAL_COMPANY_TAX_ID` | Todos |
| Domicilio y dirección para notificaciones | `LEGAL_COMPANY_ADDRESS` | 01, 02, 03, 06, 09 |
| Representante legal | contrato | 06, 09 |
| Correo y teléfono del canal de atención de titulares de datos | `LEGAL_PRIVACY_CONTACT` | 02, 03, 11, 12 |
| Correo de contacto general y de soporte | `LEGAL_CONTACT_EMAIL` | 01, 09, 12 |
| Nombre del área o persona responsable de atender consultas y reclamos | — | 02, 11 |
| Jurisdicción y ciudad para controversias | `LEGAL_JURISDICTION` | 01, 09 |
| Si la empresa está obligada a inscribir bases de datos en el RNBD (activos totales y verificación del Decreto 090 de 2018) y, si lo está, número de inscripción | — | 02 |
| Lista definitiva de proveedores y subencargados (hosting/AWS, correo, Meta, n8n, modelos de IA, pagos Wompi) con país de tratamiento | — | 02, 06 |
| Plazos de conservación aprobados (cuentas, auditoría, conversaciones, actividad técnica, copias) | — | 02, 05, 06 |
| Fecha de vigencia de cada versión | `LEGAL_EFFECTIVE_DATE` | Todos |
| Política de precios, ciclos de cobro, cancelación y reembolso (decisiones comerciales abiertas D-PL*) | `pendientes.md` §3–4 | 09 |
| Canal oficial para PQR de consumidores y datos de la autoridad competente | — | 09, 12 |
| Si CCG ofrecerá el servicio a entidades de salud y bajo qué condiciones | — | 13 |

## 3. Cómo usar este paquete

1. Un abogado colombiano especializado en protección de datos y consumo revisa los 14 documentos y la matriz (`14-matriz-normativa.md`, columna «Pendiente de validación jurídica»).
2. CCG completa los datos de la sección 2.
3. Se sincronizan los textos de `resources/legal/*.php` con los documentos aprobados y se sube `version` en `config/legal.php` (todos los usuarios deberán aceptar la nueva versión; las aceptaciones anteriores se conservan como evidencia).
4. Solo entonces se pone `LEGAL_DOCUMENTS_REVIEWED=true`, se fija `LEGAL_EFFECTIVE_DATE` y se decide cuándo activar `LEGAL_ACCEPTANCE_REQUIRED=true`.

## 4. Principios que siguen los textos

- Ava (CCG) es **Responsable** de los datos de las cuentas de quienes usan la plataforma y **Encargado** de los datos de los clientes finales de cada Workspace, cuyo Responsable es la organización cliente. Los documentos mantienen esa distinción.
- Aceptar los términos de servicio, autorizar el tratamiento de datos personales y aceptar comunicaciones comerciales son **tres decisiones distintas**, nunca casillas marcadas por defecto ni condiciones unas de otras.
- No se renuncia a ningún derecho irrenunciable ni se excluye responsabilidad que la ley no permita excluir.
- Lo que Ava no hace hoy no se promete (por ejemplo, envío real de campañas, facturación electrónica o cobros recurrentes).
