<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { http, errorMessage, type Paginated } from '../api';
import { money, date } from '../format';
import ErrorBox from '../components/ErrorBox.vue';

interface SaleRow { id: number; number: string; sale_date: string; status: string; payment_status: string; currency: string; total: string; balance_due: string; customer: { name: string } }

const rows = ref<SaleRow[]>([]);
const status = ref('');
const error = ref<string | null>(null);

async function load() {
    try {
        rows.value = (await http.get<Paginated<SaleRow>>('/api/sales', { params: { status: status.value || undefined } })).data.data;
    } catch (e) {
        error.value = errorMessage(e);
    }
}
onMounted(load);
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-end gap-2">
            <h1 class="mr-auto text-xl font-semibold">Ventas</h1>
            <select v-model="status" class="input w-44" @change="load">
                <option value="">Todos los estados</option>
                <option v-for="s in ['borrador', 'confirmada', 'entregada', 'cancelada', 'devuelta']" :key="s" :value="s">{{ s }}</option>
            </select>
            <RouterLink to="/ventas/nueva" class="btn">Nueva venta</RouterLink>
        </div>
        <ErrorBox :message="error" />
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>N°</th><th>Fecha</th><th>Cliente</th><th>Estado</th><th>Cobro</th><th class="text-right">Total</th><th class="text-right">Saldo</th></tr></thead>
                <tbody>
                    <tr v-for="s in rows" :key="s.id">
                        <td><RouterLink :to="`/ventas/${s.id}`" class="text-blue-700 hover:underline">{{ s.number }}</RouterLink></td>
                        <td>{{ date(s.sale_date) }}</td>
                        <td>{{ s.customer.name }}</td>
                        <td><span class="badge">{{ s.status }}</span></td>
                        <td><span class="badge">{{ s.payment_status }}</span></td>
                        <td class="text-right tabular-nums">{{ money(s.total, s.currency) }}</td>
                        <td class="text-right tabular-nums">{{ money(s.balance_due, s.currency) }}</td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="7" class="text-slate-500">No hay ventas.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
