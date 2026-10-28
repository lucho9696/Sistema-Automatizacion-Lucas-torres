<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('imei', 20)->nullable()->unique();
            $table->string('imei2', 20)->nullable()->unique();
            $table->string('serial_number', 60)->nullable()->unique();
            $table->string('condition', 30)->nullable(); // nuevo | usado | reacondicionado
            $table->unsignedTinyInteger('battery_health')->nullable();
            $table->string('location')->nullable();
            // en_transito, en_revision, disponible, reservado, vendido, devuelto, en_garantia, perdido
            $table->string('status', 20)->default('disponible');
            $table->decimal('unit_cost_base', 18, 4);
            $table->text('inspection_notes')->nullable();
            $table->timestamps();
            $table->index(['product_variant_id', 'status']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_unit_id')->nullable()->constrained()->restrictOnDelete();
            // entrada, reserva, liberacion, salida, devolucion, retencion, liberacion_retencion, ajuste
            $table->string('type', 30);
            // Variaciones aplicadas a los saldos de la variante (pueden ser negativas).
            $table->integer('on_hand_delta')->default(0);
            $table->integer('reserved_delta')->default(0);
            $table->integer('held_delta')->default(0);
            $table->decimal('unit_cost_base', 18, 4)->nullable();
            $table->string('reference_type', 80)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['reference_type', 'reference_id']);
        });

        // Cinturón y tiradores: la base rechaza saldos imposibles aunque falle la lógica de aplicación.
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT chk_reserved_non_negative CHECK (stock_reserved >= 0)');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT chk_held_non_negative CHECK (stock_held >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product_variants DROP CONSTRAINT IF EXISTS chk_reserved_non_negative');
        DB::statement('ALTER TABLE product_variants DROP CONSTRAINT IF EXISTS chk_held_non_negative');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('product_units');
    }
};
