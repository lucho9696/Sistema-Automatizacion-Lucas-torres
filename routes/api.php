<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CrmController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ProspectingSearchController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\RadarController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\WhatsAppController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Webhook público de WhatsApp Cloud API (protegido por verify token y firma HMAC, no por sesión).
Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->middleware('throttle:30,1');
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])->middleware('throttle:600,1');

Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Catálogo e inventario
    Route::get('/categories', [CatalogController::class, 'categories']);
    Route::post('/categories', [CatalogController::class, 'storeCategory']);
    Route::get('/products', [CatalogController::class, 'products']);
    Route::post('/products', [CatalogController::class, 'storeProduct']);
    Route::patch('/products/{product}', [CatalogController::class, 'updateProduct']);
    Route::post('/products/{product}/variants', [CatalogController::class, 'storeVariant']);
    Route::patch('/variants/{variant}', [CatalogController::class, 'updateVariant']);
    Route::get('/stock', [CatalogController::class, 'stock']);
    Route::get('/units', [CatalogController::class, 'units']);
    Route::post('/units/{unit}/status', [InventoryController::class, 'changeUnitStatus']);
    Route::get('/inventory/movements', [InventoryController::class, 'movements']);
    Route::post('/inventory/adjustments', [InventoryController::class, 'adjust'])->middleware('idempotent');

    // Compras / importación
    Route::get('/suppliers', [PurchaseController::class, 'suppliers']);
    Route::post('/suppliers', [PurchaseController::class, 'storeSupplier']);
    Route::get('/purchase-orders', [PurchaseController::class, 'index']);
    Route::post('/purchase-orders', [PurchaseController::class, 'store'])->middleware('idempotent');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseController::class, 'show']);
    Route::post('/purchase-orders/{purchaseOrder}/receive', [PurchaseController::class, 'receive'])->middleware('idempotent');
    Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseController::class, 'cancel']);

    // Clientes
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::patch('/customers/{customer}', [CustomerController::class, 'update']);

    // Ventas y cobros
    Route::get('/sales', [SaleController::class, 'index']);
    Route::post('/sales', [SaleController::class, 'store'])->middleware('idempotent');
    Route::get('/sales/{sale}', [SaleController::class, 'show']);
    Route::post('/sales/{sale}/confirm', [SaleController::class, 'confirm'])->middleware('idempotent');
    Route::post('/sales/{sale}/deliver', [SaleController::class, 'deliver'])->middleware('idempotent');
    Route::post('/sales/{sale}/cancel', [SaleController::class, 'cancel']);
    Route::post('/sales/{sale}/return', [SaleController::class, 'returnSale']);
    Route::post('/sales/{sale}/payments', [SaleController::class, 'storePayment'])->middleware('idempotent');
    Route::post('/payments/{payment}/void', [SaleController::class, 'voidPayment']);

    // Finanzas
    Route::get('/dashboard', [FinanceController::class, 'dashboard']);
    Route::get('/expense-categories', [FinanceController::class, 'expenseCategories']);
    Route::get('/expenses', [FinanceController::class, 'expenses']);
    Route::post('/expenses', [FinanceController::class, 'storeExpense'])->middleware('idempotent');
    Route::post('/expenses/{expense}/pay', [FinanceController::class, 'payExpense'])->middleware('idempotent');
    Route::post('/expenses/{expense}/void', [FinanceController::class, 'voidExpense']);
    Route::get('/cash', [FinanceController::class, 'cashMovements']);
    Route::post('/cash', [FinanceController::class, 'storeCashMovement'])->middleware('idempotent');
    Route::get('/audit-logs', [FinanceController::class, 'auditLogs']);

    // CRM de prospectos
    Route::get('/leads', [CrmController::class, 'index']);
    Route::post('/leads', [CrmController::class, 'store'])->middleware('idempotent');
    Route::get('/leads/pipeline', [CrmController::class, 'pipeline']);
    Route::get('/leads/outreach-list', [CrmController::class, 'outreachList']);
    Route::post('/leads/import', [CrmController::class, 'import'])->middleware('throttle:10,1');
    Route::get('/leads/{lead}', [CrmController::class, 'show']);
    Route::patch('/leads/{lead}', [CrmController::class, 'update']);
    Route::post('/leads/{lead}/status', [CrmController::class, 'changeStatus']);
    Route::post('/leads/{lead}/activities', [CrmController::class, 'storeActivity'])->middleware('idempotent');
    Route::post('/leads/{lead}/consents', [CrmController::class, 'storeConsent'])->middleware('idempotent');
    Route::post('/leads/{lead}/merge', [CrmController::class, 'merge']);
    Route::post('/leads/{lead}/convert', [CrmController::class, 'convert'])->middleware('idempotent');
    Route::post('/leads/{lead}/erase', [CrmController::class, 'erase']);
    Route::post('/suppressions', [CrmController::class, 'suppress']);
    Route::get('/prospecting/places/status', [CrmController::class, 'placesStatus']);
    Route::post('/prospecting/places/search', [CrmController::class, 'placesSearch'])->middleware('throttle:10,1');
    Route::get('/prospecting/searches', [ProspectingSearchController::class, 'index']);
    Route::post('/prospecting/searches', [ProspectingSearchController::class, 'store']);
    Route::patch('/prospecting/searches/{search}', [ProspectingSearchController::class, 'update']);
    Route::post('/prospecting/searches/{search}/run', [ProspectingSearchController::class, 'run'])->middleware('throttle:10,1');

    // Radar de productos
    Route::get('/trends/candidates', [RadarController::class, 'index']);
    Route::get('/trends/candidates/{candidate}', [RadarController::class, 'show']);
    Route::patch('/trends/candidates/{candidate}', [RadarController::class, 'update']);
    Route::post('/trends/candidates/{candidate}/suppliers', [RadarController::class, 'storeSupplier'])->middleware('idempotent');
    Route::post('/trends/signals', [RadarController::class, 'storeSignal'])->middleware('idempotent');
    Route::post('/trends/sync', [RadarController::class, 'sync'])->middleware('throttle:6,1');

    // WhatsApp
    Route::get('/whatsapp/status', [WhatsAppController::class, 'status']);
    Route::get('/whatsapp/conversations', [WhatsAppController::class, 'index']);
    Route::get('/whatsapp/conversations/{conversation}', [WhatsAppController::class, 'show']);
    Route::post('/whatsapp/conversations/{conversation}/messages', [WhatsAppController::class, 'reply'])->middleware('idempotent');
    Route::post('/whatsapp/conversations/{conversation}/template', [WhatsAppController::class, 'template'])->middleware('idempotent');
    Route::post('/whatsapp/conversations/{conversation}/handoff', [WhatsAppController::class, 'handoff']);
    Route::post('/whatsapp/conversations/{conversation}/resume', [WhatsAppController::class, 'resume']);
    Route::post('/whatsapp/conversations/{conversation}/attended', [WhatsAppController::class, 'attended']);
    Route::post('/whatsapp/quotes/{quote}/order', [WhatsAppController::class, 'convertQuote'])->middleware('idempotent');
    Route::post('/whatsapp/quotes/{quote}/reject', [WhatsAppController::class, 'closeQuote']);
    Route::get('/whatsapp/faqs', [WhatsAppController::class, 'faqs']);
    Route::post('/whatsapp/faqs', [WhatsAppController::class, 'storeFaq']);
    Route::patch('/whatsapp/faqs/{faq}', [WhatsAppController::class, 'updateFaq']);
    Route::post('/whatsapp/simulate', [WhatsAppController::class, 'simulate'])->middleware('throttle:60,1');

    // Integraciones
    Route::get('/integrations/status', [IntegrationController::class, 'status']);
    Route::get('/integrations/mercadolibre/authorize', [IntegrationController::class, 'mercadolibreAuthorize']);

    Route::get('/exports/{dataset}', [ExportController::class, 'export'])
        ->whereIn('dataset', ['ventas', 'cobros', 'gastos', 'caja', 'stock']);
});
