# Sistema comercial mayorista (iPhones importados)

Sistema web privado para gestionar compras/importación, inventario serializado, ventas, cobros, gastos, caja y dashboard financiero. Fuente funcional: [`especificacion_sistema_comercial_iphone_mayorista.md`](especificacion_sistema_comercial_iphone_mayorista.md).

- Diagnóstico y plan por hitos: [`docs/plan-por-hitos.md`](docs/plan-por-hitos.md)
- Decisiones técnicas: [`docs/adr/0001-arquitectura-hito-1.md`](docs/adr/0001-arquitectura-hito-1.md)
- CRM, radar e integraciones (Hito 2): [`docs/adr/0002-crm-y-radar-hito-2.md`](docs/adr/0002-crm-y-radar-hito-2.md)
- Escenario de conciliación (datos de prueba y resultados esperados): [`docs/hito-1-escenario-conciliacion.md`](docs/hito-1-escenario-conciliacion.md)

**Estado:** Hito 2 (núcleo operativo + CRM de prospectos + radar de tendencias). Mercado Libre Trends y Google Places tienen adaptador listo pero **no están conectados**: funcionan en modo demo señalizado hasta que el dueño cargue credenciales (pasos abajo). WhatsApp y Meta Ads llegan en los hitos 3–4. El sistema funciona completo sin ninguna integración.

## Stack

Laravel 12 (PHP 8.2+) · PostgreSQL 16 · Vue 3 + TypeScript + Vite + Tailwind 4 · Laravel Sanctum. Monolito modular: la lógica de negocio vive en `app/Domain/*` y la API en `app/Http/Controllers/Api`.

```
app/Domain/Shared      Auditor, Currency, BusinessRuleException
app/Domain/Inventory   InventoryService (único punto que mueve stock, con libro mayor)
app/Domain/Purchasing  CostAllocator (costo puesto en destino), PurchaseService
app/Domain/Sales       SaleService (ciclo de vida), PaymentService (cobros)
app/Domain/Finance     CashService (caja por moneda), ExpenseService
app/Domain/Reports     DashboardService
app/Domain/Crm         LeadService (estados, consentimiento, fusión, supresión de datos), LeadDeduplicator, LeadCsvImporter, Suppression
app/Domain/Radar       TrendService, CandidateScorer, DemoTrendProvider, MercadoLibreTrendsProvider, MercadoLibreAuth
app/Domain/Prospecting PlacesSearch (Google Places, apagado por defecto)
app/Domain/Integrations SyncRunRecorder, IntegrationException
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
php artisan test          # 51 pruebas contra PostgreSQL (base ventas_test, ver phpunit.xml)
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

Hito 2 (todas opcionales; sin ellas el radar corre en demo y Places queda apagado):

| Variable | Default | Uso |
|---|---|---|
| `TRENDS_PROVIDER` | `demo` | `demo` o `mercadolibre` |
| `TRENDS_SITE` | `MLA` | Sitio de Mercado Libre |
| `ML_CLIENT_ID` / `ML_CLIENT_SECRET` | vacío | App de developers.mercadolibre.com.ar. **Secreto** |
| `ML_REDIRECT_URI` | vacío | `https://<dominio>/integraciones/mercadolibre/callback` |
| `ML_API_BASE_URL` / `ML_AUTH_URL` | API y auth de MLA | Solo si cambian |
| `GOOGLE_PLACES_MODE` | `off` | `off`, `demo` o `live` (pago) |
| `GOOGLE_PLACES_API_KEY` | vacío | **Secreto**; restringir por IP y a Places API (New) |
| `GOOGLE_PLACES_FIELD_MASK` | id, nombre, dirección, teléfono, web, link Maps | Define el costo por solicitud |
| `GOOGLE_PLACES_MONTHLY_REQUEST_LIMIT` | `0` | Tope mensual; 0 bloquea |

Nunca commitear `.env`. Los tokens OAuth de Mercado Libre se guardan cifrados en la base, no en `.env`.

## Activar Mercado Libre Trends

1. En https://developers.mercadolibre.com.ar crear una aplicación con la cuenta del negocio. URL de redirección: `https://<tu-dominio>/integraciones/mercadolibre/callback` (HTTPS obligatorio).
2. Cargar `ML_CLIENT_ID`, `ML_CLIENT_SECRET`, `ML_REDIRECT_URI` en `.env` y `php artisan config:clear`.
3. Entrar como admin a **Integraciones → Conectar Mercado Libre** y autorizar. Sin navegador: `php artisan app:ml-connect` muestra la URL y luego `php artisan app:ml-connect <code>`.
4. Poner `TRENDS_PROVIDER=mercadolibre` y probar con **Radar → Sincronizar** o `php artisan app:trends-sync`. El resultado (o el error, sin secretos) queda en Integraciones → últimas corridas.
5. Para la corrida semanal (lunes 08:00) agregar el cron `* * * * * cd /ruta && php artisan schedule:run`.

## Activar Google Places (opcional, genera cargos)

Requiere aprobación del dueño: proyecto en Google Cloud con facturación, Places API (New) habilitada, clave restringida y alerta de presupuesto. Luego `GOOGLE_PLACES_MODE=live`, `GOOGLE_PLACES_API_KEY` y `GOOGLE_PLACES_MONTHLY_REQUEST_LIMIT` (p. ej. 200). Antes, revisar los términos vigentes de Google Maps Platform sobre almacenamiento de datos (ver ADR 0002). Para probar la pantalla sin costo: `GOOGLE_PLACES_MODE=demo`.

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
| Prospectos | `GET/POST leads`, `GET leads/pipeline`, `GET/PATCH leads/{id}`, `POST leads/{id}/status|activities|consents|merge|convert|erase`, `POST leads/import` (`dry_run`), `GET leads/outreach-list?channel=&format=csv`, `POST suppressions` |
| Radar | `GET trends/candidates`, `GET/PATCH trends/candidates/{id}`, `POST trends/candidates/{id}/suppliers`, `POST trends/signals`, `POST trends/sync` |
| Integraciones | `GET integrations/status`, `GET integrations/mercadolibre/authorize`, `GET prospecting/places/status`, `POST prospecting/places/search` |
| CSV | `GET exports/{ventas|cobros|gastos|caja|stock}?from&to` |

## Limitaciones

Ver la sección "Consecuencias y límites" del ADR 0001: recepción de compras en un paso, devolución total, sin cuentas por pagar a proveedores, sin vencimiento automático de reservas, numeración con posibles saltos, sin cuentas por pagar a proveedores. Hito 2: ver "Límites conocidos" del ADR 0002 (CSV sin campos multilínea, pipeline sin arrastrar y soltar, fusión manual, Mercado Libre probado solo con respuestas simuladas).

## Despliegue (resumen)

PHP 8.2+, PostgreSQL, HTTPS obligatorio. En producción: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS=<dominio>`, `php artisan migrate --force --seed`, `php artisan app:create-admin`, `npm ci && npm run build`, `php artisan optimize`. Backup diario de PostgreSQL (`pg_dump`) con prueba de restauración periódica. El manual completo se entrega en el Hito 5.
