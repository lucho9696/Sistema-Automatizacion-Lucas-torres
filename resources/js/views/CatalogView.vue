<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { http, errorMessage, type Paginated } from '../api';
import { session } from '../session';
import ErrorBox from '../components/ErrorBox.vue';

interface Category { id: number; name: string; is_serialized: boolean }
interface Product { id: number; sku: string; name: string; brand: string | null; is_serialized: boolean; category: Category; variants: { id: number; sku: string; name: string; list_price: string | null; price_currency: string | null }[] }

const categories = ref<Category[]>([]);
const products = ref<Product[]>([]);
const error = ref<string | null>(null);
const form = reactive({
    category_id: '' as number | '',
    brand: '',
    name: '',
    sku: '',
    variants: [{ sku: '', name: '', storage: '', color: '', condition: 'nuevo', list_price: '', price_currency: 'USD' }],
});

async function load() {
    categories.value = (await http.get<Category[]>('/api/categories')).data;
    products.value = (await http.get<Paginated<Product>>('/api/products', { params: { per_page: 200 } })).data.data;
}
onMounted(load);

async function submit() {
    error.value = null;
    try {
        await http.post('/api/products', {
            category_id: form.category_id,
            brand: form.brand || null,
            name: form.name,
            sku: form.sku,
            variants: form.variants.map((v) => ({
                sku: v.sku,
                name: v.name,
                // Solo se guardan los atributos informados; no se deducen datos ausentes.
                attributes: Object.fromEntries(Object.entries({ storage: v.storage, color: v.color, condition: v.condition }).filter(([, x]) => x)),
                list_price: v.list_price || null,
                price_currency: v.list_price ? v.price_currency : null,
            })),
        });
        form.name = form.sku = form.brand = '';
        form.variants = [{ sku: '', name: '', storage: '', color: '', condition: 'nuevo', list_price: '', price_currency: 'USD' }];
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-xl font-semibold">Catálogo</h1>
        <ErrorBox :message="error" />
        <form class="card space-y-3" @submit.prevent="submit">
            <h2 class="font-semibold">Nuevo producto</h2>
            <div class="grid gap-2 md:grid-cols-4">
                <select v-model="form.category_id" class="input" required>
                    <option value="" disabled>Categoría…</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}{{ c.is_serialized ? ' (IMEI/serie)' : '' }}</option>
                </select>
                <input v-model="form.brand" class="input" placeholder="Marca" />
                <input v-model="form.name" class="input" placeholder="Nombre (ej. iPhone 15)" required />
                <input v-model="form.sku" class="input" placeholder="SKU interno" required />
            </div>
            <div v-for="(v, i) in form.variants" :key="i" class="grid gap-2 md:grid-cols-8">
                <input v-model="v.sku" class="input" placeholder="SKU variante" required />
                <input v-model="v.name" class="input md:col-span-2" placeholder="Nombre (ej. 128 GB Negro)" required />
                <input v-model="v.storage" class="input" placeholder="Almacenamiento" />
                <input v-model="v.color" class="input" placeholder="Color" />
                <select v-model="v.condition" class="input"><option>nuevo</option><option>usado</option><option>reacondicionado</option></select>
                <input v-model="v.list_price" class="input" inputmode="decimal" placeholder="Precio lista" />
                <select v-model="v.price_currency" class="input"><option v-for="c in session.me?.settings.currencies" :key="c">{{ c }}</option></select>
            </div>
            <div class="flex gap-2">
                <button type="button" class="btn-secondary" @click="form.variants.push({ sku: '', name: '', storage: '', color: '', condition: 'nuevo', list_price: '', price_currency: 'USD' })">+ Variante</button>
                <button class="btn ml-auto">Guardar producto</button>
            </div>
        </form>
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>SKU</th><th>Producto</th><th>Categoría</th><th>Variantes</th></tr></thead>
                <tbody>
                    <tr v-for="p in products" :key="p.id">
                        <td>{{ p.sku }}</td><td>{{ p.brand }} {{ p.name }}</td><td>{{ p.category.name }}</td>
                        <td class="text-xs">{{ p.variants.map((v) => `${v.sku} · ${v.name}${v.list_price ? ` · ${v.price_currency} ${v.list_price}` : ''}`).join(' | ') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
