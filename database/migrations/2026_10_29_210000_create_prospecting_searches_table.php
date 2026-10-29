<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Búsquedas de comercios programadas (rubro + ciudad) que alimentan el CRM sin carga manual.
        Schema::create('prospecting_searches', function (Blueprint $table) {
            $table->id();
            $table->string('query', 120);            // rubro: "venta de celulares"
            $table->string('city', 80);
            $table->string('province', 80)->nullable();
            $table->unsignedSmallInteger('frequency_days')->default(7);
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_mode', 10)->nullable();
            $table->unsignedSmallInteger('last_found')->default(0);
            $table->unsignedSmallInteger('last_created')->default(0);
            $table->unsignedSmallInteger('last_skipped')->default(0);
            $table->string('last_error', 300)->nullable();
            $table->jsonb('last_preview')->nullable();   // [{name, outcome}] del último resultado
            $table->unsignedInteger('total_created')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['query', 'city']);
            $table->index(['is_active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospecting_searches');
    }
};
