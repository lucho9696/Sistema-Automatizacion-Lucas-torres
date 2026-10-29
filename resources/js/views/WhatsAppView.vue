<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { http, postOnce, idempotencyKey, errorMessage, type Paginated } from '../api';
import { can } from '../session';
import { money } from '../format';
import ErrorBox from '../components/ErrorBox.vue';

interface Conversation {
    id: number;
    wa_id: string;
    phone_e164: string | null;
    profile_name: string | null;
    mode: 'bot' | 'humano';
    needs_attention: boolean;
    handoff_reason: string | null;
    opted_out_at: string | null;
    last_message_at: string | null;
    window_open: boolean;
    window_expires_at: string | null;
    pending_orders_count?: number;
    last_body?: string | null;
    customer: { id: number; name: string } | null;
    lead: { id: number; business_name: string; status: string } | null;
    assignee: { id: number; name: string } | null;
}
interface Message {
    id: number;
    direction: 'in' | 'out';
    author: 'cliente' | 'bot' | 'operador' | 'sistema';
    type: string;
    body: string | null;
    template_name: string | null;
    intent: string | null;
    status: string;
    error: string | null;
    created_at: string;
    redacted_at: string | null;
    user: { name: string } | null;
}
interface QuoteItem { sku: string; description: string; quantity: number; unit_price: string; line_total: string }
interface Quote {
    id: number;
    status: string;
    currency: string;
    total: string;
    items: QuoteItem[];
    valid_until: string;
    sale: { id: number; number: string; status: string; payment_status: string } | null;
}
interface Detail { conversation: Conversation; messages: Message[]; quotes: Quote[]; base_currency: string }
interface Faq { id: number; topic: string; keywords: string; answer: string; is_active: boolean }
interface WaStatus { mode: string; missing: string[]; webhook_url: string; quote_valid_hours: number; retention_days: number }

const status = ref<WaStatus | null>(null);
const list = ref<Conversation[]>([]);
const filter = ref<'todas' | 'atencion' | 'humano'>('todas');
const detail = ref<Detail | null>(null);
const error = ref<string | null>(null);
const reply = ref('');
const template = ref({ name: '', language: 'es_AR', params: '' });
const sim = ref({ phone: '+54 9 351 555-0000', name: 'Cliente de prueba', text: 'Hola, precio iPhone 15 128?' });
const order = ref<Record<number, { exchange_rate: string; confirm: boolean }>>({});
const faqs = ref<Faq[]>([]);
const faqForm = ref({ topic: '', keywords: '', answer: '' });
const tab = ref<'bandeja' | 'faq'>('bandeja');
let timer: number | undefined;

const selectedId = computed(() => detail.value?.conversation.id ?? null);

