# Anexos sectoriales: salud, comercio electrónico, delivery y servicios con reservas

**Versión 0.2 (borrador) · BORRADOR PARA REVISIÓN DE ABOGADO COLOMBIANO. No aprobado.**

Regla común: Ava Platform es una herramienta tecnológica. **No es prestador de servicios de salud, ni comercio, ni transportador, ni entidad financiera.** Esas obligaciones son del Cliente. Estos anexos se firman o aceptan **solo** si el Cliente opera en el sector.

## Anexo A — Salud

**Cuándo aplica:** el Cliente es una IPS, consultorio, clínica, laboratorio o profesional de la salud que usa Ava para agendar o conversar con pacientes.

1. **Datos sensibles.** Los datos de salud son sensibles (Ley 1581 de 2012, art. 5): requieren autorización expresa, informar que es facultativo darlos y finalidades específicas (art. 6). La casilla es propia y sin premarcar (documento 07-C).
2. **Historia clínica.** La historia clínica está sometida a reserva y reglas propias (Ley 23 de 1981; Resolución 1995 de 1999; Ley 2015 de 2020 sobre historia clínica electrónica interoperable —verificar normas vigentes y modificaciones—). **Ava no es un sistema de historia clínica y no debe usarse para almacenarla ni para enviar diagnósticos.** Las conversaciones y las citas no deben contener información clínica detallada salvo que el Cliente lo decida y asuma sus obligaciones; la decisión sobre «datos clínicos de la cita» sigue abierta en el producto (D-07 de `pendientes.md`).
3. **Reserva profesional y acceso.** El Cliente debe limitar los roles que ven conversaciones; el personal de CCG no accede al contenido salvo acceso de soporte temporal y auditado (documento 05, numeral 4).
4. **IA en salud.** El asistente no debe dar orientación médica; debe derivar a una persona. Las instrucciones del asistente deben prohibirlo.
5. **Proveedores.** Enviar datos de salud a un modelo de lenguaje requiere evaluar el proveedor y la transmisión (documento 06). Hasta tener esa evaluación, **se recomienda no enviar datos clínicos al modelo**.
6. **Retención.** Los plazos de conservación de historia clínica los fija la norma sectorial y se gestionan en el sistema clínico del Cliente, no en Ava.
7. **Pendiente de validación jurídica:** si se ofrecerá el Servicio a este sector y con qué condiciones adicionales (cláusulas de confidencialidad reforzada, auditoría, localización de datos).

## Anexo B — Comercio electrónico y ventas a distancia

**Cuándo aplica:** el Cliente vende bienes o servicios a consumidores por medios electrónicos (por ejemplo con WooCommerce o pedidos por chat).

1. El **proveedor/expendedor** es el Cliente. Debe cumplir la información mínima, el derecho de retracto y la reversión del pago (Ley 1480 de 2011, arts. 46, 47 y 51; reglamentación vigente), las reglas de publicidad y la factura (normativa tributaria).
2. Los precios, existencias y condiciones que muestre el asistente deben provenir del catálogo del Cliente; el asistente no debe prometer lo que el catálogo no respalde.
3. Los pedidos tomados por el asistente deben confirmar al consumidor lo esencial (producto, precio, entrega, medio de pago) antes de cerrarse.
4. Para pagos, el Cliente usa su propia pasarela; Ava no recibe números de tarjeta.

## Anexo C — Delivery

**Cuándo aplica:** el Cliente toma pedidos para entrega a domicilio.

1. Datos de dirección y ubicación son datos personales que requieren finalidad clara (entrega) y conservación limitada.
2. Compartir datos con un tercer repartidor es una transmisión o transferencia que el Cliente debe cubrir en su política y en un contrato con ese tercero.
3. Las condiciones de cobertura, tarifas, tiempos y cancelación son del Cliente y deben informarse antes de confirmar el pedido. Ava no define cobertura ni tarifas (decisión pendiente en el producto).

## Anexo D — Servicios con reservas (agenda)

**Cuándo aplica:** el Cliente toma reservas de citas (salones, consultorios, servicios).

1. Informar al titular las condiciones de cancelación y reprogramación antes de confirmar (el módulo permite un plazo mínimo configurable para que el cliente cambie o cancele por su enlace).
2. Los recordatorios de la cita son comunicaciones **de servicio** ligadas a la relación en curso; las promociones son comerciales y requieren autorización aparte (documento 08).
3. Si la agenda es externa (Booker), esa plataforma es otro proveedor del Cliente: la política y el contrato con Booker son del Cliente.
4. La plataforma conserva el identificador y estado de la reserva para reportes; no recibe datos clínicos ni de pago de Booker.
