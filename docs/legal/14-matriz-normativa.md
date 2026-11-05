# Matriz normativa y de correspondencia (Colombia)

**Versión 0.2 (borrador) · Fecha de elaboración y consulta: 2026-11-04 · NO es asesoría legal. Debe validarla un abogado colombiano.**

## Honestidad sobre las fuentes

| Nivel | Qué significa |
|---|---|
| **Consultada** | Se abrió o se encontró en esta sesión una fuente oficial o una copia fiel del texto |
| **Conocimiento previo, a verificar** | Se redactó con base en conocimiento general de la norma; **no se pudo abrir el texto oficial en esta sesión**. El abogado debe contrastarlo con la fuente indicada |

Solo se **consultó** (búsqueda web, 2026-11-04): Ley 2300 de 2023 (normograma del MinTIC y Rama Judicial; Sentencia C-278 de 2024 de la Corte Constitucional, exequible), las tasas de la SIC para 2026 (sic.gov.co, Resolución 6167 del 30 de enero de 2026) y referencias secundarias sobre el RNBD, la responsabilidad demostrada y la firma de webhooks. Todo lo demás está marcado «a verificar». Fuentes oficiales para la verificación: SUIN-Juriscol (suin-juriscol.gov.co), Secretaría del Senado (secretariasenado.gov.co), Función Pública (funcionpublica.gov.co/eva/gestornormativo), Diario Oficial (imprenta.gov.co) y SIC (sic.gov.co).

## 1. Análisis norma por norma

