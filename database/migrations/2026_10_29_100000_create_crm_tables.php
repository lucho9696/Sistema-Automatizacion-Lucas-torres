<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('filename');
            $table->string('source', 40);
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('suppressed_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->jsonb('report')->nullable(); // detalle por fila (sin datos personales completos)
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('business_name');
            $table->string('business_type', 60)->nullable(); // tienda_celulares, revendedor, distribuidor...
            $table->string('contact_name')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country', 2)->default('AR');
            $table->string('phone_e164', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('domain')->nullable();          // dominio normalizado para dedupe
            $table->string('name_key')->nullable();        // nombre comercial normalizado
            // Procedencia (obligatoria): manual, csv, google_places, referido, visita, web, redes
            $table->string('source', 40);
            $table->string('source_url')->nullable();
            $table->string('external_id')->nullable();     // p.ej. place_id de Google
            $table->timestamp('captured_at');
            $table->timestamp('last_verified_at')->nullable();
            $table->string('interest_category')->nullable();
            $table->string('estimated_volume')->nullable(); // manual, nunca inferido
            $table->string('status', 30)->default('nuevo');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('lead_import_id')->nullable()->constrained()->nullOnDelete();
            $table->string('exclusion_reason')->nullable();
            $table->timestamp('erased_at')->nullable();    // datos personales suprimidos (Ley 25.326)
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('phone_e164');
            $table->index('domain');
            $table->index(['name_key', 'city']);
            $table->index('status');
            $table->unique(['source', 'external_id']);
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // llamada, whatsapp, email, visita, nota, cambio_estado, fusion, importacion
            $table->string('type', 30);
            $table->timestamp('occurred_at');
            $table->string('outcome')->nullable();
            $table->string('next_step')->nullable();
            $table->date('next_step_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Registro inmutable de permisos de contacto (se agrega uno nuevo para revocar).
        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20);   // whatsapp | email | marketing
            $table->boolean('granted');
            // formulario_web, whatsapp_entrante, verbal_registrado, contrato, otro
            $table->string('method', 40);
            $table->string('evidence')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['lead_id', 'channel']);
        });

        // Lista de supresión: guarda solo el hash del identificador normalizado.
        Schema::create('suppression_list', function (Blueprint $table) {
            $table->id();
            $table->string('identifier_type', 10); // phone | email
            $table->char('identifier_hash', 64);
            $table->string('scope', 20)->default('global'); // global | whatsapp | marketing
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['identifier_type', 'identifier_hash', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppression_list');
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lead_imports');
    }
};
