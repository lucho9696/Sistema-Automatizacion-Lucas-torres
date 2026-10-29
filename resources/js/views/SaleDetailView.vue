<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { http, postOnce, idempotencyKey, errorMessage } from '../api';
import { session, can } from '../session';
import { money, date, today } from '../format';
import ErrorBox from '../components/ErrorBox.vue';

const props = defineProps<{ id: string }>();

interface Payment { id: number; method: string; amount: string; currency: string; applied_amount: string; paid_at: string; status: string; reference: string | null }
interface Sale {
    id: number; number: string; status: string; payment_status: string; currency: string; exchange_rate: string; sale_date: string; due_date: string | null;
    subtotal: string; discount: string; total: string; total_base: string; cost_total_base: string; paid_amount: string; balance_due: string; status_reason: string | null;
    customer: { name: string };
    items: { id: number; description: string; quantity: number; unit_price: string; line_total: string; unit_cost_base: string | null; line_cost_base: string | null; item_units: { unit: { imei?: string | null; serial_number?: string | null } | null }[] }[];
    payments: Payment[];
}

const sale = ref<Sale | null>(null);
const error = ref<string | null>(null);
const busy = ref(false);
const pay = reactive({ method: 'transferencia', amount: '', currency: '', exchange_rate: '', applied_amount: '', paid_at: today(), reference: '' });
let payKey = idempotencyKey();

async function load() {
    sale.value = (await http.get<Sale>(`/api/sales/${props.id}`)).data;
    pay.currency = sale.value.currency;
}
onMounted(load);

