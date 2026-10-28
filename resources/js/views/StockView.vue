<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { http, errorMessage, type Paginated } from '../api';
import { money } from '../format';
import ErrorBox from '../components/ErrorBox.vue';

interface Variant { id: number; sku: string; name: string; stock_on_hand: number; stock_reserved: number; stock_held: number; stock_available: number; avg_cost_base: string; product: { name: string; is_serialized: boolean } }
interface Unit { id: number; imei?: string | null; serial_number?: string | null; status: string; unit_cost_base: string; condition: string | null }

const rows = ref<Variant[]>([]);
const q = ref('');
const units = ref<Unit[]>([]);
const selected = ref<Variant | null>(null);
const error = ref<string | null>(null);

async function load() {
    try {
        rows.value = (await http.get<Paginated<Variant>>('/api/stock', { params: { q: q.value || undefined, per_page: 500 } })).data.data;
    } catch (e) {
        error.value = errorMessage(e);
    }
}
async function showUnits(v: Variant) {
    selected.value = v;
    units.value = (await http.get<Paginated<Unit>>('/api/units', { params: { variant_id: v.id, per_page: 500 } })).data.data;
}
onMounted(load);
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-end gap-2">
            <h1 class="mr-auto text-xl font-semibold">Stock</h1>
            <input v-model="q" class="input w-60" placeholder="Buscar SKU o modelo" @keyup.enter="load" />
            <button class="btn-secondary" @click="load">Buscar</button>
            <a class="btn-secondary" href="/api/exports/stock">CSV</a>
        </div>
        <ErrorBox :message="error" />
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>SKU</th><th>Producto</th><th>En poder</th><th>Reservado</th><th>Retenido</th><th>Disponible</th><th class="text-right">Costo prom. base</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="v in rows" :key="v.id">
                        <td>{{ v.sku }}</td><td>{{ v.product.name }} {{ v.name }}</td>
                        <td>{{ v.stock_on_hand }}</td><td>{{ v.stock_reserved }}</td><td>{{ v.stock_held }}</td>
                        <td class="font-semibold">{{ v.stock_available }}</td>
                        <td class="text-right">{{ money(v.avg_cost_base) }}</td>
                        <td><button v-if="v.product.is_serialized" class="text-xs text-blue-700 hover:underline" @click="showUnits(v)">Unidades</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="selected" class="card overflow-x-auto">
            <h2 class="mb-2 font-semibold">Unidades de {{ selected.sku }}</h2>
            <table class="table">
                <thead><tr><th>ID</th><th>IMEI / serie</th><th>Condición</th><th>Estado</th><th class="text-right">Costo base</th></tr></thead>
                <tbody>
                    <tr v-for="u in units" :key="u.id">
                        <td>{{ u.id }}</td><td>{{ u.imei ?? u.serial_number ?? 'restringido' }}</td><td>{{ u.condition ?? '—' }}</td>
                        <td><span class="badge">{{ u.status }}</span></td><td class="text-right">{{ money(u.unit_cost_base) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
