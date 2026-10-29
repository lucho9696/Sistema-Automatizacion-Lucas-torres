# ADR 0001 — Arquitectura y decisiones del Hito 0/1

- **Estado:** aceptada
- **Fecha:** 2026-10-28
- **Fuente funcional:** `especificacion_sistema_comercial_iphone_mayorista.md` (v1.0)

## Contexto (auditoría del Hito 0)

Al iniciar, el repositorio `lucho9696/Sistema-Automatizacion-Lucas-torres` tenía **un solo commit** con la especificación y ninguna línea de código: sin stack, base de datos, autenticación, pruebas, CI ni configuración de despliegue. No había nada que migrar ni preservar salvo la especificación, que se mantiene intacta en la raíz.

Entorno disponible para construir y probar: PHP 8.3, Composer 2, Node 22, PostgreSQL 16 (local). Extensión `bcmath` no disponible → se usa `brick/math` (PHP puro) para aritmética decimal.

## Decisiones

| Tema | Decisión | Motivo |
|---|---|---|
| Estilo | Monolito modular (no microservicios) | Especificación §10.1; un solo negocio, volumen inicial bajo. |
| Backend | Laravel 12 (PHP ≥ 8.2) | Indicado por la especificación cuando no hay stack previo; versión estable actual. |
| Frontend | Vue 3 + TypeScript (SPA servida por Laravel vía Vite), Tailwind 4 | Indicado por la especificación; sin Nuxt porque no hay separación API/frontend justificada. |
| Base de datos | PostgreSQL 16 | `numeric` exacto, `CHECK` constraints, `jsonb`, `FOR UPDATE`. Las pruebas corren contra PostgreSQL real (no SQLite) para validar decimales y bloqueos. |
| Auth | Sesión + Laravel Sanctum (SPA misma-origen; tokens disponibles para scripts) | Gratis, estándar, CSRF incluido. Login con rate limiting (5/min). Sin usuario por defecto: `php artisan app:create-admin`. |
| Roles | Columna `users.role` (`admin`, `vendedor`, `analista`) + Gates (`AppServiceProvider::ABILITIES`) | Mínimo para el MVP; agregar roles/usuarios no requiere rediseño. |
| Dinero | `numeric(18,2)` importes, `numeric(18,4)` costos unitarios, `numeric(18,6)` cotizaciones. En PHP se opera como **string** con `App\Support\Money` (brick/math, HALF_UP). Se rechazan `float`. | Regla obligatoria de decimal fijo. |
| Multi-moneda | Moneda base configurable (`BUSINESS_BASE_CURRENCY`, default ARS). Toda operación guarda moneda original, cotización, fecha/fuente y equivalente en base calculado **en ese momento**. La cotización la informa el usuario; nunca se busca una "actual" implícitamente. | §5.7; no recalcular históricos. |
| Costo de importación | `CostAllocator`: distribución por **valor** (default) o por **cantidad**, método del mayor resto para que la suma sea exacta; costo unitario = costo puesto en destino de la línea / unidades válidas (recibidas − dañadas). El cálculo completo se guarda en `purchase_orders.allocation_snapshot`. | §5.4. |
| Inventario | Saldos por variante (`stock_on_hand`, `stock_reserved`, `stock_held`) modificados **solo** por `InventoryService` dentro de transacciones con `SELECT … FOR UPDATE`; cada cambio deja un `inventory_movements` (libro mayor). `CHECK` en la base impide reservados/retenidos negativos. Stock negativo prohibido salvo `INVENTORY_ALLOW_NEGATIVE_STOCK=true`. | §5.3, §12. |
| Serializados | `product_units` con IMEI/IMEI2/serie únicos; la categoría define si exige IMEI. IMEI/serie ocultos para el rol `analista`. Venta asigna unidades FIFO o las elegidas. | §5.3. |
| Costo de venta | Serializados: costo real de la unidad. No serializados: costo promedio ponderado en base al confirmar. Precio y costo **congelados** en `sale_items` al confirmar. | §11, §12. |
| Ventas | Estado de la venta (`borrador`→`confirmada`→`entregada`; `cancelada`; `devuelta`) separado del estado de cobro (`pendiente`/`parcial`/`pagado`). Reserva al confirmar, salida al entregar, liberación al cancelar, reingreso **retenido** al devolver. | §5.5, §13.1 (ver mapeo abajo). |
| Cobros | Solo por usuario autorizado; si la moneda del cobro difiere de la venta, el importe imputado se informa explícitamente. No se permite sobrepago. Anular un cobro crea una **reversa** de caja en la fecha de anulación. | §6.3, §12. |
| Caja | `cash_movements` por moneda: entradas/salidas reales (cobros, gastos pagados, aperturas, aportes, retiros, pagos a proveedor, ajustes). Nunca ventas − gastos devengados. | §12. |
| Gastos | Devengado y pagado separados; anulación con motivo y reversa de caja. `external_key` única para impedir doble importación (p. ej. Meta Ads). | §5.6. |
| Auditoría | `audit_logs` con usuario, evento, valores antes/después (redactando claves sensibles), motivo, IP y `X-Request-Id`. | §3, §15. |
| Idempotencia | Middleware `idempotent`: header `Idempotency-Key` en POST de ventas, cobros, gastos, compras, recepción y caja. Repetición devuelve la respuesta original; misma clave con otro cuerpo → 422. El frontend genera una clave por envío. | §3, §12. |
| Reportes | Ventas por `sale_date`; anulaciones/devoluciones por fecha del evento (los períodos cerrados no cambian); cobros por fecha de caja; gastos por devengo. | §12. |
| CSV | Exportaciones de ventas, cobros, gastos, caja y stock (UTF-8 con BOM, anti-inyección de fórmulas). | §5.8. |

### Mapeo con la máquina de estados de la especificación (§13.1)

| Especificación | Implementación Hito 1 |
|---|---|
| Borrador / Cotizado | `borrador` (con `quote_valid_until` opcional) |
| PendienteConfirmacion / PendientePago | `confirmada` + `payment_status=pendiente` |
| PagoParcial | `confirmada|entregada` + `payment_status=parcial` |
| Pagado | `payment_status=pagado` |
| Preparando / Entregado | `entregada` (Preparando se agrega si hace falta logística) |
| Vencido | No implementado aún (Hito 4: vencimiento de reservas con scheduler) |
| Cancelado / Devuelto | `cancelada` / `devuelta` |

## Consecuencias y límites conocidos

- Recepción de compras **en un solo paso** por lote; recepciones parciales múltiples quedan para más adelante.
- Devolución **total** de una venta; devoluciones parciales y notas de crédito, más adelante.
- El reintegro de dinero de una devolución se registra anulando el/los cobros (genera salida de caja en la fecha de anulación).
- La numeración `V-000001` usa el id: puede tener saltos (no es numeración fiscal).
- Los pagos a proveedores se registran como movimiento de caja manual (`pago_proveedor`); no hay aún cuentas por pagar a proveedores.
- Normalización de teléfonos simple (+54 por defecto); la normalización/deduplicación completa del CRM es Hito 2.
- Sin cola/scheduler en uso todavía: no hay integraciones externas activas en este hito.
