<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('business_name')->nullable();
            $table->string('tax_id', 20)->nullable(); // CUIT/CUIL/DNI
            $table->string('phone_e164', 20)->nullable()->unique(); // normalizado con código de país
            $table->string('email')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country', 2)->default('AR');
            $table->string('type', 20)->default('mayorista'); // mayorista | minorista
            $table->string('source')->nullable(); // origen del contacto
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            // borrador (cotización) -> confirmada -> entregada ; cancelada ; devuelta
            $table->string('status', 20)->default('borrador');
            // pendiente | parcial | pagado (se recalcula desde los pagos confirmados)
            $table->string('payment_status', 20)->default('pendiente');
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 18, 6);
            $table->date('exchange_rate_date');
            $table->string('exchange_rate_source')->nullable();
            $table->date('sale_date');
            $table->string('payment_terms', 20)->default('contado'); // contado | credito
            $table->date('due_date')->nullable();
            $table->date('quote_valid_until')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('total_base', 18, 2)->default(0);
            $table->decimal('cost_total_base', 18, 2)->default(0); // congelado al confirmar
            $table->decimal('paid_amount', 18, 2)->default(0);     // en moneda de la venta
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->string('status_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'sale_date']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('description'); // instantánea del nombre al momento de la venta
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            // Costos congelados al confirmar (moneda base).
            $table->decimal('unit_cost_base', 18, 4)->nullable();
            $table->decimal('line_cost_base', 18, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('sale_item_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_unit_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_cost_base', 18, 4)->nullable();
            $table->timestamps();
            $table->unique(['sale_item_id', 'product_unit_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method', 20);
            $table->decimal('amount', 18, 2);             // moneda en que se cobró
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 18, 6);       // -> moneda base
            $table->decimal('amount_base', 18, 2);
            $table->decimal('applied_amount', 18, 2);     // imputado en moneda de la venta
            $table->timestamp('paid_at');
            $table->string('reference')->nullable();       // nro. de transferencia, recibo, etc.
            $table->string('status', 20)->default('confirmado'); // confirmado | anulado
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sale_item_units');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('customers');
    }
};
