<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { http, postOnce, idempotencyKey, errorMessage } from '../api';
import { can } from '../session';
import { date } from '../format';
import ErrorBox from '../components/ErrorBox.vue';

interface Run { status: string; mode: string; items_count: number; error: string | null; started_at: string }
interface Status {
    mercadolibre_trends: { mode: string; connected: boolean; missing_env: string[]; last_run: Run | null; last_success_at: string | null };
    google_places: { mode: string; missing: string[]; monthly_limit: number; used_this_month: number; field_mask: string; last_run: Run | null };
    whatsapp: { mode: string; missing: string[]; webhook_url: string; last_event_at: string | null; failed_events: number };
    meta_ads: { mode: string; note: string };
}
interface Place { external_id: string; business_name: string; address: string | null; phone: string | null; website: string | null; source_url: string | null; lead_id: number | null }

const route = useRoute();
const s = ref<Status | null>(null);
const error = ref<string | null>(null);
const query = ref('');
const places = ref<{ mode: string; results: Place[] } | null>(null);

async function load() {
    s.value = (await http.get<Status>('/api/integrations/status')).data;
}
onMounted(load);

async function connectMl() {
    try {
        window.location.href = (await http.get<{ url: string }>('/api/integrations/mercadolibre/authorize')).data.url;
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function search() {
    error.value = null;
    try {
        places.value = (await http.post('/api/prospecting/places/search', { query: query.value })).data;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function addLead(p: Place) {
    try {
        const res = await postOnce<{ lead: { id: number } }>('/api/leads', {
            business_name: p.business_name, phone: p.phone ?? undefined, website: p.website ?? undefined,
            source: 'google_places', external_id: p.external_id, source_url: p.source_url ?? undefined, notes: p.address ?? undefined,
        }, idempotencyKey());
        p.lead_id = res.data.lead.id;
    } catch (e) {
        error.value = errorMessage(e);
    }
}
</script>

<template>
    <div v-if="s" class="space-y-4">
        <h1 class="text-xl font-semibold">Integraciones</h1>
        <ErrorBox :message="error" />
        <p v-if="route.query.ml === 'ok'" class="text-sm text-emerald-800">Mercado Libre autorizado.</p>
        <p v-if="route.query.ml === 'error'" class="text-sm text-red-800">No se pudo autorizar Mercado Libre. Reintente.</p>

        <div class="grid gap-3 md:grid-cols-2">
            <div class="card text-sm">
                <h2 class="font-semibold">Mercado Libre · Tendencias</h2>
                <p>Modo: <span class="badge" :class="{ 'bg-amber-100 text-amber-900': s.mercadolibre_trends.mode === 'demo' }">{{ s.mercadolibre_trends.mode === 'demo' ? 'DEMO (datos ficticios)' : 'real' }}</span>
                    <span v-if="s.mercadolibre_trends.mode === 'live'"> · {{ s.mercadolibre_trends.connected ? 'autorizado' : 'sin autorizar' }}</span></p>
                <p v-if="s.mercadolibre_trends.missing_env.length" class="text-red-800">Faltan variables: {{ s.mercadolibre_trends.missing_env.join(', ') }}</p>
                <p v-if="s.mercadolibre_trends.mode === 'demo'" class="text-xs text-slate-500">Para datos reales: TRENDS_PROVIDER=mercadolibre, ML_CLIENT_ID, ML_CLIENT_SECRET, ML_REDIRECT_URI y autorizar.</p>
                <p v-if="s.mercadolibre_trends.last_run">Última ejecución: {{ date(s.mercadolibre_trends.last_run.started_at) }} · {{ s.mercadolibre_trends.last_run.status }} · {{ s.mercadolibre_trends.last_run.items_count }} términos
                    <span v-if="s.mercadolibre_trends.last_run.error" class="text-red-800">· {{ s.mercadolibre_trends.last_run.error }}</span></p>
                <button v-if="s.mercadolibre_trends.mode === 'live' && !s.mercadolibre_trends.missing_env.length && can('integrations.manage')" class="btn mt-2" @click="connectMl">
                    {{ s.mercadolibre_trends.connected ? 'Reautorizar' : 'Autorizar con Mercado Libre' }}
                </button>
            </div>
            <div class="card text-sm">
                <h2 class="font-semibold">Google Places (opcional, pago)</h2>
                <p>Modo: <span class="badge">{{ s.google_places.mode }}</span> · Consultas este mes: {{ s.google_places.used_this_month }} / {{ s.google_places.monthly_limit || 'sin tope (bloqueado)' }}</p>
                <p v-if="s.google_places.missing.length" class="text-red-800">Faltan: {{ s.google_places.missing.join(', ') }}</p>
                <p class="text-xs text-slate-500">Campos pedidos (FieldMask): {{ s.google_places.field_mask }}</p>
            </div>
            <div class="card space-y-1 text-sm">
                <h2 class="font-semibold">WhatsApp Cloud API</h2>
                <p v-if="s.whatsapp.mode === 'simulado'">
                    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-800">Modo simulado</span>
                    Nada sale del sistema; probá el bot desde <RouterLink to="/whatsapp" class="underline">WhatsApp</RouterLink>.
                </p>
                <p v-else>Modo Cloud API (envíos reales).</p>
                <p v-if="s.whatsapp.missing.length" class="text-slate-600">Faltan para conectar: {{ s.whatsapp.missing.join(', ') }}</p>
                <p class="text-slate-600">URL del webhook: <code class="break-all">{{ s.whatsapp.webhook_url }}</code></p>
                <p class="text-slate-600">Último evento real: {{ s.whatsapp.last_event_at ? date(s.whatsapp.last_event_at) : 'ninguno' }}</p>
                <p v-if="s.whatsapp.failed_events" class="text-red-700">{{ s.whatsapp.failed_events }} evento(s) del webhook fallaron (ver logs).</p>
            </div>
            <div class="card text-sm"><h2 class="font-semibold">Meta Ads</h2><p>No conectado · {{ s.meta_ads.note }}</p></div>
        </div>

        <div v-if="can('prospecting.places') && s.google_places.mode !== 'off'" class="card space-y-2">
            <h2 class="font-semibold">Buscar comercios (Google Places{{ s.google_places.mode === 'demo' ? ' · DEMO' : '' }})</h2>
            <form class="flex gap-2" @submit.prevent="search"><input v-model="query" class="input" placeholder="p. ej. venta de celulares en Córdoba" minlength="3" required /><button class="btn">Buscar</button></form>
            <p class="text-xs text-slate-500">Cada búsqueda real consume la cuota paga. Los resultados no se guardan solos: elija cuáles agregar como prospecto (queda la fuente y el place_id).</p>
            <table v-if="places" class="table text-sm">
                <tbody>
                    <tr v-for="p in places.results" :key="p.external_id">
                        <td>{{ p.business_name }}</td><td>{{ p.address }}</td><td>{{ p.phone }}</td>
                        <td><RouterLink v-if="p.lead_id" :to="`/prospectos/${p.lead_id}`" class="underline">ver prospecto</RouterLink><button v-else-if="places.mode !== 'demo'" class="text-blue-700 hover:underline" @click="addLead(p)">Agregar</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
