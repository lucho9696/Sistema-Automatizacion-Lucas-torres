<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\SaleController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

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

    Route::get('/exports/{dataset}', [ExportController::class, 'export'])
        ->whereIn('dataset', ['ventas', 'cobros', 'gastos', 'caja', 'stock']);
});
