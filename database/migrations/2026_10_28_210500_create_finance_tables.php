<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            // false para costos que no son gasto operativo (no se usa aún, reservado)
            $table->boolean('is_operating')->default(true);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('vendor')->nullable();
            $table->string('description');
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 18, 6);
            $table->decimal('amount_base', 18, 2);
            $table->date('issued_at');                 // devengado
            $table->date('paid_at')->nullable();       // pagado (genera movimiento de caja)
            $table->string('payment_method', 20)->nullable();
            $table->string('status', 20)->default('devengado'); // devengado | pagado | anulado
            // manual | meta_ads | csv
            $table->string('source', 20)->default('manual');
            // Clave externa para impedir doble importación (p.ej. meta:<cuenta>:<campaña>:<fecha>).
            $table->string('external_key')->nullable()->unique();
            $table->string('void_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'issued_at']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->date('occurred_at');
            $table->string('direction', 3); // in | out
            // cobro, anulacion_cobro, gasto, anulacion_gasto, pago_proveedor, apertura, aporte, retiro, ajuste
            $table->string('type', 30);
            $table->decimal('amount', 18, 2); // siempre positivo; el signo lo da direction
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 18, 6);
            $table->decimal('amount_base', 18, 2);
            $table->string('method', 20)->nullable();
            $table->string('reference_type', 80)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('reverses_id')->nullable()->constrained('cash_movements')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->timestamps();
            $table->index(['currency', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
