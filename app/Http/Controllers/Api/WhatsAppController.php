<?php

namespace App\Http\Controllers\Api;

use App\Domain\Messaging\BotQuoteService;
use App\Domain\Messaging\ConversationService;
use App\Domain\Messaging\MessagingProvider;
use App\Domain\Messaging\WhatsAppCloudProvider;
use App\Domain\Shared\Auditor;
use App\Domain\Shared\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookEvent;
use App\Models\BotFaq;
use App\Models\BotQuote;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class WhatsAppController extends Controller
{
    public function __construct(
        private ConversationService $conversations,
        private BotQuoteService $quotes,
        private MessagingProvider $provider,
        private Auditor $auditor,
    ) {}

    public function status()
    {
        Gate::authorize('whatsapp.view');

        return [
            'mode' => $this->provider->mode(),
            'missing' => WhatsAppCloudProvider::missingConfig(),
            'webhook_url' => url('/api/webhooks/whatsapp'),
            'pending_events' => WebhookEvent::where('provider', 'whatsapp')->whereNull('processed_at')->count(),
            'failed_events' => WebhookEvent::where('provider', 'whatsapp')->whereNull('processed_at')->whereNotNull('error')->count(),
            'last_event_at' => WebhookEvent::where('provider', 'whatsapp')->where('simulated', false)->max('received_at'),
            'quote_valid_hours' => config('integrations.whatsapp.quote_valid_hours'),
            'retention_days' => config('integrations.whatsapp.retention_days'),
        ];
    }

    public function index(Request $request)
    {
        Gate::authorize('whatsapp.view');

        return Conversation::query()
            ->with(['customer:id,name', 'lead:id,business_name,status', 'assignee:id,name'])
            ->withCount(['quotes as pending_orders_count' => fn ($q) => $q->where('status', 'aceptada')])
            ->addSelect(['last_body' => Message::select('body')->whereColumn('conversation_id', 'conversations.id')->latest('id')->limit(1)])
            ->when($request->query('filter') === 'atencion', fn ($q) => $q->where('needs_attention', true))
            ->when($request->query('filter') === 'humano', fn ($q) => $q->where('mode', 'humano'))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('profile_name', 'ilike', "%{$t}%")->orWhere('phone_e164', 'like', '%'.preg_replace('/\D/', '', $t).'%')))
            ->orderByDesc('needs_attention')->orderByRaw('last_message_at DESC NULLS LAST')
            ->paginate(min((int) $request->query('per_page', 50), 100));
    }

    public function show(Conversation $conversation)
    {
        Gate::authorize('whatsapp.view');
        $conversation->load(['customer:id,name,phone_e164', 'lead:id,business_name,status', 'assignee:id,name']);

        return [
            'conversation' => $conversation,
            'messages' => $conversation->messages()->with('user:id,name')->orderByDesc('id')->limit(200)->get()->reverse()->values(),
            'quotes' => $conversation->quotes()->with('sale:id,number,status,payment_status')->orderByDesc('id')->limit(20)->get(),
            'base_currency' => config('business.base_currency'),
        ];
    }

    public function reply(Request $request, Conversation $conversation)
    {
        Gate::authorize('whatsapp.reply');
        $data = $request->validate(['body' => ['required', 'string', 'max:4096']]);
        $message = $this->conversations->sendText($conversation, $data['body'], 'operador', $request->user());
        $conversation->update(['needs_attention' => false]);

        return response()->json($message, 201);
    }

    public function template(Request $request, Conversation $conversation)
    {
        Gate::authorize('whatsapp.reply');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['required', 'string', 'max:10'],
            'params' => ['array', 'max:10'],
            'params.*' => ['string', 'max:500'],
        ]);

        return response()->json($this->conversations->sendTemplate($conversation, $data['name'], $data['language'], $data['params'] ?? [], $request->user()), 201);
    }

    public function handoff(Request $request, Conversation $conversation)
    {
        Gate::authorize('whatsapp.reply');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:250']]);

        return $this->conversations->handoff($conversation, $data['reason'] ?? 'Tomada por un operador', $request->user());
    }

    public function resume(Request $request, Conversation $conversation)
    {
        Gate::authorize('whatsapp.reply');

        return $this->conversations->resumeBot($conversation, $request->user());
    }

    public function attended(Conversation $conversation)
    {
        Gate::authorize('whatsapp.reply');
        $conversation->update(['needs_attention' => false]);

        return $conversation;
    }

    public function convertQuote(Request $request, BotQuote $quote)
    {
        Gate::authorize('whatsapp.reply');
        Gate::authorize('sales.create');
        $data = $request->validate([
            'confirm' => ['required', 'boolean'],
            'exchange_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'exchange_rate_source' => ['nullable', 'string', 'max:100'],
            'customer_name' => ['nullable', 'string', 'max:160'],
        ]);
        $quote = $this->quotes->convertToSale($quote, $request->user(), $data['exchange_rate'] ?? null, $data['exchange_rate_source'] ?? null, $data['confirm'], $data['customer_name'] ?? null);

        return response()->json($quote, 201);
    }

    public function closeQuote(Request $request, BotQuote $quote)
    {
        Gate::authorize('whatsapp.reply');
        $data = $request->validate(['reason' => ['required', 'string', 'max:250']]);

        return $this->quotes->close($quote, 'rechazada', $data['reason']);
    }

    public function faqs()
    {
        Gate::authorize('whatsapp.view');

        return BotFaq::orderBy('topic')->get();
    }

    public function storeFaq(Request $request)
    {
        Gate::authorize('whatsapp.manage');
        $faq = BotFaq::create($this->faqData($request) + ['updated_by' => Auth::id()]);
        $this->auditor->record('whatsapp.faq_creada', $faq, null, $faq->only(['topic', 'keywords', 'answer']));

        return response()->json($faq, 201);
    }

    public function updateFaq(Request $request, BotFaq $faq)
    {
        Gate::authorize('whatsapp.manage');
        $data = $this->faqData($request, true);
        $old = $faq->only(array_keys($data));
        $faq->update($data + ['updated_by' => Auth::id()]);
        $this->auditor->record('whatsapp.faq_modificada', $faq, $old, $data);

        return $faq;
    }

    /** Simula un mensaje de un cliente (solo en modo simulado): pasa por el mismo circuito que el webhook real. */
    public function simulate(Request $request)
    {
        Gate::authorize('whatsapp.manage');
        if ($this->provider->mode() !== 'simulado') {
            throw new BusinessRuleException('La simulación solo está disponible con WHATSAPP_MODE=simulado.');
        }
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'name' => ['nullable', 'string', 'max:120'],
            'text' => ['required', 'string', 'max:1000'],
        ]);
        $phone = Phone::normalize($data['phone']);
        if (! $phone) {
            throw new BusinessRuleException('Teléfono inválido.', 'phone');
        }
        $waId = ltrim($phone, '+');
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'SIMULADO', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => 'SIMULADO'],
            'contacts' => [['wa_id' => $waId, 'profile' => ['name' => $data['name'] ?? 'Cliente de prueba']]],
            'messages' => [['from' => $waId, 'id' => 'sim.in.'.Str::uuid(), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $data['text']]]],
        ]]]]]];
        $event = WebhookEvent::create([
            'provider' => 'whatsapp', 'payload_hash' => hash('sha256', json_encode($payload)), 'payload' => $payload,
            'simulated' => true, 'received_at' => now(),
        ]);
        ProcessWebhookEvent::dispatchSync($event->id);

        return response()->json(Conversation::where('wa_id', $waId)->firstOrFail(), 201);
    }

    private function faqData(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'topic' => [$req, 'string', 'max:60'],
            'keywords' => [$req, 'string', 'max:255'],
            'answer' => [$req, 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
