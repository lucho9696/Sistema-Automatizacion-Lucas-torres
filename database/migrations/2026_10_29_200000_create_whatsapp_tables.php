<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada POST recibido en el webhook. El hash del cuerpo evita reprocesar reenvíos idénticos.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->char('payload_hash', 64);
            $table->jsonb('payload');
            $table->boolean('simulated')->default(false);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['provider', 'payload_hash']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20)->default('whatsapp');
            $table->string('wa_id', 20);                 // identificador que envía WhatsApp (se usa para responder)
            $table->string('phone_e164', 20)->nullable(); // normalizado para cruzar con clientes y prospectos
            $table->string('profile_name')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 10)->default('bot');  // bot | humano
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('handoff_reason')->nullable();
            $table->timestamp('handoff_at')->nullable();
            $table->boolean('needs_attention')->default(false);
            $table->unsignedSmallInteger('misunderstood_count')->default(0);
            $table->timestamp('last_inbound_at')->nullable(); // abre la ventana de 24 h
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'wa_id']);
            $table->index(['needs_attention', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3);              // in | out
            $table->string('author', 10);                // cliente | bot | operador | sistema
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->nullable()->unique(); // wamid.* (deduplicación)
            $table->string('type', 20)->default('text'); // text | template | interactive | otro
            $table->text('body')->nullable();
            $table->string('template_name')->nullable();
            $table->string('intent', 30)->nullable();
            // Datos internos consultados para armar la respuesta (precio/stock con fecha).
            $table->jsonb('sources')->nullable();
            // recibido | simulado | enviado | entregado | leido | fallido
            $table->string('status', 15);
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('processed_at')->nullable(); // entrante ya atendido por el bot
            $table->timestamp('redacted_at')->nullable();  // cuerpo borrado por política de retención
            $table->timestamps();
            $table->index(['conversation_id', 'id']);
        });

        Schema::create('bot_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_message_id')->unique()->constrained('messages')->restrictOnDelete();
            $table->char('currency', 3);
            $table->decimal('total', 18, 2);
            $table->jsonb('items'); // [{product_variant_id, sku, description, quantity, unit_price, line_total, stock_available}]
            $table->timestamp('valid_until');
            // enviada -> aceptada (pedido pendiente de confirmación) -> convertida (venta) ; rechazada ; vencida
            $table->string('status', 15)->default('enviada');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'valid_until']);
        });

        Schema::create('bot_faqs', function (Blueprint $table) {
            $table->id();
            $table->string('topic', 60);
            $table->string('keywords');   // separadas por coma; se comparan sin acentos
            $table->text('answer');
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_faqs');
        Schema::dropIfExists('bot_quotes');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('webhook_events');
    }
};
