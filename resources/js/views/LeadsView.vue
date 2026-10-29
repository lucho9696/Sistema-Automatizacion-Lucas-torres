<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { http, postOnce, idempotencyKey, errorMessage, type Paginated } from '../api';
import { date } from '../format';
import ErrorBox from '../components/ErrorBox.vue';

interface Lead { id: number; business_name: string; city: string | null; phone_e164: string | null; source: string; status: string; captured_at: string; owner: { name: string } | null }
interface Dup { id: number; business_name: string; match: string; strength: string }
interface ImportResult { dry_run: boolean; rows: number; counts: Record<string, number>; report: { fila: number; result: string; mensaje: string | null; lead_id?: number }[] }

const SOURCES = ['manual', 'visita', 'referido', 'web', 'redes', 'evento', 'csv', 'google_places', 'otro'];
const router = useRouter();
const rows = ref<Lead[]>([]);
const pipeline = ref<{ status: string; count: number }[]>([]);
const filters = reactive({ q: '', status: '' });
const error = ref<string | null>(null);
const warning = ref<Dup[]>([]);
let key = idempotencyKey();
const form = reactive({ business_name: '', business_type: 'tienda_celulares', contact_name: '', city: '', province: '', phone: '', email: '', website: '', source: 'visita', source_url: '', notes: '' });
const importForm = reactive({ source: 'csv', file: null as File | null });
const importResult = ref<ImportResult | null>(null);

async function load() {
    rows.value = (await http.get<Paginated<Lead>>('/api/leads', { params: { q: filters.q || undefined, status: filters.status || undefined } })).data.data;
    pipeline.value = (await http.get('/api/leads/pipeline')).data;
}
onMounted(load);

async function create() {
    error.value = null;
    warning.value = [];
    try {
        const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
        const res = await postOnce<{ lead: Lead; possible_duplicates: Dup[] }>('/api/leads', payload, key);
        key = idempotencyKey();
        warning.value = res.data.possible_duplicates;
        Object.assign(form, { business_name: '', contact_name: '', phone: '', email: '', website: '', source_url: '', notes: '' });
        await load();
    } catch (e) {
        error.value = errorMessage(e);
        key = idempotencyKey();
    }
}

async function runImport(dryRun: boolean) {
    if (!importForm.file) return;
    error.value = null;
    const data = new FormData();
    data.append('file', importForm.file);
    data.append('source', importForm.source);
    data.append('dry_run', dryRun ? '1' : '0');
    try {
        importResult.value = (await http.post<ImportResult>('/api/leads/import', data)).data;
        if (!dryRun) await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="mr-auto text-xl font-semibold">Prospectos mayoristas</h1>
            <a class="btn-secondary" href="/api/leads/outreach-list?channel=whatsapp&format=csv">Lista con permiso WhatsApp (CSV)</a>
        </div>
        <ErrorBox :message="error" />
        <div class="flex flex-wrap gap-1 text-xs">
            <button v-for="p in pipeline" :key="p.status" class="badge" :class="{ 'bg-slate-800 text-white': filters.status === p.status }" @click="filters.status = filters.status === p.status ? '' : p.status; load()">
                {{ p.status }} · {{ p.count }}
            </button>
        </div>

        <form class="card grid gap-2 md:grid-cols-6" @submit.prevent="create">
            <input v-model="form.business_name" class="input md:col-span-2" placeholder="Nombre comercial" required />
            <select v-model="form.business_type" class="input"><option value="tienda_celulares">Tienda de celulares</option><option value="revendedor">Revendedor</option><option value="distribuidor">Distribuidor</option><option value="otro">Otro</option></select>
            <input v-model="form.contact_name" class="input" placeholder="Contacto" />
            <input v-model="form.phone" class="input" placeholder="Teléfono" />
            <input v-model="form.email" class="input" type="email" placeholder="Email" />
            <input v-model="form.city" class="input" placeholder="Ciudad" />
            <input v-model="form.province" class="input" placeholder="Provincia" />
            <input v-model="form.website" class="input" placeholder="Web / perfil" />
            <select v-model="form.source" class="input" required><option v-for="s in SOURCES" :key="s">{{ s }}</option></select>
            <input v-model="form.source_url" class="input" placeholder="URL de origen" />
            <button class="btn">Agregar prospecto</button>
        </form>
        <div v-if="warning.length" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            Posibles duplicados: <span v-for="d in warning" :key="d.id"><RouterLink :to="`/prospectos/${d.id}`" class="underline">#{{ d.id }} {{ d.business_name }}</RouterLink> ({{ d.match }}) </span>
        </div>

        <div class="card space-y-2">
            <h2 class="font-semibold">Importar CSV</h2>
            <p class="text-xs text-slate-500">Columnas: nombre_comercial (obligatoria), tipo, contacto, ciudad, provincia, pais, telefono, email, web, url_fuente, categoria_interes, notas, consentimiento_whatsapp (si/no), metodo_consentimiento, evidencia_consentimiento. Solo listas propias o adquiridas legalmente.</p>
            <div class="flex flex-wrap items-center gap-2">
                <input type="file" accept=".csv,text/csv" class="text-sm" @change="importForm.file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                <select v-model="importForm.source" class="input w-40"><option v-for="s in SOURCES" :key="s">{{ s }}</option></select>
                <button class="btn-secondary" :disabled="!importForm.file" @click="runImport(true)">Probar (no guarda)</button>
                <button class="btn" :disabled="!importForm.file" @click="runImport(false)">Importar</button>
            </div>
            <div v-if="importResult" class="text-sm">
                <p class="font-medium">{{ importResult.dry_run ? 'Prueba' : 'Importación' }}: {{ importResult.rows }} filas · creados {{ importResult.counts.creado }} · duplicados {{ importResult.counts.duplicado }} · suprimidos {{ importResult.counts.suprimido }} · errores {{ importResult.counts.error }}</p>
                <ul class="mt-1 max-h-40 overflow-y-auto text-xs text-slate-600">
                    <li v-for="r in importResult.report.filter((x) => x.result !== 'creado' || x.mensaje)" :key="r.fila">Fila {{ r.fila }}: {{ r.result }} — {{ r.mensaje }}</li>
                </ul>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <div class="mb-2 flex gap-2"><input v-model="filters.q" class="input w-64" placeholder="Buscar nombre, contacto o teléfono" @keyup.enter="load" /><button class="btn-secondary" @click="load">Buscar</button></div>
            <table class="table">
                <thead><tr><th>Comercio</th><th>Ciudad</th><th>Teléfono</th><th>Fuente</th><th>Capturado</th><th>Estado</th><th>Responsable</th></tr></thead>
                <tbody>
                    <tr v-for="l in rows" :key="l.id" class="cursor-pointer hover:bg-slate-50" @click="router.push(`/prospectos/${l.id}`)">
                        <td class="font-medium">{{ l.business_name }}</td><td>{{ l.city }}</td><td>{{ l.phone_e164 }}</td><td>{{ l.source }}</td>
                        <td>{{ date(l.captured_at) }}</td><td><span class="badge" :class="{ 'bg-red-100 text-red-800': l.status === 'no_contactar' }">{{ l.status }}</span></td><td>{{ l.owner?.name }}</td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="7" class="text-slate-500">Sin prospectos.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
