# ADR 0002 — CRM de prospectos, radar de tendencias e integraciones (Hito 2)

- **Estado:** aceptada
- **Fecha:** 2026-10-29
- **Fuente funcional:** especificación §7 (radar), §8 (prospección/CRM), §14 (privacidad), §15 (seguridad)

## CRM de prospectos

| Tema | Decisión |
|---|---|
| Origen | Cada prospecto guarda `source` (manual, csv, google_places, referido, visita, web, redes, evento, otro), `source_detail`, `captured_at` y, si vino de un CSV, el lote (`lead_imports`). No se crean prospectos sin fuente. |
| Teléfonos | `App\Support\Phone` canoniza números argentinos a `+54` + 10 dígitos: quita el 9 de celular y el "15" local después de característica de 2–4 dígitos. `0351 15 123-4567`, `+54 9 351 1234567` y `351 1234567` dan `+543511234567`. Números de otros países se aceptan con `+` y se guardan como vinieron (solo dígitos). |
| Duplicados | Coincidencia **fuerte** (bloquea el alta): mismo teléfono normalizado o mismo dominio web/correo corporativo (no Gmail/Hotmail/etc.). Coincidencia **posible** (solo aviso): mismo nombre normalizado (sin acentos, puntos ni sufijos SRL/SA/SAS…) y ciudad. Fusión manual (`merge`) que mueve actividades y consentimientos, deja `duplicate_of_id` y la procedencia en una actividad. |
| Estados | `nuevo → por_verificar → calificado → contactado → respondio/sin_respuesta → interesado → cotizacion_enviada → negociacion → cliente`, más `sin_interes`, `descartado`, `no_contactar`. Transiciones validadas en `LeadService::TRANSITIONS`. `cliente` solo por "convertir" (crea el cliente del Hito 1 y enlaza). |
| Consentimiento | `consent_records` es de solo agregado: canal (whatsapp/email/marketing), método (formulario_web, whatsapp_entrante, verbal_registrado, contrato, otro), evidencia, fecha y usuario. La revocación es un registro nuevo y además agrega supresión por canal. Pasar a `contactado` exige consentimiento activo o una base de contacto escrita (p. ej. "consulta comercial B2B en el local"). |
| Supresión | `suppression_list` guarda solo **HMAC-SHA256** (con `APP_KEY`) del teléfono/correo normalizado, con alcance global/whatsapp/marketing. Sobrevive a la supresión de datos: un contacto borrado no puede reingresar por CSV ni por Places. `no_contactar` siempre está permitido y agrega supresión global. |
| Listas de envío | `GET leads/outreach-list?channel=` devuelve solo prospectos con consentimiento activo en ese canal, sin supresión y fuera de `no_contactar/descartado/sin_interes/cliente`. **No envía nada**: la exportación queda auditada. Ningún prospecto se agrega solo a una campaña. |
| Corrección y supresión (Ley 25.326) | Edición auditada (antes/después). `erase` (solo admin) borra nombre de contacto, teléfono, correo y notas (también las notas de las actividades), deja el registro con `erased_at`, en `no_contactar`, y agrega supresión. Se conservan los datos de la empresa (razón social, web) y la auditoría. |
| Importación CSV | Máx. 5.000 filas; separador `;` o `,` autodetectado; UTF-8 con o sin BOM, con respaldo Windows-1252 (Excel). Encabezados en español o inglés. Resultado por fila: creado / duplicado / suprimido / error, con motivo. `dry_run=1` valida todo dentro de una transacción que se revierte. Una columna de consentimiento solo se acepta con método válido. |

## Radar de tendencias

| Tema | Decisión |
|---|---|
| Fuente | Interfaz `TrendProvider`. `DemoTrendProvider` (por defecto) genera palabras con prefijo `[DEMO]`, `is_demo=true` y confianza "demo"; se ocultan del listado salvo que se pidan. `MercadoLibreTrendsProvider` usa la API oficial `GET /trends/{site}[/{category}]` con OAuth. |
| OAuth de Mercado Libre | Flujo authorization code desde **Integraciones** (estado anti-CSRF en sesión) o `php artisan app:ml-connect`. Tokens en `integration_tokens` cifrados con el cast `encrypted` (APP_KEY), nunca expuestos por la API. Renovación automática con refresh token (que Mercado Libre rota en cada uso). |
| Grupos de la respuesta | La API devuelve 50 términos sin etiqueta; según la documentación de Mercado Libre las posiciones 1–10 son las de mayor crecimiento, 11–30 las más deseadas y 31–50 las más populares. Se guardan como `trend_type` (configurable en `integrations.mercadolibre.groups`). |
| Registro de corridas | Cada sincronización crea un `sync_runs` (proveedor, modo, parámetros, cantidad, requests, duración, error **sanitizado** sin tokens). Reintento con espera ante 429/5xx; 401/403 con mensaje de acción. |
| Programación | `app:trends-sync` los lunes 08:00 (zona del negocio). Requiere cron `* * * * * php artisan schedule:run`. |
| Señales manuales | Se pueden cargar apariciones observadas a mano (fuente + URL + fecha); se rechaza el prefijo `[DEMO]`. |
| Precios | Precio de compra/venta y % de costos solo con **evidencia** (`price_evidence`). Nada de precios, ventas ni reseñas inventados. |

