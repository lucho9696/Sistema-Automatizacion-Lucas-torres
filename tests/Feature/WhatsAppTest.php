<?php

namespace Tests\Feature;

use App\Domain\Crm\Suppression;
use App\Domain\Messaging\MessagingProvider;
use App\Domain\Messaging\WhatsAppCloudProvider;
use App\Models\BotFaq;
use App\Models\BotQuote;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsBusinessData;
use Tests\TestCase;

class WhatsAppTest extends TestCase
{
    use BuildsBusinessData, RefreshDatabase;

    private const WA_ID = '5493511234567';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'integrations.whatsapp.app_secret' => 'test-app-secret',
            'integrations.whatsapp.verify_token' => 'verify-me',
            'integrations.whatsapp.phone_number_id' => '1111',
        ]);
        $this->setUpBusiness();
        $this->stockedScenario(); // 3 × iPhone 15 128 (USD 950), 2 × 256 (sin precio), 10 fundas
    }

    private function payload(string $text, ?string $id = null, ?int $ts = null): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => '1111', 'display_phone_number' => '5493510000000'],
            'contacts' => [['wa_id' => self::WA_ID, 'profile' => ['name' => 'Juan Celulares']]],
            'messages' => [['from' => self::WA_ID, 'id' => $id ?? 'wamid.'.Str::random(20), 'timestamp' => (string) ($ts ?? now()->timestamp), 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]];
    }

    private function postWebhook(array|string $payload, ?string $signature = null)
    {
        $raw = is_string($payload) ? $payload : json_encode($payload);
        $signature ??= 'sha256='.hash_hmac('sha256', $raw, 'test-app-secret');

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature], $raw);
    }

    private function say(string $text): string
    {
        $this->postWebhook($this->payload($text))->assertOk();

        return (string) Message::where('direction', 'out')->latest('id')->value('body');
    }

    private function conversation(): Conversation
    {
        return Conversation::where('wa_id', self::WA_ID)->firstOrFail();
    }

    public function test_webhook_verification_and_signature(): void
    {
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345')
            ->assertOk()->assertSee('12345');
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=otro&hub.challenge=12345')->assertForbidden();

        $this->postWebhook($this->payload('hola'), 'sha256=invalida')->assertStatus(401);
        $this->postWebhook($this->payload('hola'), '')->assertStatus(401);
        $this->assertSame(0, Message::count());

        config(['integrations.whatsapp.app_secret' => null]);
        $this->postWebhook($this->payload('hola'), 'sha256=x')->assertStatus(503);
    }

    public function test_duplicate_webhooks_do_not_duplicate_messages_replies_or_quotes(): void
    {
        $payload = $this->payload('5 unidades funda iphone 15', 'wamid.DUP1');
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();                         // reenvío idéntico
        $again = $payload;
        $again['entry'][0]['id'] = 'otro-envoltorio';                     // mismo mensaje en otro cuerpo
        $this->postWebhook($again)->assertOk();

        $this->assertSame(1, Message::where('external_id', 'wamid.DUP1')->count());
        $this->assertSame(1, Message::where('direction', 'out')->count());
        $this->assertSame(0, BotQuote::count()); // la funda no tiene precio: no se cotiza
        $this->assertSame(1, Conversation::count());
    }

    public function test_price_and_stock_come_from_the_database(): void
    {
        $reply = $this->say('Hola, precio del iphone 15 128?');
        $this->assertStringContainsString('USD 950,00', $reply);
        $this->assertStringContainsString('3 disponibles', $reply);
        $out = Message::where('direction', 'out')->latest('id')->first();
        $this->assertSame('precio_stock', $out->intent);
        $this->assertSame($this->ip128->id, $out->sources[0]['id']);
        $this->assertSame('simulado', $out->status);

        // Cambia el precio en el catálogo: la respuesta siguiente lo refleja (no hay precios fijos en el bot).
        $this->ip128->update(['list_price' => '990.50']);
        $this->assertStringContainsString('USD 990,50', $this->say('iphone 15 128'));

        // Variante sin precio cargado: no inventa.
        $this->assertStringContainsString('precio a confirmar', $this->say('iphone 15 256'));
        // Un número de modelo que no existe no se confunde con otro.
        $this->assertStringNotContainsString('USD', $this->say('iphone 13 128'));
    }

    public function test_quote_acceptance_creates_pending_order_without_payment(): void
    {
        $reply = $this->say('quiero 2 iphone 15 128');
        $quote = BotQuote::firstOrFail();
        $this->assertStringContainsString("Cotización #{$quote->id}", $reply);
        $this->assertStringContainsString('USD 1.900,00', $reply);
        $this->assertSame('1900.00', (string) $quote->total);
        $this->assertSame(0, Sale::count());

        $this->assertStringContainsString('pendiente de confirmación', $this->say('Sí, CONFIRMO'));
        $quote->refresh();
        $this->assertSame('aceptada', $quote->status);
        $this->assertTrue($this->conversation()->needs_attention);
        $this->assertSame(0, Sale::count());
        $this->assertSame(3, $this->ip128->fresh()->stock_available); // nada reservado todavía

        // "Ya pagué" no registra cobros.
        $this->say('ya te transferí, está pagado');
        $this->assertSame(0, Payment::count());

        // Una persona valida: venta confirmada con stock reservado y pago pendiente.
        $this->postJson("/api/whatsapp/quotes/{$quote->id}/order", ['confirm' => true, 'exchange_rate' => '1100', 'exchange_rate_source' => 'BNA'],
            ['Idempotency-Key' => 'conv-1'])->assertCreated()->assertJsonPath('status', 'convertida');
        $sale = Sale::firstOrFail();
        $this->assertSame('confirmada', $sale->status);
        $this->assertSame('pendiente', $sale->payment_status);
        $this->assertSame('1900.00', (string) $sale->total);
        $this->assertSame('2090000.00', (string) $sale->total_base);
        $this->assertSame(1, $this->ip128->fresh()->stock_available);
        $this->assertSame('+543511234567', $sale->customer->phone_e164);
        $this->assertSame(0, Payment::count());

        // No se puede convertir dos veces.
        $this->postJson("/api/whatsapp/quotes/{$quote->id}/order", ['confirm' => true, 'exchange_rate' => '1100'])->assertStatus(422);
        $this->assertSame(1, Sale::count());
    }

    public function test_foreign_currency_order_requires_exchange_rate_and_rolls_back(): void
    {
        $this->say('2 unidades iphone 15 128');
        $this->say('confirmo');
        $quote = BotQuote::firstOrFail();
        $this->postJson("/api/whatsapp/quotes/{$quote->id}/order", ['confirm' => true])->assertStatus(422);
        $this->assertSame(0, Sale::count());
        $this->assertSame('aceptada', $quote->fresh()->status);
    }

    public function test_expired_or_rejected_quotes(): void
    {
        $this->say('2 unidades iphone 15 128');
        BotQuote::query()->update(['valid_until' => now()->subMinute()]);
        $this->assertStringContainsString('venció', $this->say('confirmo'));
        $this->assertSame('vencida', BotQuote::first()->status);

        $this->say('1 unidad iphone 15 128');
        $this->say('no confirmo');
        $this->assertSame('rechazada', BotQuote::latest('id')->first()->status);
    }

    public function test_insufficient_stock_and_minimum_quantity(): void
    {
        $this->ip128->update(['wholesale_min_qty' => 2]);
        $this->assertStringContainsString('mínimo mayorista es de 2', $this->say('1 unidad iphone 15 128'));
        $this->assertSame(0, BotQuote::count());

        $reply = $this->say('10 unidades iphone 15 128');
        $this->assertStringContainsString('Hoy hay 3 disponibles', $reply);
        $this->assertSame(0, BotQuote::count());
        $this->assertSame('humano', $this->conversation()->mode);
    }

    public function test_handoff_pauses_bot_until_operator_resumes(): void
    {
        $this->say('quiero hablar con una persona');
        $c = $this->conversation();
        $this->assertSame('humano', $c->mode);
        $this->assertTrue($c->needs_attention);
        $outBefore = Message::where('direction', 'out')->count();

        $this->say('hola? precio iphone 15 128');
        $this->assertSame($outBefore, Message::where('direction', 'out')->count()); // bot en pausa

        $this->postJson("/api/whatsapp/conversations/{$c->id}/messages", ['body' => 'Hola Juan, te ayudo yo.'], ['Idempotency-Key' => 'r1'])
            ->assertCreated()->assertJsonPath('author', 'operador');
        $this->postJson("/api/whatsapp/conversations/{$c->id}/resume")->assertOk()->assertJsonPath('mode', 'bot');
        $this->assertStringContainsString('USD 950,00', $this->say('iphone 15 128'));
    }

    public function test_sensitive_topics_use_faq_or_handoff(): void
    {
        $this->say('qué garantía tienen?');
        $this->assertSame('humano', $this->conversation()->mode);

        Conversation::query()->update(['mode' => 'bot']);
        BotFaq::create(['topic' => 'Garantía', 'keywords' => 'garantía, garantia', 'answer' => 'Los equipos nuevos tienen 12 meses de garantía oficial.']);
        $this->assertSame('Los equipos nuevos tienen 12 meses de garantía oficial.', $this->say('qué garantía tienen?'));
    }

    public function test_opt_out_blocks_templates_and_outreach(): void
    {
        $this->assertStringContainsString('registramos tu baja', $this->say('Por favor denme de BAJA'));
        $c = $this->conversation();
        $this->assertNotNull($c->opted_out_at);
        $this->assertTrue(app(Suppression::class)->isSuppressed('+543511234567', null, 'whatsapp'));

        $this->postJson("/api/whatsapp/conversations/{$c->id}/template", ['name' => 'promo_octubre', 'language' => 'es_AR'], ['Idempotency-Key' => 't1'])
            ->assertStatus(422);

        // Igual se le responde si escribe por una consulta.
        $this->assertStringContainsString('USD 950,00', $this->say('iphone 15 128'));
    }

    public function test_free_text_only_inside_24h_window(): void
    {
        $this->postWebhook($this->payload('hola', null, now()->subHours(25)->timestamp))->assertOk();
        $c = $this->conversation();
        $this->assertFalse($c->window_open);
        $this->postJson("/api/whatsapp/conversations/{$c->id}/messages", ['body' => '¿Seguís interesado?'], ['Idempotency-Key' => 'w1'])
            ->assertStatus(422);
    }

    public function test_order_status_only_for_matching_phone(): void
    {
        $this->assertStringContainsString('No encuentro pedidos', $this->say('estado de mi pedido'));

        $this->customer->update(['phone_e164' => '+543511234567']);
        $this->postJson('/api/sales', [
            'customer_id' => $this->customer->id, 'currency' => 'ARS', 'confirm' => true,
            'items' => [['product_variant_id' => $this->funda->id, 'quantity' => 2, 'unit_price' => '12000']],
        ], ['Idempotency-Key' => 's1'])->assertCreated();
        $reply = $this->say('estado de mi pedido');
        $this->assertMatchesRegularExpression('/V-\d{6}.*confirmado, pago pendiente, saldo ARS 24\.000,00/', $reply);
    }

    public function test_cloud_provider_sends_and_tracks_delivery_status(): void
    {
        config(['integrations.whatsapp.mode' => 'cloud', 'integrations.whatsapp.access_token' => 'EAAG-test-token']);
        $this->app->forgetInstance(MessagingProvider::class);
        $this->assertSame([], WhatsAppCloudProvider::missingConfig());
        $tokenValid = true;
        $sent = 0;
        Http::fake(['graph.facebook.com/*' => function () use (&$tokenValid, &$sent) {
            return $tokenValid
                ? Http::response(['messages' => [['id' => 'wamid.OUT'.(++$sent)]]])
                : Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 401);
        }]);

        $this->say('iphone 15 128');
        Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v23.0/1111/messages'
            && $r->hasHeader('Authorization', 'Bearer EAAG-test-token')
            && $r['to'] === self::WA_ID && str_contains($r['text']['body'], 'USD 950,00'));
        $out = Message::where('external_id', 'wamid.OUT1')->firstOrFail();
        $this->assertSame('enviado', $out->status);

        $status = fn (string $s) => ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'W', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '1111'],
            'statuses' => [['id' => 'wamid.OUT1', 'status' => $s, 'timestamp' => (string) now()->timestamp, 'recipient_id' => self::WA_ID]],
        ]]]]]];
        $this->postWebhook($status('read'))->assertOk();
        $this->postWebhook($status('delivered'))->assertOk(); // llega tarde: no retrocede
        $this->assertSame('leido', $out->fresh()->status);

        // Error de la API: queda registrado sin secretos y la conversación pide atención.
        $tokenValid = false;
        $this->say('iphone 15 128');
        $failed = Message::where('direction', 'out')->latest('id')->first();
        $this->assertSame('fallido', $failed->status);
        $this->assertStringContainsString('token de WhatsApp es inválido', $failed->error);
        $this->assertStringNotContainsString('EAAG', $failed->error);
        $this->assertTrue($this->conversation()->needs_attention);
    }

    public function test_simulator_and_permissions(): void
    {
        $this->postJson('/api/whatsapp/simulate', ['phone' => '0351 15 123-4567', 'name' => 'Prueba', 'text' => 'iphone 15 128'])
            ->assertCreated()->assertJsonPath('wa_id', '543511234567');
        $this->assertStringContainsString('USD 950,00', Message::where('direction', 'out')->latest('id')->value('body'));
        $this->getJson('/api/whatsapp/conversations')->assertOk()->assertJsonPath('data.0.profile_name', 'Prueba');

        Sanctum::actingAs(User::factory()->create(['role' => 'analista']));
        $this->getJson('/api/whatsapp/conversations')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'vendedor']));
        $this->getJson('/api/whatsapp/conversations')->assertOk();
        $this->postJson('/api/whatsapp/simulate', ['phone' => '3511234567', 'text' => 'hola'])->assertForbidden();
        $this->postJson('/api/whatsapp/faqs', ['topic' => 'x', 'keywords' => 'x', 'answer' => 'x'])->assertForbidden();
    }

    public function test_retention_prunes_message_content(): void
    {
        $this->say('iphone 15 128');
        Message::query()->update(['created_at' => now()->subDays(400)]);
        $this->artisan('app:whatsapp-prune', ['--days' => 365])->assertExitCode(0);
        $this->assertSame(0, Message::whereNotNull('body')->count());
        $this->assertSame(2, Message::whereNotNull('redacted_at')->count());
    }
}
