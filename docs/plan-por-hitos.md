# Diagnóstico y plan por hitos

## Hito 0 — Diagnóstico del repositorio (28/10/2026)

| Aspecto | Estado encontrado |
|---|---|
| Git | Una rama (`claude/project-thread-df4uoo`, default) con un commit: la especificación. Sin `main`. |
| Código / stack | Ninguno. |
| Base de datos / migraciones | Ninguna. |
| Autenticación | Ninguna. |
| Pruebas / CI | Ninguna. |
| Configuración / secretos | Ninguno (no hay `.env` ni credenciales en el repo). |
| Riesgo de pérdida de datos | Nulo: no había código ni datos que migrar. La especificación se conserva sin cambios. |

**Recomendación de Git:** renombrar la rama default a `main` (Settings → Branches) o crear `main` desde ella, y trabajar con una rama por hito.

## Alcance del MVP (según §2 de la especificación)

Incluido en Hito 1: acceso privado, catálogo/variantes/unidades con IMEI, compras con costo puesto en destino, inventario, ventas manuales, cobros, gastos, caja, clientes, dashboard, CSV y auditoría.

Fuera del costo de desarrollo y sujeto a aprobación/credenciales del cliente: WhatsApp Cloud API en producción, Meta Marketing API, Google Places (genera cargos), IA paga, n8n, campañas.

## Inventario de APIs externas

| API | Hito | Costo | Requisitos | Estado |
|---|---|---|---|---|
| Mercado Libre Trends (MLA) | 2 | Gratis (requiere app y OAuth) | App en developers.mercadolibre.com.ar, `client_id/secret` | Adaptador listo; no conectada (modo demo) |
| Google Trends API | — | Alfa, acceso limitado | No es requisito del MVP; solo enlaces | No se integra |
| Google Places (Text Search) | 2 (opcional) | **Pago** por campos (FieldMask) | Proyecto GCP, facturación, cuotas y alertas aprobadas | Adaptador listo; apagada por defecto |
| WhatsApp Cloud API | 3 | Pago por conversación/plantilla según tarifas vigentes | Cuenta Meta Business del cliente, número, webhook HTTPS, token del sistema | No conectada |
| Meta Marketing API (Insights) | 4 | Gratis (permisos `ads_read`) | OAuth del dueño de la cuenta publicitaria | No conectada; fallback: gasto manual/CSV |
| Proveedor de IA | opcional | Pago | Aprobación explícita y tope mensual | No incluido |

Ninguna integración es necesaria para operar el núcleo: ventas, cobros, gastos y stock funcionan siempre de forma manual.

## Plan

| Hito | Contenido | Estado |
|---|---|---|
| 0 | Auditoría, decisiones (ADR 0001), alcance, inventario de APIs | ✅ |
| 1 | Núcleo operativo: auth/roles, catálogo, compras/recepción, costo de destino, inventario, ventas/cotizaciones, cobros, gastos, caja, clientes, dashboard, CSV, auditoría, idempotencia | ✅ API + UI (ver límites en ADR 0001) |
| 2 | CRM de prospectos (estados, actividades, consentimiento, supresión, dedupe), importación CSV, radar con Mercado Libre Trends (adaptador + modo demo señalizado), score explicable, enlaces de proveedores; Google Places opcional tras feature flag | ✅ API + UI (ver ADR 0002); Mercado Libre y Places sin conectar hasta tener credenciales |
| 3 | `MessagingProvider` + adaptador WhatsApp Cloud API (webhook con verificación de firma, dedupe por ID externo, cola), bandeja, FAQ desde la base, cotización → pedido pendiente, derivación humana con pausa del bot, opt-out. Modo simulado por defecto | Pendiente |
| 4 | Meta Ads Insights idempotente, scheduler, colas, reintentos, alertas (cobros vencidos, stock bajo, reservas por vencer), vencimiento de reservas | Pendiente |
| 5 | E2E, endurecimiento de permisos, backups y restauración, manual de despliegue y capacitación | Pendiente |

## Riesgos

- **Cotizaciones:** el sistema no consulta cotizaciones; si el usuario carga una cotización errónea queda congelada. Mitigación: se muestra en cada operación; corrección vía anulación + nueva carga.
- **Alcance vs. presupuesto (§2):** las integraciones de Hitos 3–4 dependen de cuentas y aprobaciones de Meta del cliente; conviene acordarlas por escrito.
- **Hosting:** requiere PHP 8.2+, PostgreSQL y HTTPS. Para colas/scheduler de Hitos 3–4 hará falta un proceso worker y cron.
- **Datos personales (Ley 25.326):** el CRM incluye corrección, supresión y lista de no contactar (ADR 0002). Falta definir con el cliente el plazo de retención de prospectos inactivos.
- **Google Places:** costo por solicitud y restricciones de almacenamiento de sus términos; se activa solo con aprobación.
