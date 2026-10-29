<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // Si es true, los productos de la categoría se manejan por unidad (IMEI/serie).
            $table->boolean('is_serialized')->default(false);
            $table->boolean('requires_imei')->default(false);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('brand')->nullable();
            $table->string('name');
            $table->string('sku')->unique();
            $table->text('description')->nullable();
            $table->jsonb('attributes')->nullable();
            $table->boolean('is_serialized')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku')->unique();
            $table->string('name');
            // Atributos flexibles: storage, color, condition, battery_health, carrier_lock, region, warranty...
            $table->jsonb('attributes')->nullable();
            $table->decimal('list_price', 18, 2)->nullable();
            $table->char('price_currency', 3)->nullable();
            $table->unsignedInteger('wholesale_min_qty')->nullable();
            // Saldos mantenidos dentro de transacciones; el libro mayor es inventory_movements.
            $table->integer('stock_on_hand')->default(0);
            $table->integer('stock_reserved')->default(0);
            $table->integer('stock_held')->default(0); // en revisión / devuelto: no vendible
            // Costo promedio ponderado en moneda base (para productos no serializados).
            $table->decimal('avg_cost_base', 18, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
