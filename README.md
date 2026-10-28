# Sistema comercial mayorista (iPhones importados)

Sistema web privado para gestionar compras/importación, inventario serializado, ventas, cobros, gastos, caja y dashboard financiero. Fuente funcional: [`especificacion_sistema_comercial_iphone_mayorista.md`](especificacion_sistema_comercial_iphone_mayorista.md).

- Diagnóstico y plan por hitos: [`docs/plan-por-hitos.md`](docs/plan-por-hitos.md)
- Decisiones técnicas: [`docs/adr/0001-arquitectura-hito-1.md`](docs/adr/0001-arquitectura-hito-1.md)
- Escenario de conciliación (datos de prueba y resultados esperados): [`docs/hito-1-escenario-conciliacion.md`](docs/hito-1-escenario-conciliacion.md)

**Estado:** Hito 1 (núcleo operativo). No hay integraciones externas conectadas (WhatsApp, Meta Ads, Mercado Libre, Google Places): llegan en los hitos 2–4 y el sistema funciona completo sin ellas.

## Stack

Laravel 12 (PHP 8.2+) · PostgreSQL 16 · Vue 3 + TypeScript + Vite + Tailwind 4 · Laravel Sanctum. Monolito modular: la lógica de negocio vive en `app/Domain/*` y la API en `app/Http/Controllers/Api`.

```
app/Domain/Shared      Auditor, Currency, BusinessRuleException
app/Domain/Inventory   InventoryService (único punto que mueve stock, con libro mayor)
app/Domain/Purchasing  CostAllocator (costo puesto en destino), PurchaseService
app/Domain/Sales       SaleService (ciclo de vida), PaymentService (cobros)
app/Domain/Finance     CashService (caja por moneda), ExpenseService
app/Domain/Reports     DashboardService
app/Support            Money (decimal exacto, brick/math), Phone, BusinessDate
resources/js           SPA Vue 3 + TS
```

## Puesta en marcha local

Requisitos: PHP 8.2+ con `pdo_pgsql`, Composer, Node 20+, PostgreSQL 14+.

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
# Crear base y usuario (ejemplo):
#   createuser -P app && createdb -O app ventas && createdb -O app ventas_test
# Completar DB_PASSWORD en .env
php artisan migrate --seed                 # tablas + categorías de gasto/producto (sin usuarios)
php artisan app:create-admin --email=tu@correo.com   # pide contraseña (mín. 12 caracteres)
npm run build                              # o `npm run dev` en otra terminal
php artisan serve                          # http://127.0.0.1:8000
```

Datos ficticios de demostración (solo local; se niega a correr en producción):

```bash
php artisan db:seed --class=DemoSeeder     # usuarios demo@example.com / vendedor@example.com, contraseña "demo-password"
```

## Validación

```bash
php artisan test          # 36 pruebas contra PostgreSQL (base ventas_test, ver phpunit.xml)
vendor/bin/pint --test    # estilo PHP
npm run typecheck         # vue-tsc
npm run build             # build de producción del frontend
```

CI (`.github/workflows/tests.yml`) ejecuta lo mismo con un servicio PostgreSQL 16.

## Variables de entorno nuevas

| Variable | Default | Uso |
|---|---|---|
| `BUSINESS_BASE_CURRENCY` | `ARS` | Moneda de reporte |
| `BUSINESS_CURRENCIES` | `ARS,USD` | Monedas operativas permitidas |
| `BUSINESS_DISPLAY_TIMEZONE` | `America/Argentina/Cordoba` | Fechas de negocio y presentación |
| `INVENTORY_ALLOW_NEGATIVE_STOCK` | `false` | Permitir stock negativo (no recomendado) |
| `PURCHASE_ALLOCATION_METHOD` | `value` | `value` (proporcional al valor) o `quantity` |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | vacío | Opcional, solo para `app:create-admin` no interactivo. No commitear. |

Nunca commitear `.env`. No hay secretos de terceros en este hito.

## Reglas de negocio implementadas

- **Ventas ≠ cobros ≠ ganancia ≠ caja.** El dashboard muestra por separado ventas confirmadas, cobros, COGS, ganancia bruta, gastos operativos, resultado operativo estimado, cuentas por cobrar, caja por moneda e inventario a costo, con nota de cobertura.
- **Decimal exacto** (`numeric` + `brick/math`); se rechazan floats.
- **Multi-moneda histórica:** cada operación guarda moneda, cotización, fecha/fuente y su equivalente en base; nada se recalcula con cotizaciones posteriores.
- **Costo puesto en destino** distribuido por valor o cantidad con detalle auditable; dañadas/faltantes registradas.
- **Precio y costo congelados** al confirmar la venta.
- **Stock nunca negativo** por defecto (validación + bloqueo de fila + `CHECK` en la base).
- **Sin borrados:** cancelaciones, devoluciones, anulaciones de cobros/gastos generan reversas y quedan en auditoría.
- **Idempotencia** con `Idempotency-Key` en operaciones que crean dinero o stock.
- **Permisos por rol:** `admin` (todo), `vendedor` (clientes, ventas, cobros; no anula ni ve finanzas), `analista` (reportes; no ve IMEI ni modifica).

## API (resumen)

Todas bajo `/api`, autenticadas con sesión Sanctum (SPA) o token Bearer.

| Recurso | Endpoints |
|---|---|
| Auth | `POST auth/login`, `POST auth/logout`, `GET auth/me` |
| Catálogo | `GET/POST categories`, `GET/POST products`, `PATCH products/{id}`, `POST products/{id}/variants`, `PATCH variants/{id}` |
| Inventario | `GET stock`, `GET units`, `POST units/{id}/status`, `GET inventory/movements`, `POST inventory/adjustments` |
| Compras | `GET/POST suppliers`, `GET/POST purchase-orders`, `GET purchase-orders/{id}`, `POST purchase-orders/{id}/receive`, `POST purchase-orders/{id}/cancel` |
| Clientes | `GET/POST customers`, `GET/PATCH customers/{id}` |
| Ventas | `GET/POST sales` (`confirm: true` confirma en el acto), `GET sales/{id}`, `POST sales/{id}/confirm|deliver|cancel|return` |
| Cobros | `POST sales/{id}/payments`, `POST payments/{id}/void` |
| Finanzas | `GET dashboard?from&to`, `GET expense-categories`, `GET/POST expenses`, `POST expenses/{id}/pay|void`, `GET/POST cash`, `GET audit-logs` |
| CSV | `GET exports/{ventas|cobros|gastos|caja|stock}?from&to` |

## Limitaciones del Hito 1

Ver la sección "Consecuencias y límites" del ADR 0001: recepción de compras en un paso, devolución total, sin cuentas por pagar a proveedores, sin vencimiento automático de reservas, numeración con posibles saltos, normalización de teléfonos básica. Ninguna API externa conectada.

## Despliegue (resumen)

PHP 8.2+, PostgreSQL, HTTPS obligatorio. En producción: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS=<dominio>`, `php artisan migrate --force --seed`, `php artisan app:create-admin`, `npm ci && npm run build`, `php artisan optimize`. Backup diario de PostgreSQL (`pg_dump`) con prueba de restauración periódica. El manual completo se entrega en el Hito 5.
