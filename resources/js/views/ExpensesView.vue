<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { http, postOnce, idempotencyKey, errorMessage, type Paginated } from '../api';
import { session } from '../session';
import { money, date, today } from '../format';
import ErrorBox from '../components/ErrorBox.vue';

interface Category { id: number; name: string }
interface Expense { id: number; issued_at: string; paid_at: string | null; description: string; vendor: string | null; amount: string; currency: string; amount_base: string; status: string; source: string; category: Category }

const rows = ref<Expense[]>([]);
const categories = ref<Category[]>([]);
const error = ref<string | null>(null);
let key = idempotencyKey();
const form = reactive({ expense_category_id: '' as number | '', description: '', vendor: '', amount: '', currency: session.me?.settings.base_currency ?? 'ARS', exchange_rate: '', issued_at: today(), paid: true, paid_at: today(), payment_method: 'transferencia' });

async function load() {
    categories.value = (await http.get<Category[]>('/api/expense-categories')).data;
    rows.value = (await http.get<Paginated<Expense>>('/api/expenses')).data.data;
}
onMounted(load);

async function submit() {
    error.value = null;
    try {
        await postOnce('/api/expenses', {
            expense_category_id: form.expense_category_id,
            description: form.description,
            vendor: form.vendor || undefined,
            amount: form.amount,
            currency: form.currency,
            exchange_rate: form.currency === session.me?.settings.base_currency ? undefined : form.exchange_rate,
            issued_at: form.issued_at,
            paid_at: form.paid ? form.paid_at : undefined,
            payment_method: form.paid ? form.payment_method : undefined,
        }, key);
        key = idempotencyKey();
        form.description = form.vendor = form.amount = '';
        await load();
    } catch (e) {
        error.value = errorMessage(e);
        key = idempotencyKey();
    }
}

async function act(e: Expense, kind: 'pay' | 'void') {
    try {
        if (kind === 'void') {
            const reason = prompt('Motivo de la anulación:');
            if (!reason) return;
            await http.post(`/api/expenses/${e.id}/void`, { reason });
        } else {
            await http.post(`/api/expenses/${e.id}/pay`, { paid_at: today() });
        }
        await load();
    } catch (err) {
        error.value = errorMessage(err);
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center"><h1 class="mr-auto text-xl font-semibold">Gastos</h1><a class="btn-secondary" href="/api/exports/gastos">CSV</a></div>
        <ErrorBox :message="error" />
        <form class="card grid gap-2 md:grid-cols-6" @submit.prevent="submit">
            <select v-model="form.expense_category_id" class="input" required>
                <option value="" disabled>Categoría…</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <input v-model="form.description" class="input md:col-span-2" placeholder="Descripción" required />
            <input v-model="form.vendor" class="input" placeholder="Proveedor" />
            <input v-model="form.amount" class="input" inputmode="decimal" placeholder="Importe" required />
            <select v-model="form.currency" class="input"><option v-for="c in session.me?.settings.currencies" :key="c">{{ c }}</option></select>
            <input v-if="form.currency !== session.me?.settings.base_currency" v-model="form.exchange_rate" class="input" placeholder="Cotización" required />
            <label class="text-xs">Devengado <input v-model="form.issued_at" type="date" class="input" required /></label>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.paid" type="checkbox" /> Ya pagado</label>
            <template v-if="form.paid">
                <label class="text-xs">Pagado <input v-model="form.paid_at" type="date" class="input" /></label>
                <select v-model="form.payment_method" class="input"><option v-for="m in session.me?.settings.payment_methods" :key="m">{{ m }}</option></select>
            </template>
            <button class="btn">Registrar gasto</button>
        </form>
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Devengado</th><th>Pagado</th><th>Categoría</th><th>Descripción</th><th class="text-right">Importe</th><th class="text-right">Base</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="e in rows" :key="e.id" :class="{ 'text-slate-400 line-through': e.status === 'anulado' }">
                        <td>{{ date(e.issued_at) }}</td><td>{{ date(e.paid_at) }}</td><td>{{ e.category.name }}</td><td>{{ e.description }}</td>
                        <td class="text-right">{{ money(e.amount, e.currency) }}</td><td class="text-right">{{ money(e.amount_base) }}</td><td>{{ e.status }}</td>
                        <td class="space-x-2 whitespace-nowrap text-xs">
                            <button v-if="e.status === 'devengado'" class="text-blue-700 hover:underline" @click="act(e, 'pay')">Pagar hoy</button>
                            <button v-if="e.status !== 'anulado'" class="text-red-700 hover:underline" @click="act(e, 'void')">Anular</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
