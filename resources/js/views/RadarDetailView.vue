<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { http, postOnce, idempotencyKey, errorMessage } from '../api';
import { session, can } from '../session';
import { date, money, today } from '../format';
import ErrorBox from '../components/ErrorBox.vue';

const props = defineProps<{ id: string }>();

interface Factor { key: string; label: string; weight: number; available: boolean; value: number | null; note: string }
interface Candidate {
    id: number; name: string; status: string; is_demo: boolean; score: number | null; confidence: string | null; signals_count: number; scored_at: string | null; last_seen_at: string | null;
    est_buy_price: string | null; est_buy_currency: string | null; est_landed_extra_pct: string | null; est_sell_price: string | null; est_sell_currency: string | null; est_exchange_rate: string | null;
    price_evidence: string | null; competition_level: string | null; notes: string | null;
    score_breakdown: { factors: Factor[]; missing: string[]; method: string } | null;
    snapshots: { id: number; source: string; keyword: string; url: string | null; position: number | null; trend_type: string | null; is_demo: boolean; fetched_at: string }[];
    suppliers: { id: number; supplier_name: string; url: string | null; price: string | null; currency: string | null; moq: number | null; shipping_terms: string | null; consulted_at: string; verified: boolean; risk_note: string | null }[];
}
interface Detail { candidate: Candidate; supplier_links: { label: string; url: string }[]; phone_supplier_checklist: string[] }

const d = ref<Detail | null>(null);
const error = ref<string | null>(null);
const est = reactive<Record<string, string | null>>({});
const sup = reactive({ supplier_name: '', url: '', price: '', currency: 'USD', moq: '', shipping_terms: '', consulted_at: today(), verified: false, risk_note: '' });
let supKey = idempotencyKey();

async function load() {
    d.value = (await http.get<Detail>(`/api/trends/candidates/${props.id}`)).data;
    const c = d.value.candidate;
    Object.assign(est, {
        status: c.status, est_buy_price: c.est_buy_price, est_buy_currency: c.est_buy_currency ?? 'USD', est_landed_extra_pct: c.est_landed_extra_pct,
        est_sell_price: c.est_sell_price, est_sell_currency: c.est_sell_currency ?? 'ARS', est_exchange_rate: c.est_exchange_rate,
        price_evidence: c.price_evidence, competition_level: c.competition_level, notes: c.notes,
    });
}
onMounted(load);