async function action(path: string, needsReason = false) {
    let reason: string | null = null;
    if (needsReason) {
        reason = prompt('Motivo (queda registrado en auditoría):');
        if (!reason) return;
    } else if (!confirm('¿Confirmar la operación?')) {
        return;
    }
    busy.value = true;
    error.value = null;
    try {
        sale.value = (await http.post<Sale>(`/api/sales/${props.id}/${path}`, reason ? { reason } : {})).data;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}

async function registerPayment() {
    busy.value = true;
    error.value = null;
    try {
        const base = session.me?.settings.base_currency;
        await postOnce(`/api/sales/${props.id}/payments`, {
            method: pay.method,
            amount: pay.amount,
            currency: pay.currency,
            exchange_rate: pay.currency !== base && pay.currency !== sale.value?.currency ? pay.exchange_rate : undefined,
            applied_amount: pay.currency !== sale.value?.currency ? pay.applied_amount : undefined,
            paid_at: pay.paid_at,
            reference: pay.reference || undefined,
        }, payKey);
        payKey = idempotencyKey();
        pay.amount = '';
        pay.applied_amount = '';
        pay.reference = '';
        await load();
    } catch (e) {
        error.value = errorMessage(e);
        payKey = idempotencyKey();
    } finally {
        busy.value = false;
    }
}

async function voidPayment(p: Payment) {
    const reason = prompt('Motivo de la anulación del cobro:');
    if (!reason) return;
    try {
        await http.post(`/api/payments/${p.id}/void`, { reason });
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}
</script>

<template>
    <div v-if="sale" class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="mr-auto text-xl font-semibold">Venta {{ sale.number }} <span class="badge">{{ sale.status }}</span> <span class="badge">cobro: {{ sale.payment_status }}</span></h1>
            <button v-if="sale.status === 'borrador'" class="btn" :disabled="busy" @click="action('confirm')">Confirmar</button>
            <button v-if="sale.status === 'confirmada'" class="btn" :disabled="busy" @click="action('deliver')">Marcar entregada</button>
            <button v-if="sale.status === 'borrador' || (sale.status === 'confirmada' && can('sales.cancel'))" class="btn-danger" :disabled="busy" @click="action('cancel', true)">Cancelar</button>
            <button v-if="sale.status === 'entregada' && can('sales.return')" class="btn-danger" :disabled="busy" @click="action('return', true)">Registrar devolución</button>
        </div>
        <ErrorBox :message="error" />
        <div class="card grid gap-2 text-sm md:grid-cols-4">
            <div><span class="label">Cliente</span>{{ sale.customer.name }}</div>
            <div><span class="label">Fecha</span>{{ date(sale.sale_date) }}</div>
            <div><span class="label">Vencimiento</span>{{ date(sale.due_date) }}</div>
            <div><span class="label">Cotización</span>{{ sale.exchange_rate }}</div>
            <div><span class="label">Total</span>{{ money(sale.total, sale.currency) }}</div>
            <div><span class="label">Cobrado</span>{{ money(sale.paid_amount, sale.currency) }}</div>
            <div><span class="label">Saldo</span><strong>{{ money(sale.balance_due, sale.currency) }}</strong></div>
            <div v-if="sale.status !== 'borrador'"><span class="label">Costo congelado ({{ session.me?.settings.base_currency }})</span>{{ money(sale.cost_total_base) }}</div>
            <div v-if="sale.status_reason" class="md:col-span-4"><span class="label">Motivo</span>{{ sale.status_reason }}</div>
        </div>
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Ítem</th><th>Cant.</th><th class="text-right">Precio</th><th class="text-right">Total</th><th class="text-right">Costo unit. base</th><th>IMEI/serie</th></tr></thead>
                <tbody>
                    <tr v-for="i in sale.items" :key="i.id">
                        <td>{{ i.description }}</td><td>{{ i.quantity }}</td>
                        <td class="text-right">{{ money(i.unit_price) }}</td><td class="text-right">{{ money(i.line_total) }}</td>
                        <td class="text-right">{{ i.unit_cost_base ? money(i.unit_cost_base) : '—' }}</td>
                        <td class="text-xs">{{ i.item_units.map((u) => u.unit?.imei ?? u.unit?.serial_number ?? '•••').join(', ') }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-2 text-right text-sm">Subtotal {{ money(sale.subtotal) }} · Descuento {{ money(sale.discount) }} · <strong>Total {{ money(sale.total, sale.currency) }}</strong></p>
        </div>

        <div class="card space-y-3">
            <h2 class="font-semibold">Cobros</h2>
            <table class="table">
                <thead><tr><th>Fecha</th><th>Medio</th><th class="text-right">Importe</th><th class="text-right">Imputado</th><th>Referencia</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="p in sale.payments" :key="p.id" :class="{ 'line-through text-slate-400': p.status === 'anulado' }">
                        <td>{{ date(p.paid_at) }}</td><td>{{ p.method }}</td><td class="text-right">{{ money(p.amount, p.currency) }}</td>
                        <td class="text-right">{{ money(p.applied_amount, sale.currency) }}</td><td>{{ p.reference }}</td><td>{{ p.status }}</td>
                        <td><button v-if="p.status === 'confirmado' && can('payments.void')" class="text-xs text-red-700 hover:underline" @click="voidPayment(p)">Anular</button></td>
                    </tr>
                    <tr v-if="!sale.payments.length"><td colspan="7" class="text-slate-500">Sin cobros registrados.</td></tr>
                </tbody>
            </table>
            <form v-if="['confirmada', 'entregada'].includes(sale.status) && sale.payment_status !== 'pagado' && can('payments.register')" class="grid gap-2 md:grid-cols-7" @submit.prevent="registerPayment">
                <select v-model="pay.method" class="input"><option v-for="m in session.me?.settings.payment_methods" :key="m">{{ m }}</option></select>
                <select v-model="pay.currency" class="input"><option v-for="c in session.me?.settings.currencies" :key="c">{{ c }}</option></select>
                <input v-model="pay.amount" class="input" inputmode="decimal" placeholder="Importe" required />
                <input v-if="pay.currency !== sale.currency" v-model="pay.applied_amount" class="input" inputmode="decimal" :placeholder="`Imputado en ${sale.currency}`" required />
                <input v-if="pay.currency !== sale.currency && pay.currency !== session.me?.settings.base_currency" v-model="pay.exchange_rate" class="input" placeholder="Cotización" required />
                <input v-model="pay.paid_at" type="date" class="input" required />
                <input v-model="pay.reference" class="input" placeholder="Referencia / comprobante" />
                <button class="btn" :disabled="busy">Registrar cobro</button>
            </form>
            <p class="text-xs text-slate-500">Registre un cobro solo con el dinero efectivamente recibido y verificado. Un mensaje del cliente no es un cobro.</p>
        </div>
    </div>
</template>
