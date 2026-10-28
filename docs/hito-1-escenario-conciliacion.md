# Escenario de conciliación del Hito 1

Conjunto de movimientos de prueba documentado (especificación §17: "los reportes se reconcilian contra un conjunto de movimientos de prueba documentado"). Está automatizado en `tests/Feature/FinancialDashboardTest.php` y `tests/Concerns/BuildsBusinessData.php`. Moneda base: **ARS**.

## 1. Compra en USD (cotización 1.000)

| Ítem | Cant. | Costo unit. USD | Descuento | Línea USD | Línea ARS |
|---|---:|---:|---:|---:|---:|
| iPhone 15 128 GB (serializado) | 3 | 700 | 0 | 2.100 | 2.100.000 |
| iPhone 15 256 GB (serializado) | 2 | 800 | 100 | 1.500 | 1.500.000 |
| Funda (no serializada) | 10 | 5 | 0 | 50 | 50.000 |
| **Total mercadería** | | | | **3.650** | **3.650.000** |

Costos atribuibles: flete 146 USD (146.000), seguro 36,50 USD (36.500), aranceles 219.000 ARS → **401.500 ARS**.

Distribución proporcional al valor (401.500 / 3.650.000 = 0,11):

| Ítem | Costos asignados | Puesto en destino | Costo unitario |
|---|---:|---:|---:|
| 128 GB | 231.000 | 2.331.000 | **777.000** |
| 256 GB | 165.000 | 1.665.000 | **832.500** |
| Funda | 5.500 | 55.500 | **5.550** |

## 2. Operaciones de octubre 2026

| Fecha | Operación | Moneda | Importe | Base ARS |
|---|---|---|---:|---:|
| 01/10 | Apertura de caja | ARS | 100.000 | 100.000 |
| 05/10 | Gasto publicidad, pagado con tarjeta | ARS | 50.000 | 50.000 |
| 06/10 | Gasto software (hosting), devengado sin pagar, cotización 1.100 | USD | 20 | 22.000 |
| 10/10 | Venta 1: 2 × iPhone 128 a 900 USD, crédito vence 20/10, cotización 1.100, entregada | USD | 1.800 | 1.980.000 (costo 1.554.000) |
| 11/10 | Venta 2: 4 fundas × 12.000 − 3.000 descuento, contado, confirmada (no entregada) | ARS | 45.000 | 45.000 (costo 22.200) |
| 11/10 | Cobro venta 2, efectivo | ARS | 45.000 | 45.000 |
| 12/10 | Cobro parcial venta 1, transferencia | USD | 1.000 | 1.100.000 |

## 3. Resultado esperado del dashboard (01/10 – 31/10, consultado el 25/10)

| Indicador | Valor ARS | Cálculo |
|---|---:|---|
| Ventas confirmadas netas | 2.025.000 | 1.980.000 + 45.000 |
| Cobrado | 1.145.000 | 1.100.000 + 45.000 |
| Costo de mercadería vendida | 1.576.200 | 1.554.000 + 22.200 |
| Ganancia bruta | 448.800 | 2.025.000 − 1.576.200 |
| Gastos operativos | 72.000 | 50.000 + 22.000 |
| Resultado operativo estimado | 376.800 | 448.800 − 72.000 |
| Cuentas por cobrar | 800 USD = 880.000 | a la cotización de la venta (1.100); vencida desde el 20/10 |
| Gastos devengados impagos | 22.000 | hosting |
| Caja ARS | 95.000 | 0 + (100.000 + 45.000) − 50.000 |
| Caja USD | 1.000 | cobro parcial |
| Inventario a costo | 2.497.500 | 1 × 777.000 + 2 × 832.500 + 10 × 5.550 (las 4 fundas reservadas siguen en poder del negocio) |
| Stock | en poder 13 · reservado 4 · disponible 9 | |

Observá que **ventas ≠ cobros ≠ ganancia ≠ caja**: la caja ARS (95.000) no tiene relación con "ventas − gastos".

## 4. Reversas en un período posterior (noviembre)

El 05/11 se anula el cobro de 1.000 USD (reintegro) y se registra la devolución de la venta 1.

- **Octubre no cambia**: ventas netas 2.025.000 y cobros 1.145.000.
- **Noviembre** muestra: devoluciones 1.980.000 (ventas netas −1.980.000), COGS −1.554.000, cobros anulados 1.100.000, caja USD inicial 1.000 → salida 1.000 → final 0.
- Las unidades devueltas quedan **retenidas** (no vendibles) hasta que un administrador las libere tras revisión.
