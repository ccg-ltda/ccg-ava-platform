# Documentos legales de Ava Platform — índice

**Versión 0.2 (borrador) · 2026-11-04 · BORRADOR PARA REVISIÓN Y APROBACIÓN DE ABOGADO COLOMBIANO. No es asesoría legal ni está aprobado; no publicar ni presentar como aprobado hasta esa revisión.**

Nombre: estos documentos dicen «Ava Platform»; la interfaz muestra `BRAND_NAME` (hoy «Zonno Platform by CCG»). Hay que decidir un solo nombre (ver `datos-empresa-por-completar.md`, sección 6).

## Documentos (Markdown editable + PDF en `pdf/`)

| N.º | Documento | Se publica en la plataforma |
|---|---|---|
| 01 | Términos y condiciones de uso | Sí (`resources/legal/terms.php`, borrador v0.1) |
| 02 | Política de tratamiento y protección de datos personales | Sí (`data_policy.php`) |
| 03 | Aviso de privacidad | Sí (`privacy_notice.php`) |
| 04 | Política de cookies | Sí (`cookies.php`) |
| 05 | Política de datos para clientes empresariales y Workspaces | Anexo contractual |
| 06 | Acuerdo de tratamiento de datos (encargo / transmisión) | Anexo contractual |
| 07 | Autorización para el tratamiento de datos | Texto para formularios |
| 08 | Comunicaciones comerciales y consentimiento | Texto para formularios |
| 09 | Condiciones de contratación, pagos, planes, renovación, cancelación y suspensión | Sí |
| 10 | Condiciones de uso de la inteligencia artificial | Sí (`ai_use.php`) |
| 11 | Procedimiento de consultas, reclamos y supresión | Sí |
| 12 | Contacto y canales para ejercer derechos | Sí |
| 13 | Anexos sectoriales (salud, comercio electrónico, delivery, reservas) | Anexo contractual |
| 14 | Matriz normativa y de correspondencia | Interno |
| 15 | Guía informativa de protección de marca | Interno |
| — | `datos-empresa-por-completar.md` | **Único lugar** de los datos corporativos pendientes (54 campos, 0 completados) |

Regenerar los PDF tras editar un Markdown: `uv run --with markdown --with pymupdf python docs/legal/build_pdfs.py` (los PDF se generan siempre desde el Markdown vigente).

## Cómo se cierra

1. Un abogado colombiano revisa los 15 documentos y la matriz (columna «Pendiente de validación jurídica»).
2. CCG completa `datos-empresa-por-completar.md` y carga las variables `LEGAL_*`.
3. Se sincronizan `resources/legal/*.php` con los textos aprobados y se sube `version` en `config/legal.php` (todos deberán aceptar la nueva versión; las aceptaciones previas se conservan como evidencia).
4. Solo entonces: `LEGAL_DOCUMENTS_REVIEWED=true`, `LEGAL_EFFECTIVE_DATE` y decidir cuándo activar `LEGAL_ACCEPTANCE_REQUIRED=true`.

## Principios de los textos

- CCG es **Responsable** de los datos de las cuentas y **Encargado** de los de los clientes finales de cada Workspace (su Responsable es la organización cliente).
- Aceptar términos, autorizar el tratamiento de datos y aceptar comunicaciones comerciales son **tres decisiones distintas**, sin casillas premarcadas ni condicionadas entre sí.
- No se renuncia a derechos irrenunciables ni se excluye responsabilidad que la ley no permita excluir. Lo que Ava no hace hoy no se promete (envío real de campañas, facturación electrónica, cobros recurrentes).
