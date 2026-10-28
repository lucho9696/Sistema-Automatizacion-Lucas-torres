<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { http, postOnce, idempotencyKey, errorMessage, type Paginated } from '../api';
import { session } from '../session';
import { money, date, today } from '../format';
import ErrorBox from '../components/ErrorBox.vue';

interface Movement { id: number; occurred_at: string; direction: 'in' | 'out'; type: string; amount: string; currency: string; description: string | null; method: string | null }
interface Cash { balances: Record<string, { opening: string; in: string; out: string; closing: string }>; movements: Paginated<Movement> }

const from = ref(today().slice(0, 8) + '01');
const to = ref(today());
const data = ref<Cash | null>(null);
const error = ref<string | null>(null);
let key = idempotencyKey();
const form = reactive({ type: 'apertura', amount: '', currency: session.me?.settings.base_currency ?? 'ARS', exchange_rate: '', occurred_at: today(), method: 'efectivo', description: '' });

async function load() {
    data.value = (await http.get<Cash>('/api/cash', { params: { from: from.value, to: to.value } })).data;
}
onMounted(load);

async function submit() {
    error.value = null;
    try {
        await postOnce('/api/cash', { ...form, exchange_rate: form.currency === session.me?.settings.base_currency ? undefined : form.exchange_rate }, key);
        key = idempotencyKey();
        form.amount = form.description = '';
        await load();
    } catch (e) {
        error.value = errorMessage(e);
        key = idempotencyKey();
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-end gap-2">
            <h1 class="mr-auto text-xl font-semibold">Caja</h1>
            <input v-model="from" type="date" class="input w-40" /><input v-model="to" type="date" class="input w-40" />
            <button class="btn" @click="load">Ver</button>
            <a class="btn-secondary" :href="`/api/exports/caja?from=${from}&to=${to}`">CSV</a>
        </div>
        <ErrorBox :message="error" />
        <div v-if="data" class="grid gap-3 md:grid-cols-3">
            <div v-for="(b, cur) in data.balances" :key="cur" class="card text-sm">
                <div class="font-semibold">{{ cur }}</div>
                Inicial {{ money(b.opening) }} · Entradas {{ money(b.in) }} · Salidas {{ money(b.out) }}
                <div class="mt-1 text-lg font-semibold">Saldo {{ money(b.closing, String(cur)) }}</div>
            </div>
        </div>
        <form class="card grid gap-2 md:grid-cols-6" @submit.prevent="submit">
            <select v-model="form.type" class="input">
                <option value="apertura">Saldo inicial</option><option value="aporte">Aporte</option><option value="retiro">Retiro</option>
                <option value="pago_proveedor">Pago a proveedor</option><option value="ajuste_ingreso">Ajuste (+)</option><option value="ajuste_egreso">Ajuste (−)</option>
            </select>
            <input v-model="form.amount" class="input" inputmode="decimal" placeholder="Importe" required />
            <select v-model="form.currency" class="input"><option v-for="c in session.me?.settings.currencies" :key="c">{{ c }}</option></select>
            <input v-if="form.currency !== session.me?.settings.base_currency" v-model="form.exchange_rate" class="input" placeholder="Cotización" required />
            <input v-model="form.occurred_at" type="date" class="input" required />
            <input v-model="form.description" class="input" placeholder="Descripción / motivo" required />
            <button class="btn">Registrar movimiento</button>
        </form>
        <div v-if="data" class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Fecha</th><th>Tipo</th><th>Medio</th><th>Descripción</th><th class="text-right">Importe</th></tr></thead>
                <tbody>
                    <tr v-for="m in data.movements.data" :key="m.id">
                        <td>{{ date(m.occurred_at) }}</td><td>{{ m.type }}</td><td>{{ m.method }}</td><td>{{ m.description }}</td>
                        <td class="text-right tabular-nums" :class="m.direction === 'in' ? 'text-emerald-700' : 'text-red-700'">{{ m.direction === 'in' ? '+' : '−' }}{{ money(m.amount, m.currency) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
