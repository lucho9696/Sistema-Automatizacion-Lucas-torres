<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { http, postOnce, idempotencyKey, errorMessage } from '../api';
import { can } from '../session';
import { date } from '../format';
import ErrorBox from '../components/ErrorBox.vue';

const props = defineProps<{ id: string }>();
const router = useRouter();

interface Activity { id: number; type: string; occurred_at: string; outcome: string | null; notes: string | null; next_step: string | null; next_step_at: string | null; user: { name: string } | null }
interface Consent { id: number; channel: string; granted: boolean; method: string; evidence: string | null; recorded_at: string }
interface Lead {
    id: number; business_name: string; business_type: string | null; contact_name: string | null; city: string | null; province: string | null; phone_e164: string | null; email: string | null; website: string | null;
    source: string; source_url: string | null; captured_at: string; last_verified_at: string | null; status: string; exclusion_reason: string | null; erased_at: string | null; customer_id: number | null;
    duplicate_of: { id: number; business_name: string } | null; activities: Activity[]; consents: Consent[];
}
interface Detail { lead: Lead; active_consent_channels: string[]; allowed_transitions: string[]; possible_duplicates: { id: number; business_name: string; match: string; strength: string }[] }

const d = ref<Detail | null>(null);
const error = ref<string | null>(null);
const statusForm = reactive({ status: '', note: '', contact_basis: '' });
const act = reactive({ type: 'llamada', outcome: '', notes: '', next_step: '', next_step_at: '' });
const consent = reactive({ channel: 'whatsapp', granted: true, method: 'formulario_web', evidence: '' });
let actKey = idempotencyKey();
let consentKey = idempotencyKey();

async function load() {
    d.value = (await http.get<Detail>(`/api/leads/${props.id}`)).data;
    statusForm.status = d.value.allowed_transitions[0] ?? '';
}
onMounted(load);

