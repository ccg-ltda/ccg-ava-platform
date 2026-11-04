# Cláusulas de comunicaciones comerciales y consentimiento

**Versión 0.2 (borrador) · BORRADOR PARA REVISIÓN DE ABOGADO COLOMBIANO. No aprobado.**

## 1. Principios
- Enviar publicidad o información comercial es una **finalidad distinta y opcional**: requiere autorización específica del titular (Ley 1581 de 2012, arts. 4 y 9) y no puede ser condición para usar el Servicio.
- La casilla nunca viene premarcada. Se puede **retirar** en cualquier momento por el mismo medio fácil con que se dio.
- Los mensajes respetan los **canales autorizados, los horarios y la frecuencia** de la Ley 2300 de 2023 (protección del derecho a la intimidad del consumidor frente a cobranza y publicidad). Al redactar este borrador no se pudo verificar en el texto oficial el detalle de horarios y frecuencias; el abogado debe confirmarlos antes de enviar campañas reales (ver matriz, documento 14).
- Los mensajes de WhatsApp fuera de la ventana de 24 horas exigen plantillas aprobadas por Meta y el cumplimiento de sus políticas de mensajería comercial.

## 2. Texto para los usuarios de la plataforma (CCG)
> ☐ Autorizo a **[CCG]** a enviarme por correo electrónico información comercial y novedades sobre sus productos y servicios. Puedo retirar esta autorización cuando quiera desde mi perfil o con el enlace de cada mensaje. No es necesaria para usar la plataforma.

La plataforma ya separa esta decisión (`communication_consents`, pantalla Perfil) de la aceptación de términos.

## 3. Texto modelo para los clientes finales de un Cliente
> ☐ Autorizo a **[Cliente]** a contactarme por [WhatsApp / correo / llamada] para ofrecerme productos, promociones o información comercial. Puedo retirar mi autorización respondiendo «BAJA» o escribiendo a [correo].

## 4. Reglas que el Cliente debe cumplir
1. Solo contactar por el canal autorizado por el titular.
2. Respetar horarios y frecuencia de la ley; no contactar personas que retiraron su autorización.
3. Identificarse y ofrecer una forma sencilla de darse de baja en cada comunicación.
4. Conservar la prueba de la autorización (cuándo, cómo y qué texto).
5. Distinguir mensajes **transaccionales o de servicio** (confirmación de una cita ya pedida, recordatorio de una reserva) de los **comerciales**: los primeros responden a la relación en curso; los segundos requieren autorización.

## 5. Estado técnico
El módulo Campañas filtra por consentimiento `granted` y excluye al resto con su motivo, pero **no envía** mensajes reales (`UnavailableCampaignDispatcher`). Antes de implementar el envío real hay que agregar: validación de horario y frecuencia (Ley 2300), enlace o respuesta de baja, y registro de cada contacto.