| Norma | Artículos relevantes | Obligación | ¿Aplica a Ava? | Consecuencia en documentos / interfaz / sistema | Fuente · estado | Modificaciones / jurisprudencia a revisar |
|---|---|---|---|---|---|---|
| **Constitución, art. 15** (habeas data) | 15 | Derecho a conocer, actualizar y rectificar datos | Sí | Fundamento de derechos del titular (02, 11) | Constitución · a verificar | Sentencias C-748 de 2011 (control de la Ley 1581), T-729 de 2002 |
| **Ley 1581 de 2012** | 3, 4, 5–7, 8, 9, 10, 12, 13, 14–16, 17, 18, 21, 25, 26 | Principios, autorización, derechos, deberes del Responsable y del Encargado, consultas y reclamos, RNBD, transferencias | **Sí, plenamente.** CCG es Responsable de datos de cuentas y Encargado de los de cada Workspace | Documentos 02, 03, 05, 06, 07, 11; registro de aceptaciones; roles y aislamiento por Workspace; enmascaramiento en actividad técnica | Secretaría del Senado · a verificar | C-748/2011; reglamentada por Decretos 1377/2013 y 886/2014 (compilados) |
| **Decreto 1074 de 2015** (compila Decreto 1377 de 2013, Decreto 886 de 2014 y Decreto 090 de 2018 en lo de datos) | 2.2.2.25.2.x (autorización y prueba), 2.2.2.25.3.1 (política), 2.2.2.25.3.3 (aviso de privacidad), 2.2.2.25.5.2 (transmisión), 2.2.2.25.6.1 (responsabilidad demostrada); Cap. 26 RNBD | Política y aviso, prueba de autorización, contrato de transmisión, programa de responsabilidad demostrada, RNBD para quienes cumplan el criterio de activos | **Sí.** El RNBD aplica a CCG solo si supera el umbral de activos vigente (Decreto 090 de 2018: 100.000 UVT) — **verificar** | 02, 03, 06; registro de prueba de aceptación; RNBD **[POR CONFIRMAR]** | Función Pública / SUIN · a verificar | Decreto 090/2018 (umbral RNBD); Circular Única SIC Título V |
| **Circular Única de la SIC, Título V** | Protección de datos personales: RNBD, transferencias internacionales (lista de países), incidentes | Instrucciones a Responsables y Encargados | Sí | 02 (incidentes, transferencias), 06 Anexo A | sic.gov.co · a verificar | Verificar la lista vigente de países adecuados y el procedimiento de reporte de incidentes |
| **Ley 1266 de 2008** (habeas data financiero) | 1, 3, 5 y siguientes | Regula información financiera, crediticia, comercial y de servicios | **Solo si** un Cliente usa la plataforma para tratar o reportar información de riesgo crediticio o cobranza reportada a centrales | No se aplica a las cuentas de la plataforma. Se advierte en el documento 05; los Clientes de cobranza deben revisar esta ley y la Ley 2300 | Secretaría del Senado · a verificar | Ley 2157 de 2021 (reforma) |
| **Ley 1480 de 2011** (Estatuto del Consumidor) | 5, 23, 37, 42–43, 46, 47, 50, 51, 7–8 | Información, publicidad, garantías, cláusulas abusivas, ventas a distancia, retracto, reversión | **Sí, cuando el contratante es consumidor** (persona que adquiere para necesidad propia no ligada a su actividad). Para empresas no, salvo pacto. **Para los Clientes que venden a sus consumidores, aplica a ellos** | 01 (§11), 09 (§1, 2, 7, 11), 13 (Anexo B) | Secretaría del Senado · a verificar | Decreto 587 de 2016 (reversión); Circular Única SIC Título III (comercio electrónico) — verificar |
| **Ley 527 de 1999** | 2, 5, 6, 7, 8, 10, 11, 12, 13 | Reconoce el mensaje de datos, su valor probatorio y la firma electrónica | **Sí**: sustenta la validez de la aceptación electrónica y de su registro | `legal_acceptances` (documento, versión, fecha, IP); 01 §13, 09 §3 | Secretaría del Senado · a verificar | Decreto 2364 de 2012 (firma electrónica), Ley 2213 de 2022 — verificar |
| **Ley 2300 de 2023** («Dejen de fregar») | Arts. sobre canales, horarios y periodicidad de contacto de cobranza y de publicidad; derecho a no ser contactado | Restringe cuándo, cómo y por qué canal se contacta a un consumidor para cobrar u ofrecer productos; exige autorización expresa para contactar fuera de horario | **Aplica a los Clientes que envían campañas o cobran** y a CCG si hace mercadeo. No aplica al mensaje de servicio que el titular espera | 08; el módulo Campañas **debe** validar horario, frecuencia y baja antes de activar el envío real | **Consultada** (normograma MinTIC; Rama Judicial). Detalle de horarios y frecuencias **no verificado en el texto**: contrastar el art. 3 | **Sentencia C-278 de 2024: exequible.** Rige desde el 10 de octubre de 2023 (Diario Oficial 52.452) |
| **Cookies / SIC** | Ley 1581 art. 4(c), 9; guías y decisiones de la SIC | El uso de cookies que identifiquen personas es tratamiento de datos: informar y, para las no necesarias, obtener autorización | **Sí**, pero hoy solo hay cookies necesarias (04) | 04; no se muestra banner mientras solo existan necesarias; mecanismo previo obligatorio si se agregan otras | Secundarias solamente · **no se encontró norma o circular específica oficial**; el abogado debe confirmar el criterio vigente de la SIC | No citar sanciones no verificadas |
| **Ley 1273 de 2009** (delitos informáticos) | Título VII BIS del Código Penal | Protección penal de sistemas y datos | Contexto de seguridad; sin obligación de documento | 02 §8 (seguridad) | a verificar | — |
| **Normas sectoriales de salud**: Ley 23 de 1981, Res. 1995 de 1999, Ley 1751 de 2015, Ley 2015 de 2020 | Reserva de la historia clínica, historia clínica electrónica | Solo para prestadores de salud | **Solo Clientes del sector salud**; Ava no es prestador | 13 (Anexo A) | a verificar | Verificar reglamentación vigente |
| **Comercio electrónico / tributario** (factura electrónica, Estatuto Tributario) | — | Facturación del Cliente y de CCG | Sí para CCG en su facturación; SIGO no está integrado | 09 §5 | a verificar | Resolución vigente de la DIAN |
| **IA** | No hay ley específica vigente identificada | Aplican Ley 1581, consumo y lineamientos de la SIC | Sí | 10 | a verificar (existen lineamientos de la SIC sobre IA; número y vigencia sin confirmar) | Seguir proyectos de ley en trámite |

## 2. Distinción de obligaciones

| Obligación | CCG (SaaS) | Workspace (cliente) |
|---|---|---|
| Autorización de datos de las cuentas de usuarios | Sí (Responsable) | — |
| Autorización de datos de clientes finales | — | Sí (Responsable) |
| Seguridad de la plataforma, aislamiento, auditoría | Sí (Encargado) | Configurar roles y accesos |
| Atender derechos de clientes finales | Apoyar y trasladar | Sí |
| Informar el uso de IA al cliente final | Proveer la herramienta | Sí |
| Comunicaciones comerciales | No enviarlas sin autorización de sus propios usuarios | Sí (autorización, horarios, baja) |
| Retracto, garantías, facturación a sus consumidores | — | Sí |
| Salud, financiero y demás normas sectoriales | No es prestador | Sí |
| RNBD | Si supera el umbral | Si supera el umbral |

## 3. Correspondencia: norma → documento → funcionalidad → evidencia

