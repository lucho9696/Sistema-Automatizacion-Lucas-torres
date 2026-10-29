<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { http, errorMessage } from '../api';
import { money, today } from '../format';
import Kpi from '../components/Kpi.vue';
import ErrorBox from '../components/ErrorBox.vue';

interface CashRow { opening: string; in: string; out: string; closing: string }
interface Dashboard {
    base_currency: string;
    coverage_note: string;
    sales: { confirmed_gross: string; confirmed_count: number; cancelled: string; returned: string; net: string };
    collections: { collected: string; voided: string; net: string };
    cogs: string;
    gross_profit: string;
    operating_expenses: { total: string; scope: string; by_category: { slug: string; name: string; total: string; count: number }[] };
    estimated_operating_result: string;
    receivables: { pending_base: string; overdue_base: string; note: string; by_currency: Record<string, { pending: string; overdue: string; count: number }> };
    unpaid_expenses_base: string;
    cash: Record<string, CashRow>;
    inventory: { on_hand: number; reserved: number; held: number; available: number; value_at_cost_base: string };
    integrations: { meta_ads: { status: string; note: string } };
}

const from = ref(today().slice(0, 8) + '01');
const to = ref(today());
const data = ref<Dashboard | null>(null);
const error = ref<string | null>(null);

async function load() {
    error.value = null;
    try {
        data.value = (await http.get<Dashboard>('/api/dashboard', { params: { from: from.value, to: to.value } })).data;
    } catch (e) {
        error.value = errorMessage(e);
    }
}
onMounted(load);
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-end gap-2">
            <h1 class="mr-auto text-xl font-semibold">Dashboard financiero</h1>
            <div><label class="label">Desde</label><input v-model="from" type="date" class="input" /></div>
            <div><label class="label">Hasta</label><input v-model="to" type="date" class="input" /></div>
            <button class="btn" @click="load">Actualizar</button>
            <a class="btn-secondary" :href="`/api/exports/ventas?from=${from}&to=${to}`">CSV ventas</a>
        </div>
        <ErrorBox :message="error" />
        <template v-if="data">
            <p class="text-xs text-slate-500">{{ data.coverage_note }} Importes en {{ data.base_currency }} a la cotización de cada operación.</p>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <Kpi label="Ventas confirmadas (netas)" :value="money(data.sales.net)" :hint="`${data.sales.confirmed_count} ventas · anuladas ${money(data.sales.cancelled)} · devueltas ${money(data.sales.returned)}`" />
                <Kpi label="Cobrado en el período" :value="money(data.collections.net)" :hint="`Anulaciones de cobro: ${money(data.collections.voided)}`" />
                <Kpi label="Costo mercadería vendida" :value="money(data.cogs)" />
                <Kpi label="Ganancia bruta" :value="money(data.gross_profit)" />
                <Kpi label="Gastos operativos" :value="money(data.operating_expenses.total)" :hint="data.operating_expenses.scope" />
                <Kpi label="Resultado operativo estimado" :value="money(data.estimated_operating_result)" hint="Ganancia bruta − gastos operativos registrados" />
                <Kpi label="Cuentas por cobrar" :value="money(data.receivables.pending_base)" :hint="`Vencidas: ${money(data.receivables.overdue_base)}. ${data.receivables.note}`" />
                <Kpi label="Gastos devengados impagos" :value="money(data.unpaid_expenses_base)" />
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <div class="card">
                    <h2 class="mb-2 font-semibold">Caja por moneda</h2>
                    <table class="table">
                        <thead><tr><th>Moneda</th><th>Inicial</th><th>Entradas</th><th>Salidas</th><th>Final</th></tr></thead>
                        <tbody>
                            <tr v-for="(row, cur) in data.cash" :key="cur">
                                <td>{{ cur }}</td><td>{{ money(row.opening) }}</td><td>{{ money(row.in) }}</td><td>{{ money(row.out) }}</td><td class="font-medium">{{ money(row.closing) }}</td>
                            </tr>
                            <tr v-if="!Object.keys(data.cash).length"><td colspan="5" class="text-slate-500">Sin movimientos de caja.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="card">
                    <h2 class="mb-2 font-semibold">Gastos por categoría</h2>
                    <table class="table">
                        <tbody>
                            <tr v-for="c in data.operating_expenses.by_category" :key="c.slug"><td>{{ c.name }}</td><td class="text-right">{{ money(c.total) }}</td></tr>
                            <tr v-if="!data.operating_expenses.by_category.length"><td class="text-slate-500">Sin gastos en el período.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="card">
                    <h2 class="mb-2 font-semibold">Inventario</h2>
                    <p class="text-sm">En poder: {{ data.inventory.on_hand }} · Reservado: {{ data.inventory.reserved }} · Retenido: {{ data.inventory.held }} · <strong>Disponible: {{ data.inventory.available }}</strong></p>
                    <p class="mt-1 text-sm">Valor a costo: {{ money(data.inventory.value_at_cost_base, data.base_currency) }}</p>
                </div>
                <div class="card">
                    <h2 class="mb-2 font-semibold">Integraciones</h2>
                    <p class="text-sm">Meta Ads: <span class="badge">{{ data.integrations.meta_ads.status }}</span></p>
                    <p class="mt-1 text-xs text-slate-500">{{ data.integrations.meta_ads.note }}</p>
                </div>
            </div>
        </template>
    </div>
</template>
