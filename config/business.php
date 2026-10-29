<?php

return [

    /*
    | Moneda base de reporte. Todas las operaciones guardan su moneda original,
    | la cotización aplicada y el equivalente en esta moneda calculado en el
    | momento de la operación (nunca se recalcula con la cotización actual).
    */
    'base_currency' => strtoupper(env('BUSINESS_BASE_CURRENCY', 'ARS')),

    'currencies' => array_values(array_filter(array_map(
        fn ($c) => strtoupper(trim($c)),
        explode(',', env('BUSINESS_CURRENCIES', 'ARS,USD'))
    ))),

    'display_timezone' => env('BUSINESS_DISPLAY_TIMEZONE', 'America/Argentina/Cordoba'),

    'inventory' => [
        'allow_negative_stock' => (bool) env('INVENTORY_ALLOW_NEGATIVE_STOCK', false),
    ],

    'purchases' => [
        // value: proporcional al valor de compra (default de la especificación) | quantity: por unidad
        'allocation_method' => env('PURCHASE_ALLOCATION_METHOD', 'value'),
    ],

    'payment_methods' => ['efectivo', 'transferencia', 'tarjeta', 'billetera', 'cripto', 'otro'],

    'expense_categories' => [
        'publicidad' => 'Publicidad',
        'flete' => 'Flete',
        'despacho' => 'Despacho',
        'impuestos_aranceles' => 'Impuestos/aranceles',
        'almacenamiento' => 'Almacenamiento',
        'comisiones' => 'Comisiones',
        'servicios' => 'Servicios',
        'software' => 'Software',
        'logistica' => 'Logística',
        'transporte' => 'Combustible/transporte',
        'devoluciones_garantias' => 'Devoluciones/garantías',
        'otros' => 'Otros',
    ],

    'purchase_cost_types' => ['flete', 'seguro', 'aranceles', 'impuestos', 'despacho', 'traslado', 'otro'],
];