async function loadList() {
    const params = filter.value === 'todas' ? {} : { filter: filter.value };
    list.value = (await http.get<Paginated<Conversation>>('/api/whatsapp/conversations', { params })).data.data;
}
async function open(id: number) {
    error.value = null;
    detail.value = (await http.get<Detail>(`/api/whatsapp/conversations/${id}`)).data;
    for (const q of detail.value.quotes) {
        order.value[q.id] ??= { exchange_rate: '', confirm: true };
    }
}
async function refresh() {
    await loadList();
    if (selectedId.value) await open(selectedId.value);
}
async function act(fn: () => Promise<unknown>) {
    error.value = null;
    try {
        await fn();
        await refresh();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

const send = () => act(async () => {
    await postOnce(`/api/whatsapp/conversations/${selectedId.value}/messages`, { body: reply.value }, idempotencyKey());
    reply.value = '';
});
const sendTemplate = () => act(async () => {
    const params = template.value.params.split('|').map((p) => p.trim()).filter(Boolean);
    await postOnce(`/api/whatsapp/conversations/${selectedId.value}/template`, { name: template.value.name, language: template.value.language, params }, idempotencyKey());
});
const take = () => act(() => http.post(`/api/whatsapp/conversations/${selectedId.value}/handoff`, { reason: 'Tomada por un operador' }));
const resume = () => act(() => http.post(`/api/whatsapp/conversations/${selectedId.value}/resume`));
const attended = () => act(() => http.post(`/api/whatsapp/conversations/${selectedId.value}/attended`));
const convert = (q: Quote) => act(() => postOnce(`/api/whatsapp/quotes/${q.id}/order`, {
    confirm: order.value[q.id].confirm,
    exchange_rate: q.currency === detail.value?.base_currency ? undefined : order.value[q.id].exchange_rate || undefined,
    exchange_rate_source: 'manual',
}, idempotencyKey()));
const reject = (q: Quote) => act(() => http.post(`/api/whatsapp/quotes/${q.id}/reject`, { reason: 'Rechazada por el operador' }));

async function simulate() {
    error.value = null;
    try {
        const c = (await http.post<Conversation>('/api/whatsapp/simulate', sim.value)).data;
        sim.value.text = '';
        await loadList();
        await open(c.id);
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function loadFaqs() {
    faqs.value = (await http.get<Faq[]>('/api/whatsapp/faqs')).data;
}
const addFaq = async () => {
    error.value = null;
    try {
        await http.post('/api/whatsapp/faqs', faqForm.value);
        faqForm.value = { topic: '', keywords: '', answer: '' };
        await loadFaqs();
    } catch (e) {
        error.value = errorMessage(e);
    }
};
const toggleFaq = async (f: Faq) => {
    await http.patch(`/api/whatsapp/faqs/${f.id}`, { is_active: !f.is_active });
    await loadFaqs();
};

function time(v: string | null) {
    if (!v) return '';
    return new Date(v).toLocaleString('es-AR', { timeZone: 'America/Argentina/Cordoba', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}
const authorLabel: Record<string, string> = { cliente: 'Cliente', bot: 'Bot', operador: 'Operador', sistema: 'Sistema' };

onMounted(async () => {
    status.value = (await http.get<WaStatus>('/api/whatsapp/status')).data;
    await Promise.all([loadList(), loadFaqs()]);
    // La bandeja se refresca sola cada 15 s (sin websockets en esta etapa).
    timer = window.setInterval(() => { refresh().catch(() => undefined); }, 15000);
});
onUnmounted(() => window.clearInterval(timer));
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold">WhatsApp</h1>
            <span v-if="status?.mode === 'simulado'" class="rounded bg-amber-100 px-2 py-0.5 text-sm text-amber-800">
                Modo simulado: ningún mensaje sale del sistema
            </span>
            <span v-else-if="status" class="rounded bg-emerald-100 px-2 py-0.5 text-sm text-emerald-800">Cloud API: envíos reales</span>
            <div class="ml-auto flex gap-1 text-sm">
                <button :class="tab === 'bandeja' ? 'btn' : 'btn-secondary'" @click="tab = 'bandeja'">Bandeja</button>
                <button :class="tab === 'faq' ? 'btn' : 'btn-secondary'" @click="tab = 'faq'">Respuestas frecuentes</button>
            </div>
        </div>
        <ErrorBox :message="error" />

        <div v-if="tab === 'bandeja'" class="grid gap-4 lg:grid-cols-[320px_1fr]">
            <aside class="space-y-3">
                <div class="flex gap-1 text-sm">
                    <button v-for="f in (['todas', 'atencion', 'humano'] as const)" :key="f" :class="filter === f ? 'btn' : 'btn-secondary'"
                        @click="filter = f; loadList()">{{ f === 'todas' ? 'Todas' : f === 'atencion' ? 'Requieren atención' : 'Con persona' }}</button>
                </div>
                <ul class="divide-y divide-slate-100 rounded border border-slate-200 bg-white">
                    <li v-for="c in list" :key="c.id">
                        <button class="w-full px-3 py-2 text-left text-sm hover:bg-slate-50" :class="selectedId === c.id ? 'bg-slate-100' : ''" @click="open(c.id)">
                            <div class="flex items-center gap-2">
                                <span class="font-medium">{{ c.profile_name || c.phone_e164 }}</span>
                                <span v-if="c.needs_attention" class="rounded bg-red-100 px-1.5 text-xs text-red-700">atender</span>
                                <span v-if="c.mode === 'humano'" class="rounded bg-sky-100 px-1.5 text-xs text-sky-800">persona</span>
                                <span v-if="c.pending_orders_count" class="rounded bg-violet-100 px-1.5 text-xs text-violet-800">pedido por validar</span>
                                <span class="ml-auto text-xs text-slate-500">{{ time(c.last_message_at) }}</span>
                            </div>
                            <p class="truncate text-xs text-slate-500">{{ c.last_body }}</p>
                        </button>
                    </li>
                    <li v-if="!list.length" class="px-3 py-4 text-sm text-slate-500">Sin conversaciones.</li>
                </ul>

                <form v-if="status?.mode === 'simulado' && can('whatsapp.manage')" class="card space-y-2 text-sm" @submit.prevent="simulate">
                    <h2 class="font-semibold">Simular mensaje de un cliente</h2>
                    <p class="text-xs text-slate-500">Pasa por el mismo circuito que el webhook real. Útil para probar el bot.</p>
                    <input v-model="sim.phone" class="input w-full" placeholder="Teléfono" required />
                    <input v-model="sim.name" class="input w-full" placeholder="Nombre" />
                    <textarea v-model="sim.text" class="input w-full" rows="2" placeholder="Mensaje" required />
                    <button class="btn w-full">Enviar como cliente</button>
                </form>
            </aside>

            <section v-if="detail" class="space-y-3">
                <div class="card flex flex-wrap items-center gap-2 text-sm">
                    <div>
                        <div class="font-semibold">{{ detail.conversation.profile_name || 'Sin nombre' }} · {{ detail.conversation.phone_e164 }}</div>
                        <div class="text-xs text-slate-500">
                            <span v-if="detail.conversation.customer">Cliente: {{ detail.conversation.customer.name }} · </span>
                            <RouterLink v-if="detail.conversation.lead" :to="`/prospectos/${detail.conversation.lead.id}`" class="underline">Prospecto: {{ detail.conversation.lead.business_name }}</RouterLink>
                            <span v-if="detail.conversation.opted_out_at" class="ml-1 text-red-700">· Pidió la baja (no se envían plantillas)</span>
                        </div>
                        <div v-if="detail.conversation.mode === 'humano'" class="text-xs text-sky-800">
                            Bot en pausa{{ detail.conversation.handoff_reason ? `: ${detail.conversation.handoff_reason}` : '' }}
                        </div>
                    </div>
                    <div v-if="can('whatsapp.reply')" class="ml-auto flex gap-1">
                        <button v-if="detail.conversation.mode === 'bot'" class="btn-secondary" @click="take">Tomar conversación</button>
                        <button v-else class="btn-secondary" @click="resume">Devolver al bot</button>
                        <button v-if="detail.conversation.needs_attention" class="btn-secondary" @click="attended">Marcar atendida</button>
                    </div>
                </div>

                <div class="card max-h-[55vh] space-y-2 overflow-y-auto text-sm">
                    <div v-for="m in detail.messages" :key="m.id" class="flex" :class="m.direction === 'out' ? 'justify-end' : 'justify-start'">
                        <div class="max-w-[75%] rounded px-3 py-2" :class="m.direction === 'out' ? (m.author === 'bot' ? 'bg-emerald-50' : 'bg-sky-50') : 'bg-slate-100'">
                            <div class="mb-0.5 text-xs text-slate-500">
                                {{ m.author === 'operador' && m.user ? m.user.name : authorLabel[m.author] }} · {{ time(m.created_at) }}
                                <span v-if="m.direction === 'out'"> · {{ m.status }}</span>
                                <span v-if="m.template_name"> · plantilla {{ m.template_name }}</span>
                            </div>
                            <p v-if="m.redacted_at" class="italic text-slate-400">Contenido borrado por la política de retención</p>
                            <p v-else class="whitespace-pre-wrap">{{ m.body }}</p>
                            <p v-if="m.error" class="mt-1 text-xs text-red-700">{{ m.error }}</p>
                        </div>
                    </div>
                </div>

                <div v-if="can('whatsapp.reply')" class="card space-y-2 text-sm">
                    <form v-if="detail.conversation.window_open" class="flex gap-2" @submit.prevent="send">
                        <textarea v-model="reply" class="input flex-1" rows="2" placeholder="Responder como operador…" required />
                        <button class="btn self-end">Enviar</button>
                    </form>
                    <div v-else class="space-y-2">
                        <p class="text-amber-800">Pasaron más de 24 h desde el último mensaje del cliente: WhatsApp solo permite plantillas aprobadas.</p>
                        <form class="flex flex-wrap gap-2" @submit.prevent="sendTemplate">
                            <input v-model="template.name" class="input" placeholder="nombre_plantilla" required />
                            <input v-model="template.language" class="input w-24" placeholder="es_AR" required />
                            <input v-model="template.params" class="input flex-1" placeholder="Variables separadas por |" />
                            <button class="btn">Enviar plantilla</button>
                        </form>
                    </div>
                </div>

                <div v-if="detail.quotes.length" class="card space-y-3 text-sm">
                    <h2 class="font-semibold">Cotizaciones del bot</h2>
                    <div v-for="q in detail.quotes" :key="q.id" class="rounded border border-slate-200 p-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">#{{ q.id }}</span>
                            <span class="rounded bg-slate-100 px-1.5 text-xs">{{ q.status === 'aceptada' ? 'aceptada: pedido por validar' : q.status }}</span>
                            <span>{{ money(q.total, q.currency) }}</span>
                            <span class="text-xs text-slate-500">vence {{ time(q.valid_until) }}</span>
                            <RouterLink v-if="q.sale" :to="`/ventas/${q.sale.id}`" class="ml-auto underline">{{ q.sale.number }} · {{ q.sale.status }} · pago {{ q.sale.payment_status }}</RouterLink>
                        </div>
                        <ul class="mt-1 text-xs text-slate-600">
                            <li v-for="i in q.items" :key="i.sku">{{ i.quantity }} × {{ i.description }} a {{ money(i.unit_price, q.currency) }}</li>
                        </ul>
                        <form v-if="q.status === 'aceptada' && can('sales.create')" class="mt-2 flex flex-wrap items-center gap-2" @submit.prevent="convert(q)">
                            <input v-if="q.currency !== detail.base_currency" v-model="order[q.id].exchange_rate" class="input w-40"
                                :placeholder="`Cotización ${q.currency}→${detail.base_currency}`" required />
                            <label class="flex items-center gap-1"><input v-model="order[q.id].confirm" type="checkbox" /> Confirmar y reservar stock</label>
                            <button class="btn">Crear pedido</button>
                            <button type="button" class="btn-secondary" @click="reject(q)">Rechazar</button>
                            <span class="w-full text-xs text-slate-500">La venta queda con pago pendiente. Los cobros se registran desde la venta.</span>
                        </form>
                    </div>
                </div>
            </section>
            <section v-else class="card text-sm text-slate-500">Elegí una conversación.</section>
        </div>

        <div v-else class="grid gap-4 lg:grid-cols-2">
            <div class="card space-y-2 text-sm">
                <h2 class="font-semibold">Respuestas frecuentes del bot</h2>
                <p class="text-xs text-slate-500">
                    Garantía, envíos, devoluciones y autenticidad solo se responden si hay una respuesta cargada acá; si no, el bot deriva a una persona.
                    Los precios y el stock nunca salen de acá: siempre se consultan en el catálogo.
                </p>
                <ul class="divide-y divide-slate-100">
                    <li v-for="f in faqs" :key="f.id" class="py-2">
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ f.topic }}</span>
                            <span class="text-xs text-slate-500">{{ f.keywords }}</span>
                            <button v-if="can('whatsapp.manage')" class="btn-secondary ml-auto" @click="toggleFaq(f)">{{ f.is_active ? 'Desactivar' : 'Activar' }}</button>
                        </div>
                        <p class="whitespace-pre-wrap text-slate-700" :class="f.is_active ? '' : 'opacity-50'">{{ f.answer }}</p>
                    </li>
                    <li v-if="!faqs.length" class="py-2 text-slate-500">Todavía no hay respuestas cargadas.</li>
                </ul>
            </div>
            <form v-if="can('whatsapp.manage')" class="card space-y-2 text-sm" @submit.prevent="addFaq">
                <h2 class="font-semibold">Nueva respuesta</h2>
                <input v-model="faqForm.topic" class="input w-full" placeholder="Tema (ej. Garantía)" required />
                <input v-model="faqForm.keywords" class="input w-full" placeholder="Palabras clave separadas por coma (ej. garantia, garantía)" required />
                <textarea v-model="faqForm.answer" class="input w-full" rows="4" placeholder="Respuesta exacta que enviará el bot" required />
                <button class="btn">Guardar</button>
            </form>
        </div>
    </div>
</template>
