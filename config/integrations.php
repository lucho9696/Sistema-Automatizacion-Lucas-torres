<?php

/*
| Integraciones externas. Todas funcionan apagadas o en modo demo por defecto: el sistema
| nunca simula estar conectado. Los secretos se leen solo del entorno (.env / gestor de
| secretos del hosting) y jamás se envían al frontend ni a los logs.
*/
return [

    'trends' => [
        // demo | mercadolibre
        'provider' => env('TRENDS_PROVIDER', 'demo'),
        'site' => env('TRENDS_SITE', 'MLA'),
        'demand_window_days' => 30,
    ],

    'mercadolibre' => [
        'base_url' => env('ML_API_BASE_URL', 'https://api.mercadolibre.com'),
        'auth_url' => env('ML_AUTH_URL', 'https://auth.mercadolibre.com.ar/authorization'),
        'client_id' => env('ML_CLIENT_ID'),
        'client_secret' => env('ML_CLIENT_SECRET'),
        'redirect_uri' => env('ML_REDIRECT_URI'),
        'timeout' => 10,
        // Según la documentación de tendencias (verificada al planificar, revisar si cambia):
        // posiciones 1-10 mayor crecimiento, 11-30 más deseadas, 31-50 más populares.
        'groups' => [[1, 10, 'crecimiento'], [11, 30, 'deseadas'], [31, 50, 'populares']],
    ],

    'google_places' => [
        // off | demo | live. "live" consume la API paga de Google: requiere aprobación explícita.
        'mode' => env('GOOGLE_PLACES_MODE', 'off'),
        'api_key' => env('GOOGLE_PLACES_API_KEY'),
        'endpoint' => 'https://places.googleapis.com/v1/places:searchText',
        // Pedir solo lo necesario: los campos de contacto pertenecen a un SKU más caro.
        'field_mask' => env('GOOGLE_PLACES_FIELD_MASK', 'places.id,places.displayName,places.formattedAddress,places.nationalPhoneNumber,places.websiteUri,places.googleMapsUri'),
        'monthly_request_limit' => (int) env('GOOGLE_PLACES_MONTHLY_REQUEST_LIMIT', 0),
        'page_size' => 20,
        'timeout' => 10,
    ],

    // Atajos de búsqueda. No implican que un proveedor esté validado.
    'supplier_search_links' => [
        'Alibaba' => 'https://www.alibaba.com/trade/search?SearchText={q}',
        'Global Sources' => 'https://www.globalsources.com/searchList/products?keyWord={q}',
        'Made-in-China' => 'https://www.made-in-china.com/products-search/hot-china-products/{q}.html',
        'Mercado Libre (competencia)' => 'https://listado.mercadolibre.com.ar/{q}',
        'Google Trends (AR)' => 'https://trends.google.com/trends/explore?geo=AR&q={q}',
        'TikTok Creative Center' => 'https://ads.tiktok.com/business/creativecenter/inspiration/popular/hashtag/pc/es?period=30&region=AR',
    ],

    'whatsapp' => [
        // simulado (por defecto: nada sale del sistema) | cloud (WhatsApp Business Platform Cloud API oficial)
        'mode' => env('WHATSAPP_MODE', 'simulado'),
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        // Versión de la Graph API: revisar la vigente en developers.facebook.com antes de producción.
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),       // token de usuario del sistema (secreto)
        'app_secret' => env('WHATSAPP_APP_SECRET'),           // firma X-Hub-Signature-256 (secreto)
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),       // verificación GET del webhook (secreto)
        'timeout' => 10,
        // Vigencia de las cotizaciones del bot y retención de mensajes.
        'quote_valid_hours' => (int) env('WHATSAPP_QUOTE_VALID_HOURS', 24),
        'retention_days' => (int) env('WHATSAPP_RETENTION_DAYS', 365),
        // Mensajes sin entender seguidos antes de derivar a una persona.
        'max_misunderstood' => 2,
        // Palabras (sin acentos) que derivan a una persona y pausan el bot.
        'handoff_keywords' => ['humano', 'persona', 'asesor', 'vendedor', 'operador', 'hablar con alguien', 'reclamo', 'queja',
            'factura', 'afip', 'arca', 'monotributo', 'abogado', 'denuncia', 'descuento', 'mejor precio', 'rebaja', 'negociar'],
        // Temas que se responden solo si hay una FAQ cargada; si no, se deriva (no se promete nada).
        'sensitive_topics' => ['garantia', 'devolucion', 'cambio', 'envio', 'envios', 'entrega', 'original', 'autentic', 'importacion'],
        'opt_out_keywords' => ['baja', 'stop', 'no me escriban', 'no quiero recibir', 'dejen de escribir', 'no me manden'],
        'accept_keywords' => ['confirmo', 'acepto'],
        'reject_keywords' => ['no confirmo', 'no acepto', 'cancelar', 'cancelo', 'rechazo'],
    ],

    // Checklist de validación de proveedores de celulares (especificación §7.5).
    'phone_supplier_checklist' => [
        'IMEI/serie de cada unidad informados antes del pago y verificables',
        'Estado de activación / bloqueo de iCloud y operador',
        'Procedencia y factura del proveedor',
        'Condición real (nuevo, usado, reacondicionado) y salud de batería',
        'Garantía ofrecida y política ante unidades defectuosas',
        'Costos de importación, flete, seguro y aranceles estimados',
        'Referencias del proveedor y antigüedad verificables',
    ],
];
