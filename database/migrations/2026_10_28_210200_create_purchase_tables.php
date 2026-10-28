<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('country', 2)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->nullable(); // factura / orden de compra del proveedor
            $table->date('purchase_date');
            $table->char('currency', 3);
            // Cotización moneda de la compra -> moneda base, con fecha y fuente.
            $table->decimal('exchange_rate', 18, 6);
            $table->date('exchange_rate_date');
            $table->string('exchange_rate_source')->nullable();
            // draft -> received | cancelled
            $table->string('status', 20)->default('draft');
            $table->string('allocation_method', 20)->default('value');
            $table->decimal('goods_total', 18, 2)->default(0);       // moneda de la compra, neto de descuentos
            $table->decimal('goods_total_base', 18, 2)->default(0);
            $table->decimal('extra_costs_base', 18, 2)->default(0);
            $table->decimal('landed_total_base', 18, 2)->default(0);
            // Detalle completo del cálculo para auditoría.
            $table->jsonb('allocation_snapshot')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 18, 4);          // moneda de la compra
            $table->decimal('discount', 18, 2)->default(0); // descuento total de la línea
            $table->decimal('line_total', 18, 2);          // quantity*unit_cost - discount
            $table->decimal('line_total_base', 18, 2)->default(0);
            $table->decimal('allocated_costs_base', 18, 2)->default(0);
            $table->decimal('landed_line_total_base', 18, 2)->default(0);
            $table->unsignedInteger('quantity_received')->default(0);
            $table->unsignedInteger('quantity_damaged')->default(0);
            $table->unsignedInteger('quantity_missing')->default(0);
            $table->decimal('landed_unit_cost_base', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('purchase_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // flete, seguro, aranceles, impuestos, despacho, traslado, otro
            $table->string('description')->nullable();
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 18, 6);
            $table->decimal('amount_base', 18, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_costs');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
