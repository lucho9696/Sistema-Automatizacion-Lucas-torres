<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ejecuciones de integraciones externas (tendencias, Places, luego Meta/WhatsApp).
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);          // mercadolibre_trends, google_places...
            $table->string('mode', 10);              // live | demo
            $table->string('status', 20);            // running | success | failed
            $table->jsonb('parameters')->nullable();
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedInteger('request_count')->default(0);
            $table->string('error')->nullable();     // mensaje sanitizado, nunca tokens
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->index(['provider', 'started_at']);
        });

        // Tokens OAuth de integraciones (cifrados con APP_KEY).
        Schema::create('integration_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40)->unique();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('trend_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('normalized_key')->unique();
            // nuevo, investigar, contactar_proveedor, muestra_solicitada, aprobado, descartado, comprado
            $table->string('status', 30)->default('nuevo');
            $table->boolean('is_demo')->default(false);
            // Estimación de margen: solo con precios comparables cargados por el usuario.
            $table->decimal('est_buy_price', 18, 2)->nullable();
            $table->char('est_buy_currency', 3)->nullable();
            $table->decimal('est_landed_extra_pct', 6, 2)->nullable(); // logística + impuestos estimados, %
            $table->decimal('est_sell_price', 18, 2)->nullable();
            $table->char('est_sell_currency', 3)->nullable();
            $table->decimal('est_exchange_rate', 18, 6)->nullable(); // si las monedas difieren
            $table->string('price_evidence')->nullable();            // de dónde salen los precios
            $table->string('competition_level', 10)->nullable();     // baja | media | alta (manual)
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('confidence', 15)->nullable();            // alta | media | baja | demo | insuficiente
            $table->unsignedTinyInteger('signals_count')->default(0);
            $table->jsonb('score_breakdown')->nullable();
            $table->timestamp('scored_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'score']);
        });

        Schema::create('trend_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trend_candidate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sync_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 40);             // mercadolibre_trends | manual | csv
            $table->string('site', 5)->nullable();    // MLA
            $table->string('category_id', 30)->nullable();
            $table->string('keyword');
            $table->string('url')->nullable();
            $table->unsignedSmallInteger('position')->nullable();
            $table->string('trend_type', 20)->nullable(); // crecimiento | deseadas | populares | manual
            $table->boolean('is_demo')->default(false);
            $table->timestamp('fetched_at');
            $table->timestamps();
            $table->index(['trend_candidate_id', 'fetched_at']);
        });

        Schema::create('supplier_research', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trend_candidate_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_name');
            $table->string('url')->nullable();
            $table->decimal('price', 18, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedInteger('moq')->nullable();
            $table->string('shipping_terms')->nullable();
            $table->date('consulted_at');
            $table->boolean('verified')->default(false); // verificado por una persona, no por aparecer en un buscador
            $table->string('risk_note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_research');
        Schema::dropIfExists('trend_snapshots');
        Schema::dropIfExists('trend_candidates');
        Schema::dropIfExists('integration_tokens');
        Schema::dropIfExists('sync_runs');
    }
};
