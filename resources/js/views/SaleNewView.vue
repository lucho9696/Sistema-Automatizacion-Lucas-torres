<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { http, postOnce, idempotencyKey, errorMessage, type Paginated } from '../api';
import { session } from '../session';
import { today } from '../format';
import ErrorBox from '../components/ErrorBox.vue';

interface Customer { id: number; name: string; business_name: string | null }
interface Variant { id: number; sku: string; name: string; stock_available: number; list_price: string | null; price_currency: string | null; product: { name: string; is_serialized: boolean } }

const router = useRouter();
const customers = ref<Customer[]>([]);
const variants = ref<Variant[]>([]);
const error = ref<string | null>(null);
const busy = ref(false);
let key = idempotencyKey();

const form = reactive({
    customer_id: '' as number | '',
    currency: session.me?.settings.base_currency ?? 'ARS',
    exchange_rate: '',
    sale_date: today(),
    payment_terms: 'contado',
    due_date: '',
    discount: '',
    notes: '',
    confirm: true,
    items: [{ product_variant_id: '' as number | '', quantity: 1, unit_price: '' }],
});

onMounted(async () => {
    const [c, v] = await Promise.all([
        http.get<Paginated<Customer>>('/api/customers'),
        http.get<Paginated<Variant>>('/api/stock', { params: { available_only: 1, per_page: 500 } }),
    ]);
    customers.value = c.data.data;
    variants.value = v.data.data;
});

function addLine() {
    form.items.push({ product_variant_id: '', quantity: 1, unit_price: '' });
}

async function submit() {
    busy.value = true;
    error.value = null;
    try {
        const payload = {
            ...form,
            exchange_rate: form.currency === session.me?.settings.base_currency ? undefined : form.exchange_rate,
            due_date: form.payment_terms === 'credito' ? form.due_date : undefined,
            discount: form.discount || undefined,
        };
        const res = await postOnce<{ id: number }>('/api/sales', payload, key);
        router.push(`/ventas/${res.data.id}`);
    } catch (e) {
        error.value = errorMessage(e);
        // Si el servidor rechazó la operación, el próximo envío es un intento nuevo.
        key = idempotencyKey();
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <h1 class="text-xl font-semibold">Nueva venta</h1>
        <ErrorBox :message="error" />
        <div class="card grid gap-3 md:grid-cols-4">
            <div class="md:col-span-2">
                <label class="label">Cliente</label>
                <select v-model="form.customer_id" class="input" required>
                    <option value="" disabled>Seleccionar…</option>
                    <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}{{ c.business_name ? ` (${c.business_name})` : '' }}</option>
                </select>
            </div>
            <div><label class="label">Fecha</label><input v-model="form.sale_date" type="date" class="input" required /></div>
            <div>
                <label class="label">Moneda</label>
                <select v-model="form.currency" class="input">
                    <option v-for="c in session.me?.settings.currencies" :key="c">{{ c }}</option>
                </select>
            </div>
            <div v-if="form.currency !== session.me?.settings.base_currency">
                <label class="label">Cotización a {{ session.me?.settings.base_currency }}</label>
                <input v-model="form.exchange_rate" class="input" inputmode="decimal" placeholder="ej. 1100" required />
            </div>
            <div>
                <label class="label">Condición</label>
                <select v-model="form.payment_terms" class="input"><option value="contado">Contado</option><option value="credito">Crédito</option></select>
            </div>
            <div v-if="form.payment_terms === 'credito'"><label class="label">Vencimiento</label><input v-model="form.due_date" type="date" class="input" required /></div>
            <div><label class="label">Descuento global</label><input v-model="form.discount" class="input" inputmode="decimal" placeholder="0.00" /></div>
        </div>

        <div class="card space-y-2">
            <h2 class="font-semibold">Ítems</h2>
            <div v-for="(item, i) in form.items" :key="i" class="grid gap-2 md:grid-cols-6">
                <select v-model="item.product_variant_id" class="input md:col-span-3" required>
                    <option value="" disabled>Producto…</option>
                    <option v-for="v in variants" :key="v.id" :value="v.id">{{ v.product.name }} {{ v.name }} — {{ v.sku }} (disp. {{ v.stock_available }})</option>
                </select>
                <input v-model.number="item.quantity" type="number" min="1" class="input" placeholder="Cant." required />
                <input v-model="item.unit_price" class="input" inputmode="decimal" :placeholder="`Precio unit. (${form.currency})`" required />
                <button type="button" class="btn-secondary" :disabled="form.items.length === 1" @click="form.items.splice(i, 1)">Quitar</button>
            </div>
            <button type="button" class="btn-secondary" @click="addLine">+ Agregar ítem</button>
            <p class="text-xs text-slate-500">Para celulares se asignan automáticamente las unidades disponibles más antiguas (FIFO). El total, el costo y el margen se calculan en el servidor.</p>
        </div>

        <div class="card flex flex-wrap items-center gap-3">
            <label class="flex items-center gap-2 text-sm"><input v-model="form.confirm" type="checkbox" /> Confirmar ahora (reserva stock y congela precio/costo)</label>
            <input v-model="form.notes" class="input max-w-md" placeholder="Notas" />
            <button class="btn ml-auto" :disabled="busy">{{ form.confirm ? 'Guardar y confirmar' : 'Guardar como cotización' }}</button>
        </div>
    </form>
</template>