### Puntaje (0–100, para ordenar; no es una predicción)

| Factor | Peso | Cómo se calcula | Si falta |
|---|---:|---|---|
| Demanda observable | 30 | Mejor posición en tendencias de los últimos 30 días: `1 − (pos − 1)/100` | Sin apariciones recientes |
| Aceleración reciente | 20 | Mejora de posición entre la primera y la última medición (`0,5 + Δ/40`); con una sola medición del grupo "crecimiento", 0,75 | Una sola medición |
| Margen potencial | 25 | `(venta − compra × (1 + %costos) × cotización) / venta`; 40 % o más = 1 | Falta algún precio, % o cotización |
| Competencia | 10 | Evaluación manual: baja 1 · media 0,5 · alta 0,15 | No evaluada |
| Proveedores | 10 | `min(1, n/3)`, × 0,8 si ninguno fue verificado por una persona | Sin proveedores |
| Evidencia | 5 | Señales presentes / 5; 0 si solo hay datos demo | — |

Puntaje = suma ponderada de los factores **presentes** / peso presente × 100. Con menos de 2 señales no se muestra puntaje (confianza "insuficiente"). Confianza: alta si la cobertura de peso ≥ 75 %, media ≥ 45 %, baja debajo; "demo" si solo hay datos de demostración. Cada candidato guarda el desglose con notas y la lista de señales faltantes, visible en la ficha.

### Enlaces de proveedores

Plantillas configurables (`integrations.supplier_search_links`) que abren búsquedas en Alibaba, Global Sources, Made-in-China, listado de Mercado Libre, Google Trends AR y TikTok Creative Center. Son **enlaces para que una persona busque**; el sistema no hace scraping. Lo que se releva se carga a mano en `supplier_research` (precio, moneda, MOQ, envío, fecha, verificado, nota de riesgo), junto con una checklist de verificación para proveedores de teléfonos.

Google Trends no se integra (su API está en alfa con acceso limitado) y Meta Ads Library no se usa como API general de productos.

## Google Places (opcional, pago)

- Modo `off` por defecto (la API responde 422 y la UI lo explica). `demo` devuelve 3 resultados ficticios marcados. `live` exige `GOOGLE_PLACES_API_KEY` y un tope mensual (`GOOGLE_PLACES_MONTHLY_REQUEST_LIMIT` > 0) contado con `sync_runs`; al llegar al tope se bloquea.
- Solo Text Search con `X-Goog-FieldMask` explícito (el costo depende de los campos pedidos). Solo admin.
- Los resultados **no se guardan automáticamente**: una persona elige cuáles importar como prospectos (`source=google_places`), pasando por dedupe y supresión.
- **Riesgo a verificar antes de activar `live`:** los términos de Google Maps Platform restringen almacenar contenido de Places más allá de `place_id` (y algunos campos por tiempo limitado). Antes de producción hay que revisar los términos vigentes y, si corresponde, guardar solo `place_id` y datos verificados por el negocio. Además requiere facturación habilitada en GCP con alertas de presupuesto: **no se activa sin aprobación del dueño**.

## Permisos

| Habilidad | admin | vendedor | analista |
|---|:-:|:-:|:-:|
| `crm.manage` (ver/crear/editar prospectos, importar, consentimientos) | ✓ | ✓ | ✓ |
| `crm.erase`, `crm.suppress` | ✓ | | |
| `radar.view` | ✓ | ✓ | ✓ |
| `radar.manage` (editar candidatos, proveedores, sincronizar) | ✓ | | ✓ |
| `integrations.manage`, `prospecting.places` | ✓ | | |

## Límites conocidos

- El importador CSV no soporta campos entre comillas con saltos de línea.
- El pipeline es una vista por columnas sin arrastrar y soltar; el cambio de estado se hace desde la ficha.
- La fusión de duplicados es manual (el sistema solo detecta y avisa).
- La aceleración usa posiciones de Mercado Libre, no volumen de búsquedas (la API no lo da).
- La integración real con Mercado Libre se probó con respuestas simuladas (`Http::fake`) siguiendo la documentación pública; **no se conectó a una cuenta real** porque no hay credenciales. Ver pasos de activación en el README.