async function save() {
    error.value = null;
    try {
        await http.patch(`/api/trends/candidates/${props.id}`, Object.fromEntries(Object.entries(est).map(([k, v]) => [k, v === '' ? null : v])));
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function addSupplier() {
    error.value = null;
    try {
        await postOnce(`/api/trends/candidates/${props.id}/suppliers`, Object.fromEntries(Object.entries(sup).filter(([, v]) => v !== '')), supKey);
        supKey = idempotencyKey();
        Object.assign(sup, { supplier_name: '', url: '', price: '', moq: '', shipping_terms: '', verified: false, risk_note: '' });
        await load();
    } catch (e) {
        error.value = errorMessage(e);
        supKey = idempotencyKey();
    }
}
</script>

<template>
    <div v-if="d" class="space-y-4">
        <h1 class="text-xl font-semibold">{{ d.candidate.name }} <span v-if="d.candidate.is_demo" class="badge bg-amber-100 text-amber-900">DEMO · datos ficticios</span></h1>
        <ErrorBox :message="error" />
        <div class="card">
            <p class="text-lg font-semibold">
                {{ d.candidate.score !== null ? `Puntaje ${d.candidate.score}/100` : 'Datos insuficientes para puntuar' }}
                · Confianza {{ d.candidate.confidence }} · Basado en {{ d.candidate.signals_count }} señales · Actualizado {{ date(d.candidate.last_seen_at) }}
            </p>
            <p v-if="d.candidate.score_breakdown?.missing.length" class="mt-1 text-sm text-amber-800">Faltan: {{ d.candidate.score_breakdown.missing.join(', ') }} (se excluyen del cálculo y bajan la confianza).</p>
            <table v-if="d.candidate.score_breakdown" class="table mt-2">
                <thead><tr><th>Factor</th><th>Peso</th><th>Valor</th><th>Detalle</th></tr></thead>
                <tbody>
                    <tr v-for="f in d.candidate.score_breakdown.factors" :key="f.key" :class="{ 'text-slate-400': !f.available }">
                        <td>{{ f.label }}</td><td>{{ f.weight }}%</td><td>{{ f.available ? Math.round((f.value ?? 0) * 100) + '%' : 'sin dato' }}</td><td class="text-xs">{{ f.note }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-1 text-xs text-slate-500">{{ d.candidate.score_breakdown?.method }}</p>
        </div>

        <form v-if="can('radar.manage')" class="card grid gap-2 md:grid-cols-4" @submit.prevent="save">
            <h2 class="font-semibold md:col-span-4">Estado, margen estimado y competencia</h2>
            <label class="text-xs">Estado<select v-model="est.status" class="input"><option v-for="s in ['nuevo', 'investigar', 'contactar_proveedor', 'muestra_solicitada', 'aprobado', 'descartado', 'comprado']" :key="s">{{ s }}</option></select></label>
            <label class="text-xs">Costo de compra<input v-model="est.est_buy_price" class="input" inputmode="decimal" /></label>
            <label class="text-xs">Moneda compra<select v-model="est.est_buy_currency" class="input"><option v-for="c in session.me?.settings.currencies" :key="c">{{ c }}</option></select></label>
            <label class="text-xs">% logística + impuestos<input v-model="est.est_landed_extra_pct" class="input" inputmode="decimal" /></label>
            <label class="text-xs">Precio de venta comparable<input v-model="est.est_sell_price" class="input" inputmode="decimal" /></label>
            <label class="text-xs">Moneda venta<select v-model="est.est_sell_currency" class="input"><option v-for="c in session.me?.settings.currencies" :key="c">{{ c }}</option></select></label>
            <label v-if="est.est_buy_currency !== est.est_sell_currency" class="text-xs">Cotización (1 {{ est.est_buy_currency }} en {{ est.est_sell_currency }})<input v-model="est.est_exchange_rate" class="input" inputmode="decimal" /></label>
            <label class="text-xs">Competencia observada<select v-model="est.competition_level" class="input"><option :value="null">sin evaluar</option><option>baja</option><option>media</option><option>alta</option></select></label>
            <label class="text-xs md:col-span-2">Fuente de los precios<input v-model="est.price_evidence" class="input" placeholder="p. ej. cotización proveedor + publicaciones ML del 29/10" /></label>
            <label class="text-xs md:col-span-2">Notas<input v-model="est.notes" class="input" /></label>
            <button class="btn md:col-span-4 md:w-40">Guardar y recalcular</button>
        </form>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="card">
                <h2 class="mb-2 font-semibold">Buscar proveedores</h2>
                <p class="mb-2 text-xs text-slate-500">Atajos de búsqueda: aparecer en un buscador no valida a un proveedor ni la autenticidad del producto.</p>
                <ul class="space-y-1 text-sm"><li v-for="l in d.supplier_links" :key="l.label"><a :href="l.url" target="_blank" rel="noopener noreferrer" class="text-blue-700 underline">{{ l.label }}</a></li></ul>
                <h3 class="mt-3 text-sm font-semibold">Checklist para celulares</h3>
                <ul class="list-disc pl-5 text-xs text-slate-600"><li v-for="i in d.phone_supplier_checklist" :key="i">{{ i }}</li></ul>
            </div>
            <div class="card">
                <h2 class="mb-2 font-semibold">Evidencia ({{ d.candidate.snapshots.length }})</h2>
                <table class="table text-xs">
                    <tbody>
                        <tr v-for="s in d.candidate.snapshots" :key="s.id">
                            <td>{{ date(s.fetched_at) }}</td><td>{{ s.source }}<span v-if="s.is_demo" class="badge ml-1 bg-amber-100">DEMO</span></td><td>{{ s.trend_type }}</td><td>{{ s.position ? '#' + s.position : '' }}</td>
                            <td><a v-if="s.url" :href="s.url" target="_blank" rel="noopener noreferrer" class="text-blue-700 underline">{{ s.keyword }}</a><span v-else>{{ s.keyword }}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card space-y-2">
            <h2 class="font-semibold">Proveedores relevados</h2>
            <form v-if="can('radar.manage')" class="grid gap-2 md:grid-cols-6" @submit.prevent="addSupplier">
                <input v-model="sup.supplier_name" class="input" placeholder="Proveedor" required />
                <input v-model="sup.url" class="input" placeholder="URL exacta" />
                <input v-model="sup.price" class="input" inputmode="decimal" placeholder="Precio" />
                <select v-model="sup.currency" class="input"><option v-for="c in session.me?.settings.currencies" :key="c">{{ c }}</option></select>
                <input v-model="sup.moq" type="number" min="1" class="input" placeholder="MOQ" />
                <input v-model="sup.consulted_at" type="date" class="input" required />
                <input v-model="sup.shipping_terms" class="input md:col-span-2" placeholder="Condiciones de envío" />
                <input v-model="sup.risk_note" class="input md:col-span-2" placeholder="Nota de riesgo" />
                <label class="flex items-center gap-1 text-xs"><input v-model="sup.verified" type="checkbox" /> Verificado por una persona</label>
                <button class="btn">Agregar</button>
            </form>
            <table class="table text-sm">
                <tbody>
                    <tr v-for="s in d.candidate.suppliers" :key="s.id">
                        <td><a v-if="s.url" :href="s.url" target="_blank" rel="noopener noreferrer" class="underline">{{ s.supplier_name }}</a><span v-else>{{ s.supplier_name }}</span></td>
                        <td>{{ s.price ? money(s.price, s.currency ?? '') : '—' }}</td><td>MOQ {{ s.moq ?? '—' }}</td><td>{{ s.shipping_terms }}</td><td>{{ date(s.consulted_at) }}</td>
                        <td>{{ s.verified ? 'verificado' : 'sin verificar' }}</td><td class="text-xs">{{ s.risk_note }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