| Norma / obligación | Documento | Funcionalidad de la plataforma | Evidencia (prueba o archivo) | Pendiente de validación jurídica |
|---|---|---|---|---|
| Ley 1581: política y aviso | 02, 03 | Páginas `/legal/*`, enlaces en pantallas de acceso (`LegalLinks`) | `tests/Feature/LegalTest.php` | Sí: contenido y datos corporativos |
| Aceptación versionada y prueba | 01, 07 | `legal_acceptances`, página `Legal/Accept`, versión en `config/legal.php` | `LegalTest` | Sí |
| Autorización separada de términos y de comunicaciones | 07, 08 | Casillas independientes sin premarcar; `communication_consents` | `LegalTest`, `PublicSignupTest` | Sí |
| Derechos del titular (consultas, reclamos, supresión) | 11, 12 | Anonimización de ficha de cliente; auditoría; sin módulo de PQR | `CustomerRegistry::anonymize` (pruebas del módulo Clientes) | Sí: procedimiento manual vs. módulo |
| Transmisión a proveedores | 06 | Integraciones por Workspace; aislamiento por tenant | `TenantIsolation*`, `Integrations*` | Sí: lista de subencargados |
| Seguridad y mínimo de datos en registros | 02, 05 | Redactor de credenciales, enmascaramiento, retención 30/180 días, permiso específico, interruptor por Workspace | `ActivityPrivacyPolicyTest` | Sí: plazos |
| Cookies | 04 | Solo cookies necesarias; sin analítica | Inventario en 04; `config/session.php` | Sí: criterio SIC |
| Comunicaciones comerciales (Ley 2300) | 08 | Consentimiento por cliente; el envío real de campañas no existe | `Campaigns*` | **Sí, crítico antes de activar el envío** |
| Ventas a distancia, retracto, reversión | 09, 13-B | Contratación pública con confirmación de pago verificada | `PublicSignupTest`, `Wompi*` | Sí |
| Mensajes de datos / firma electrónica | 01, 09 | Registro de aceptación con IP, fecha y versión | `LegalTest` | Sí |
| IA | 10 | Handoff a humano, control de concurrencia IA/humano, registro de actividad | `HumanHandlingTest`, `N8nContractFlowTest` | Sí |
| Salud | 13-A | Sin módulo clínico; datos sensibles solo por decisión del Cliente | — | **Sí: decidir si se ofrece al sector** |

## 4. Clasificación de lo anterior (actualizada 2026-11-04, segunda ronda)

| Categoría | Qué incluye |
|---|---|
| **Verificado en fuente oficial o copia fiel (consulta de esta fecha)** | Ley 2300 de 2023: existencia, vigencia desde el 10 de octubre de 2023, objeto (intimidad del consumidor, canales, horarios y periodicidad de contacto de cobranza y publicidad) y exequibilidad por la Sentencia C-278 de 2024 (normograma del MinTIC, Rama Judicial, copias de la sentencia). Tasas de marca de la SIC para 2026 (Resolución 6167 de 2026). **No verificado aún en el texto:** horarios exactos y frecuencia de la Ley 2300 |
| **Pendiente de comprobación en fuente oficial** | Ley 1581 de 2012 y los números de artículo citados; Decreto 1074 de 2015 (numerales 2.2.2.25.x y Cap. 26) y Decreto 090 de 2018 (umbral RNBD); Circular Única de la SIC Título V (incidentes, transferencias, lista de países); Ley 1266 de 2008; Ley 1480 de 2011 (arts. 23, 42, 43, 46, 47, 50, 51) y su reglamentación; Ley 527 de 1999; normas sectoriales de salud; criterio de la SIC sobre cookies; lineamientos de la SIC sobre IA |
| **Interpretaciones que requieren abogado** | Si CCG es Responsable o Encargado en cada flujo; aplicabilidad de retracto y reversión a un SaaS de activación inmediata; si basta con no mostrar un banner mientras solo haya cookies necesarias; límites de responsabilidad admisibles; cláusula de jurisdicción; tratamiento de datos de salud; transferencias internacionales con proveedores de nube y de IA; obligación de RNBD |
| **Obligaciones técnicas que la plataforma ya cumple o puede cumplir** | *Ya implementado:* registro versionado de aceptaciones (documento, versión, fecha, IP); casillas sin premarcar y separadas (términos, autorización de datos, comunicaciones comerciales); consentimiento de comunicaciones por cliente y por canal; anonimización de fichas de clientes; aislamiento por Workspace; auditoría; credenciales cifradas; enmascaramiento y retención en la actividad técnica; acceso de soporte temporal y auditado. *Por implementar si se decide:* módulo de PQR de titulares; validación de horario, frecuencia y baja antes del envío real de campañas (Ley 2300); exportación de los datos de un titular; retención automática de conversaciones por Workspace; banner de cookies **solo si** se añaden cookies no necesarias; hash del contenido de cada versión aceptada (P16 de `RECOMENDACIONES.md`) |
