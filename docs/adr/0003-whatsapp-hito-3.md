# ADR 0003 — Asistente de WhatsApp (Hito 3)

- **Estado:** aceptada
- **Fecha:** 2026-10-29
- **Fuente funcional:** especificación §6 (WhatsApp), §13.1/§13.2 (pedido y flujo de venta), §15 (seguridad), §17 (criterios de aceptación)

## Decisiones

| Tema | Decisión |
|---|---|
| Canal | Solo **WhatsApp Business Platform Cloud API** oficial. Nada de WhatsApp Web ni librerías no oficiales. Interfaz `MessagingProvider` con dos implementaciones: `SimulatedMessagingProvider` (por defecto, `WHATSAPP_MODE=simulado`: registra pero no envía) y `WhatsAppCloudProvider` (`WHATSAPP_MODE=cloud`). |
| Webhook | `GET /api/webhooks/whatsapp` verifica la suscripción con `WHATSAPP_VERIFY_TOKEN`. `POST` exige la firma `X-Hub-Signature-256` (HMAC-SHA256 del cuerpo crudo con `WHATSAPP_APP_SECRET`, comparación en tiempo constante); sin secreto configurado responde 503. Guarda el evento (`webhook_events`, único por hash del cuerpo), responde 200 enseguida y procesa en la cola (`ProcessWebhookEvent`, 3 intentos). |
| Idempotencia | Reenvío idéntico: se descarta por hash. Mismo mensaje en otro envoltorio: `messages.external_id` es único (`wamid…`). El bot atiende cada entrante una sola vez (bloqueo de fila + `processed_at`). Cada cotización está atada a su mensaje de origen (`bot_quotes.source_message_id` único). La conversión a venta bloquea la cotización y exige estado `aceptada` sin venta previa. |
| Estados de entrega | `sent/delivered/read/failed` actualizan el mensaje saliente sin retroceder (un "entregado" que llega tarde no pisa "leído"). |
| Bot | Por reglas, **sin IA**: ninguna respuesta contiene precios o stock que no salgan de una consulta al catálogo en ese momento. Cada respuesta guarda en `messages.sources` la variante, precio, moneda, stock y hora consultados. |
| Búsqueda en catálogo | Normaliza el texto (sin acentos), separa la cantidad ("10 unidades", "x10", "quiero 10 …") y exige que todos los números coincidan (no confunde iPhone 15 con 13). Si hay varias variantes, lista y pide precisar; con una sola y cantidad, cotiza. |
| Cotización | Precios congelados del catálogo en su moneda, vigencia `WHATSAPP_QUOTE_VALID_HOURS` (24 h). Respeta el mínimo mayorista (`wholesale_min_qty`). Sin precio cargado o sin stock suficiente: no cotiza y deriva. Pide aceptación explícita ("CONFIRMO"); "sí" no alcanza. |
| Pedido | Al aceptar, la cotización pasa a `aceptada` = **pedido pendiente de confirmación** (§13.1 `PendienteConfirmacion`) y la conversación queda marcada para atender. Una persona lo valida desde la bandeja: crea la venta con los precios cotizados (informando la cotización de cambio si la moneda no es la base) y, opcionalmente, la confirma, lo que reserva stock (default de §19: "reserva al crear pedido validado"). La venta queda con **pago pendiente**: el bot nunca registra cobros, aunque el cliente diga que pagó. |
| Derivación | Palabras como "persona", "vendedor", "reclamo", "factura", "descuento" o "mejor precio", dos mensajes seguidos sin entender, falta de stock o precio, o un error interno → el bot avisa, pasa la conversación a modo `humano` y deja de responder hasta que un operador la devuelva al bot. |
| Temas sensibles | Garantía, devoluciones, envíos, autenticidad e importación solo se responden si hay una respuesta cargada en **Respuestas frecuentes** (`bot_faqs`, editable por admin y auditada). Si no hay, deriva. El bot no promete nada que no esté configurado. |
| Estado de pedido | Solo informa pedidos del cliente cuyo teléfono registrado coincide con el número de WhatsApp. |
| Ventana de 24 h | Texto libre solo si el último mensaje del cliente fue hace menos de 24 h; fuera de la ventana solo plantillas aprobadas (nombre, idioma y variables). |
| Baja (opt-out) | "baja", "stop", "no me escriban"… → `opted_out_at`, supresión del teléfono para el canal WhatsApp (queda fuera de las listas de envío del CRM) y bloqueo de plantillas. Si el cliente escribe por una consulta, se le responde. |
| Vínculos | La conversación se asocia sola al cliente o prospecto con el mismo teléfono normalizado. Al crear el pedido, si no hay cliente, se crea uno con origen `whatsapp`. |
| Retención | `app:whatsapp-prune` (diario 03:00) borra el contenido de mensajes más viejos que `WHATSAPP_RETENTION_DAYS` (365) y deja fechas, estados y relaciones. |
| Permisos | `whatsapp.view` y `whatsapp.reply`: admin y vendedor. `whatsapp.manage` (respuestas frecuentes, simulador): admin. El analista no ve conversaciones (datos personales). Crear el pedido exige además `sales.create`. |
| Errores | Los fallos de la API se guardan en el mensaje con un texto accionable y sin tokens (p. ej. "token inválido o vencido", "fuera de la ventana de 24 h"); la conversación queda para atender. Un webhook que falla tres veces queda marcado con la clase de error, sin el contenido. |

## Activación en producción (requiere la cuenta del cliente)

1. El cliente crea (o usa) su cuenta de **Meta Business**, una app en developers.facebook.com con el producto WhatsApp, y registra el número que va a usar. No se piden contraseñas personales a nadie.
2. Generar un **token de usuario del sistema** con permisos `whatsapp_business_messaging` y `whatsapp_business_management`. Copiar el **Phone number ID** y el **App Secret** de la app.
3. Cargar en `.env` del servidor: `WHATSAPP_MODE=cloud`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN` (una cadena aleatoria que inventa el negocio) y `php artisan config:clear`.
4. En la app de Meta → WhatsApp → Configuración, poner como webhook `https://<dominio>/api/webhooks/whatsapp` con el mismo verify token y suscribir el campo `messages`. HTTPS obligatorio.
5. Dejar corriendo el worker de colas (`php artisan queue:work`, con supervisor o similar) y el cron de `schedule:run`.
6. Cargar las respuestas frecuentes (garantía, envíos, formas de pago) y probar con un número propio antes de publicarlo.
7. Verificar las tarifas vigentes de WhatsApp: se cobran por conversación o plantilla según la política de Meta, a cargo de la cuenta del cliente.

## Límites conocidos

- El bot entiende texto; audios, fotos y documentos se guardan como "[tipo]" y se pide escribir o hablar con una persona.
- Una cotización tiene una sola variante; pedidos de varios modelos en un mensaje se derivan o se cotizan por separado.
- La bandeja se actualiza cada 15 segundos (sin websockets).
- No hay vencimiento automático de reservas ni recordatorios automáticos (Hito 4).
- La integración real con Cloud API está probada con respuestas simuladas (`Http::fake`) según la documentación pública; **no se conectó a una cuenta real**.
