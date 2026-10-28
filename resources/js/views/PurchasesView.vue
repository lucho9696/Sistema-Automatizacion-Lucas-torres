<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { http, postOnce, idempotencyKey, errorMessage, type Paginated } from '../api';
import { session } from '../session';
import { money, date, today, label } from '../format';
import ErrorBox from '../components/ErrorBox.vue';

interface Po { id: number; reference: string | null; purchase_date: string; currency: string; status: string; goods_total: string; landed_total_base: string; supplier: { name: string } }
interface Supplier { id: number; name: string }
interface Variant { id: number; sku: string; name: string; product: { name: string } }

const router = useRouter();
const rows = ref<Po[]>([]);
const suppliers = ref<Supplier[]>([]);
const variants = ref<Variant[]>([]);
const error = ref<string | null>(null);
const newSupplier = ref('');
let key = idempotencyKey();
const form = reactive({
    supplier_id: '' as number | '',
    reference: '',
    purchase_date: today(),
    currency: 'USD',
    exchange_rate: '',
    exchange_rate_source: '',
    allocation_method: 'value',
    items: [{ product_variant_id: '' as number | '', quantity: 1, unit_cost: '', discount: '' }],
    costs: [] as { type: string; description: string; amount: string; currency: string; exchange_rate: string }[],
});

async function load() {
    rows.value = (await http.get<Paginated<Po>>('/api/purchase-orders')).data.data;
    suppliers.value = (await http.get<Supplier[]>('/api/suppliers')).data;
    variants.value = (await http.get<Paginated<Variant>>('/api/stock', { params: { per_page: 500 } })).data.data;
}
onMounted(load);

async function addSupplier() {
    if (!newSupplier.value) return;
    const s = (await http.post<Supplier>('/api/suppliers', { name: newSupplier.value })).data;
    newSupplier.value = '';
    await load();
    form.supplier_id = s.id;
}

async function submit() {
    error.value = null;
    const base = session.me?.settings.base_currency;
    try {
        const res = await postOnce<{ id: number }>('/api/purchase-orders', {
            ...form,
            exchange_rate: form.currency === base ? undefined : form.exchange_rate,
            items: form.items.map((i) => ({ ...i, discount: i.discount || undefined })),
            costs: form.costs.map((c) => ({ ...c, exchange_rate: c.currency === base || c.currency === form.currency ? undefined : c.exchange_rate })),
        }, key);
        router.push(`/compras/${res.data.id}`);
    } catch (e) {
        error.value = errorMessage(e);
        key = idempotencyKey();
    }
}
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-xl font-semibold">Compras / importación</h1>
        <ErrorBox :message="error" />
        <form class="card space-y-3" @submit.prevent="submit">
            <h2 class="font-semibold">Nuevo lote de compra</h2>
            <div class="grid gap-2 md:grid-cols-4">
                <select v-model="form.supplier_id" class="input" required>
                    <option value="" disabled>Proveedor…</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <div class="flex gap-1"><input v-model="newSupplier" class="input" placeholder="Nuevo proveedor" /><button type="button" class="btn-secondary" @click="addSupplier">+</button></div>
                <input v-model="form.reference" class="input" placeholder="Factura / orden" />
                <input v-model="form.purchase_date" type="date" class="input" required />
                <select v-model="form.currency" class="input"><option v-for="c in session.me?.settings.currencies" :key="c">{{ c }}</option></select>
                <input v-if="form.currency !== session.me?.settings.base_currency" v-model="form.exchange_rate" class="input" inputmode="decimal" :placeholder="`Cotización a ${session.me?.settings.base_currency}`" required />
                <input v-model="form.exchange_rate_source" class="input" placeholder="Fuente de la cotización" />
                <select v-model="form.allocation_method" class="input">
                    <option value="value">Distribuir costos por valor</option>
                    <option value="quantity">Distribuir costos por unidad</option>
                </select>
            </div>
            <h3 class="text-sm font-semibold">Ítems ({{ form.currency }})</h3>
            <div v-for="(item, i) in form.items" :key="'i' + i" class="grid gap-2 md:grid-cols-6">
                <select v-model="item.product_variant_id" class="input md:col-span-3" required>
                    <option value="" disabled>Producto…</option>
                    <option v-for="v in variants" :key="v.id" :value="v.id">{{ v.product.name }} {{ v.name }} — {{ v.sku }}</option>
                </select>
                <input v-model.number="item.quantity" type="number" min="1" class="input" required />
                <input v-model="item.unit_cost" class="input" inputmode="decimal" placeholder="Costo unit." required />
                <input v-model="item.discount" class="input" inputmode="decimal" placeholder="Descuento línea" />
            </div>
            <button type="button" class="btn-secondary" @click="form.items.push({ product_variant_id: '', quantity: 1, unit_cost: '', discount: '' })">+ Ítem</button>
            <h3 class="text-sm font-semibold">Costos atribuibles (flete, seguro, aranceles, despacho…)</h3>
            <div v-for="(c, i) in form.costs" :key="'c' + i" class="grid gap-2 md:grid-cols-6">
                <select v-model="c.type" class="input"><option v-for="t in session.me?.settings.purchase_cost_types" :key="t">{{ t }}</option></select>
                <input v-model="c.description" class="input md:col-span-2" placeholder="Descripción" />
                <input v-model="c.amount" class="input" inputmode="decimal" placeholder="Importe" required />
                <select v-model="c.currency" class="input"><option v-for="cur in session.me?.settings.currencies" :key="cur">{{ cur }}</option></select>
                <input v-if="c.currency !== session.me?.settings.base_currency && c.currency !== form.currency" v-model="c.exchange_rate" class="input" placeholder="Cotización" required />
            </div>
            <div class="flex gap-2">
                <button type="button" class="btn-secondary" @click="form.costs.push({ type: 'flete', description: '', amount: '', currency: form.currency, exchange_rate: '' })">+ Costo</button>
                <button class="btn ml-auto">Guardar borrador</button>
            </div>
        </form>
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>#</th><th>Fecha</th><th>Proveedor</th><th>Ref.</th><th>Estado</th><th class="text-right">Mercadería</th><th class="text-right">Puesto en destino (base)</th></tr></thead>
                <tbody>
                    <tr v-for="p in rows" :key="p.id">
                        <td><RouterLink :to="`/compras/${p.id}`" class="text-blue-700 hover:underline">{{ p.id }}</RouterLink></td>
                        <td>{{ date(p.purchase_date) }}</td><td>{{ p.supplier.name }}</td><td>{{ p.reference }}</td><td><span class="badge">{{ label(p.status) }}</span></td>
                        <td class="text-right">{{ money(p.goods_total, p.currency) }}</td><td class="text-right">{{ money(p.landed_total_base) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
