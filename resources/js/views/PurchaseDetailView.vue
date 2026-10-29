<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { http, postOnce, idempotencyKey, errorMessage } from '../api';
import { money, date, label } from '../format';
import ErrorBox from '../components/ErrorBox.vue';

const props = defineProps<{ id: string }>();

interface Item { id: number; quantity: number; unit_cost: string; line_total: string; line_total_base: string; allocated_costs_base: string; landed_unit_cost_base: string; quantity_received: number; quantity_damaged: number; quantity_missing: number; variant: { sku: string; name: string; product_id: number }; units: { id: number; imei: string | null; serial_number: string | null; status: string }[] }
interface Po { id: number; status: string; currency: string; exchange_rate: string; purchase_date: string; reference: string | null; goods_total: string; goods_total_base: string; extra_costs_base: string; landed_total_base: string; allocation_method: string; allocation_snapshot: Record<string, unknown> | null; supplier: { name: string }; items: Item[]; costs: { id: number; type: string; description: string | null; amount: string; currency: string; exchange_rate: string; amount_base: string }[] }

const po = ref<Po | null>(null);
const error = ref<string | null>(null);
const hold = ref(false);
const lines = ref<Record<number, { received: number; damaged: number; imeis: string }>>({});
let key = idempotencyKey();

async function load() {
    po.value = (await http.get<Po>(`/api/purchase-orders/${props.id}`)).data;
    lines.value = Object.fromEntries(po.value.items.map((i) => [i.id, { received: i.quantity, damaged: 0, imeis: '' }]));
}
onMounted(load);

async function receive() {
    if (!po.value) return;
    error.value = null;
    try {
        await postOnce(`/api/purchase-orders/${props.id}/receive`, {
            hold_for_inspection: hold.value,
            lines: po.value.items.map((i) => {
                const l = lines.value[i.id];
                const ids = l.imeis.split(/\s|,|;/).map((s) => s.trim()).filter(Boolean);
                return {
                    item_id: i.id,
                    quantity_received: l.received,
                    quantity_damaged: l.damaged,
                    // 15 dígitos = IMEI; cualquier otro formato se guarda como número de serie.
                    units: ids.length ? ids.map((x) => (/^\d{15}$/.test(x) ? { imei: x } : { serial_number: x })) : undefined,
                };
            }),
        }, key);
        await load();
    } catch (e) {
        error.value = errorMessage(e);
        key = idempotencyKey();
    }
}

async function cancel() {
    const reason = prompt('Motivo de la cancelación:');
    if (!reason) return;
    try {
        await http.post(`/api/purchase-orders/${props.id}/cancel`, { reason });
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}
</script>

<template>
    <div v-if="po" class="space-y-4">
        <div class="flex items-center gap-2">
            <h1 class="mr-auto text-xl font-semibold">Compra #{{ po.id }} · {{ po.supplier.name }} <span class="badge">{{ label(po.status) }}</span></h1>
            <button v-if="po.status === 'draft'" class="btn-danger" @click="cancel">Cancelar</button>
        </div>
        <ErrorBox :message="error" />
        <div class="card grid gap-2 text-sm md:grid-cols-4">
            <div><span class="label">Fecha</span>{{ date(po.purchase_date) }}</div>
            <div><span class="label">Referencia</span>{{ po.reference ?? '—' }}</div>
            <div><span class="label">Moneda / cotización</span>{{ po.currency }} · {{ po.exchange_rate }}</div>
            <div><span class="label">Método de distribución</span>{{ po.allocation_method === 'value' ? 'Proporcional al valor' : 'Por unidad' }}</div>
            <div><span class="label">Mercadería</span>{{ money(po.goods_total, po.currency) }} ({{ money(po.goods_total_base) }} base)</div>
            <div><span class="label">Costos atribuibles (base)</span>{{ money(po.extra_costs_base) }}</div>
            <div><span class="label">Costo puesto en destino (base)</span><strong>{{ money(po.landed_total_base) }}</strong></div>
        </div>
        <div class="card overflow-x-auto">
            <h2 class="mb-2 font-semibold">Costos atribuibles</h2>
            <table class="table">
                <tbody>
                    <tr v-for="c in po.costs" :key="c.id"><td>{{ c.type }}</td><td>{{ c.description }}</td><td class="text-right">{{ money(c.amount, c.currency) }} × {{ c.exchange_rate }}</td><td class="text-right">{{ money(c.amount_base) }}</td></tr>
                    <tr v-if="!po.costs.length"><td class="text-slate-500">Sin costos adicionales.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card overflow-x-auto">
            <h2 class="mb-2 font-semibold">Ítems</h2>
            <table class="table">
                <thead><tr><th>Variante</th><th>Cant.</th><th class="text-right">Línea</th><th class="text-right">Costos asignados</th><th class="text-right">Costo unit. destino</th><th>Recepción</th></tr></thead>
                <tbody>
                    <tr v-for="i in po.items" :key="i.id">
                        <td>{{ i.variant.sku }} · {{ i.variant.name }}</td><td>{{ i.quantity }}</td>
                        <td class="text-right">{{ money(i.line_total, po.currency) }}</td>
                        <td class="text-right">{{ money(i.allocated_costs_base) }}</td>
                        <td class="text-right font-medium">{{ money(i.landed_unit_cost_base) }}</td>
                        <td v-if="po.status === 'draft'" class="min-w-72 space-y-1">
                            <div class="flex gap-1">
                                <label class="text-xs">Recibidas <input v-model.number="lines[i.id].received" type="number" min="0" :max="i.quantity" class="input w-20" /></label>
                                <label class="text-xs">Dañadas <input v-model.number="lines[i.id].damaged" type="number" min="0" class="input w-20" /></label>
                            </div>
                            <textarea v-model="lines[i.id].imeis" class="input h-16 text-xs" placeholder="IMEI o serie de cada unidad válida (uno por línea). Vacío si no es serializado."></textarea>
                        </td>
                        <td v-else class="text-xs">
                            Recibidas {{ i.quantity_received }} · dañadas {{ i.quantity_damaged }} · faltantes {{ i.quantity_missing }}
                            <div v-if="i.units.length" class="text-slate-500">{{ i.units.map((u) => u.imei ?? u.serial_number).join(', ') }}</div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div v-if="po.status === 'draft'" class="mt-3 flex items-center gap-3">
                <label class="flex items-center gap-2 text-sm"><input v-model="hold" type="checkbox" /> Ingresar como "en revisión" (no vendible hasta liberar)</label>
                <button class="btn ml-auto" @click="receive">Registrar recepción y calcular costos</button>
            </div>
        </div>
        <details v-if="po.allocation_snapshot" class="card text-xs">
            <summary class="cursor-pointer font-semibold">Detalle auditable del cálculo</summary>
            <pre class="mt-2 overflow-x-auto">{{ JSON.stringify(po.allocation_snapshot, null, 2) }}</pre>
        </details>
    </div>
</template>
