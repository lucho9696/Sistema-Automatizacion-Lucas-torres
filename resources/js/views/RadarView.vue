<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { http, postOnce, idempotencyKey, errorMessage, type Paginated } from '../api';
import { can } from '../session';
import { date } from '../format';
import ErrorBox from '../components/ErrorBox.vue';

interface Candidate { id: number; name: string; status: string; is_demo: boolean; score: number | null; confidence: string | null; signals_count: number; last_seen_at: string | null; snapshots_count: number; suppliers_count: number }

const rows = ref<Candidate[]>([]);
const includeDemo = ref(false);
const status = ref('');
const error = ref<string | null>(null);
const info = ref<string | null>(null);
const busy = ref(false);
const signal = reactive({ keyword: '', source: 'tiktok_creative_center', url: '' });
let key = idempotencyKey();

async function load() {
    rows.value = (await http.get<Paginated<Candidate>>('/api/trends/candidates', { params: { include_demo: includeDemo.value ? 1 : 0, status: status.value || undefined } })).data.data;
}
onMounted(load);

function ago(iso: string | null): string {
    if (!iso) return '—';
    const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
    return days <= 0 ? 'hoy' : `hace ${days} día${days > 1 ? 's' : ''}`;
}

async function sync() {
    busy.value = true;
    error.value = info.value = null;
    try {
        const run = (await http.post('/api/trends/sync')).data;
        info.value = `Actualizado (${run.mode === 'demo' ? 'MODO DEMO: datos ficticios' : 'Mercado Libre'}): ${run.items_count} términos.`;
        if (run.mode === 'demo') includeDemo.value = true;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}

async function addSignal() {
    error.value = null;
    try {
        await postOnce('/api/trends/signals', { keyword: signal.keyword, source: signal.source, url: signal.url || undefined }, key);
        key = idempotencyKey();
        signal.keyword = signal.url = '';
        await load();
    } catch (e) {
        error.value = errorMessage(e);
        key = idempotencyKey();
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="mr-auto text-xl font-semibold">Radar de productos</h1>
            <label class="flex items-center gap-1 text-sm"><input v-model="includeDemo" type="checkbox" @change="load" /> Ver datos demo</label>
            <select v-model="status" class="input w-48" @change="load">
                <option value="">Todos los estados</option>
                <option v-for="s in ['nuevo', 'investigar', 'contactar_proveedor', 'muestra_solicitada', 'aprobado', 'descartado', 'comprado']" :key="s">{{ s }}</option>
            </select>
            <button v-if="can('radar.manage')" class="btn" :disabled="busy" @click="sync">Actualizar tendencias</button>
        </div>
        <p class="text-xs text-slate-500">El puntaje ordena candidatos según señales con fuente; no predice ventas. Sin al menos 2 señales no se muestra puntaje.</p>
        <ErrorBox :message="error" />
        <p v-if="info" class="text-sm text-emerald-800">{{ info }}</p>

        <form v-if="can('radar.manage')" class="card grid gap-2 md:grid-cols-5" @submit.prevent="addSignal">
            <input v-model="signal.keyword" class="input md:col-span-2" placeholder="Producto observado (señal manual)" required />
            <select v-model="signal.source" class="input"><option value="tiktok_creative_center">TikTok Creative Center</option><option value="google_trends">Google Trends (web)</option><option value="marketplace">Marketplace</option><option value="cliente">Pedido de clientes</option><option value="otro">Otro</option></select>
            <input v-model="signal.url" class="input" placeholder="URL de la evidencia" />
            <button class="btn">Agregar señal</button>
        </form>

        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Producto</th><th>Puntaje</th><th>Confianza</th><th>Señales</th><th>Evidencias</th><th>Proveedores</th><th>Actualizado</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="c in rows" :key="c.id">
                        <td><RouterLink :to="`/radar/${c.id}`" class="text-blue-700 hover:underline">{{ c.name }}</RouterLink> <span v-if="c.is_demo" class="badge bg-amber-100 text-amber-900">DEMO</span></td>
                        <td class="font-semibold">{{ c.score !== null ? `${c.score}/100` : 'Datos insuficientes' }}</td>
                        <td>{{ c.confidence }}</td><td>{{ c.signals_count }}</td><td>{{ c.snapshots_count }}</td><td>{{ c.suppliers_count }}</td>
                        <td :title="date(c.last_seen_at)">{{ ago(c.last_seen_at) }}</td><td><span class="badge">{{ c.status }}</span></td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="8" class="text-slate-500">Sin candidatos. Actualice tendencias o cargue una señal manual.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