async function run(fn: () => Promise<unknown>) {
    error.value = null;
    try {
        await fn();
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

const changeStatus = () => run(() => http.post(`/api/leads/${props.id}/status`, { status: statusForm.status, note: statusForm.note || undefined, contact_basis: statusForm.contact_basis || undefined }));
const addActivity = () => run(async () => {
    await postOnce(`/api/leads/${props.id}/activities`, Object.fromEntries(Object.entries(act).filter(([, v]) => v !== '')), actKey);
    actKey = idempotencyKey();
    Object.assign(act, { outcome: '', notes: '', next_step: '', next_step_at: '' });
});
const addConsent = () => run(async () => {
    await postOnce(`/api/leads/${props.id}/consents`, { ...consent, evidence: consent.evidence || undefined }, consentKey);
    consentKey = idempotencyKey();
});
const merge = (intoId: number) => confirm(`¿Fusionar este prospecto en #${intoId}? Se conserva el registro y su procedencia.`) && run(async () => {
    await http.post(`/api/leads/${props.id}/merge`, { into_id: intoId });
    router.push(`/prospectos/${intoId}`);
});
const convert = () => confirm('¿Crear el cliente a partir de este prospecto?') && run(() => http.post(`/api/leads/${props.id}/convert`));
function erase() {
    const reason = prompt('Supresión de datos personales (no se puede deshacer). Motivo:');
    if (reason) run(() => http.post(`/api/leads/${props.id}/erase`, { reason }));
}
</script>

<template>
    <div v-if="d" class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="mr-auto text-xl font-semibold">{{ d.lead.business_name }} <span class="badge">{{ d.lead.status }}</span></h1>
            <button v-if="!d.lead.customer_id && !['no_contactar', 'descartado'].includes(d.lead.status) && can('customers.manage')" class="btn" @click="convert">Convertir en cliente</button>
            <button v-if="can('crm.erase') && !d.lead.erased_at" class="btn-danger" @click="erase">Suprimir datos</button>
        </div>
        <ErrorBox :message="error" />
        <p v-if="d.lead.duplicate_of" class="text-sm text-amber-800">Fusionado en <RouterLink :to="`/prospectos/${d.lead.duplicate_of.id}`" class="underline">#{{ d.lead.duplicate_of.id }} {{ d.lead.duplicate_of.business_name }}</RouterLink>.</p>
        <p v-if="d.lead.erased_at" class="text-sm text-red-800">Datos personales suprimidos el {{ date(d.lead.erased_at) }}.</p>

        <div class="card grid gap-2 text-sm md:grid-cols-4">
            <div><span class="label">Contacto</span>{{ d.lead.contact_name ?? '—' }}</div>
            <div><span class="label">Teléfono</span>{{ d.lead.phone_e164 ?? '—' }}</div>
            <div><span class="label">Email</span>{{ d.lead.email ?? '—' }}</div>
            <div><span class="label">Web</span>{{ d.lead.website ?? '—' }}</div>
            <div><span class="label">Ciudad</span>{{ d.lead.city }} {{ d.lead.province }}</div>
            <div><span class="label">Fuente</span>{{ d.lead.source }} <a v-if="d.lead.source_url" :href="d.lead.source_url" target="_blank" rel="noopener" class="text-blue-700 underline">ver</a></div>
            <div><span class="label">Capturado</span>{{ date(d.lead.captured_at) }}</div>
            <div><span class="label">Permisos vigentes</span>{{ d.active_consent_channels.join(', ') || 'ninguno' }}</div>
        </div>

        <div v-if="d.possible_duplicates.length" class="card text-sm">
            <h2 class="mb-1 font-semibold">Posibles duplicados</h2>
            <div v-for="p in d.possible_duplicates" :key="p.id" class="flex items-center gap-2">
                <RouterLink :to="`/prospectos/${p.id}`" class="underline">#{{ p.id }} {{ p.business_name }}</RouterLink> <span class="badge">{{ p.match }} · {{ p.strength }}</span>
                <button class="text-xs text-blue-700 hover:underline" @click="merge(p.id)">Fusionar aquí en #{{ p.id }}</button>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <form v-if="d.allowed_transitions.length" class="card space-y-2" @submit.prevent="changeStatus">
                <h2 class="font-semibold">Cambiar estado</h2>
                <select v-model="statusForm.status" class="input"><option v-for="s in d.allowed_transitions" :key="s">{{ s }}</option></select>
                <input v-if="statusForm.status === 'contactado' && !d.active_consent_channels.length" v-model="statusForm.contact_basis" class="input" placeholder="Base del contacto (p. ej. visita al local)" required />
                <input v-model="statusForm.note" class="input" placeholder="Nota / motivo" />
                <button class="btn">Guardar estado</button>
            </form>
            <form v-if="!d.lead.erased_at" class="card space-y-2" @submit.prevent="addConsent">
                <h2 class="font-semibold">Permiso de contacto</h2>
                <div class="grid grid-cols-3 gap-2">
                    <select v-model="consent.channel" class="input"><option>whatsapp</option><option>email</option><option>marketing</option></select>
                    <select v-model="consent.granted" class="input"><option :value="true">Otorga</option><option :value="false">Revoca / baja</option></select>
                    <select v-model="consent.method" class="input"><option>formulario_web</option><option>whatsapp_entrante</option><option>verbal_registrado</option><option>contrato</option><option>otro</option></select>
                </div>
                <input v-model="consent.evidence" class="input" placeholder="Evidencia (nº de formulario, captura, fecha)" />
                <button class="btn">Registrar</button>
                <ul class="text-xs text-slate-600">
                    <li v-for="c in d.lead.consents" :key="c.id">{{ date(c.recorded_at) }} · {{ c.channel }} · {{ c.granted ? 'otorgado' : 'revocado' }} · {{ c.method }}</li>
                </ul>
            </form>
        </div>

        <div class="card space-y-3">
            <h2 class="font-semibold">Actividades</h2>
            <form class="grid gap-2 md:grid-cols-6" @submit.prevent="addActivity">
                <select v-model="act.type" class="input"><option>llamada</option><option>whatsapp</option><option>email</option><option>visita</option><option>reunion</option><option>nota</option></select>
                <input v-model="act.outcome" class="input" placeholder="Resultado" />
                <input v-model="act.notes" class="input md:col-span-2" placeholder="Notas" />
                <input v-model="act.next_step" class="input" placeholder="Siguiente paso" />
                <input v-model="act.next_step_at" type="date" class="input" />
                <button class="btn">Agregar</button>
            </form>
            <table class="table">
                <tbody>
                    <tr v-for="a in d.lead.activities" :key="a.id">
                        <td class="whitespace-nowrap">{{ date(a.occurred_at) }}</td><td><span class="badge">{{ a.type }}</span></td><td>{{ a.outcome }}</td><td>{{ a.notes }}</td>
                        <td class="text-xs">{{ a.next_step }} {{ a.next_step_at ? '(' + date(a.next_step_at) + ')' : '' }}</td><td class="text-xs text-slate-500">{{ a.user?.name }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
