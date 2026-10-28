<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { http, errorMessage, type Paginated } from '../api';
import ErrorBox from '../components/ErrorBox.vue';

interface Customer { id: number; name: string; business_name: string | null; phone_e164: string | null; city: string | null; type: string; source: string | null }

const rows = ref<Customer[]>([]);
const q = ref('');
const error = ref<string | null>(null);
const form = reactive({ name: '', business_name: '', tax_id: '', phone: '', email: '', city: '', province: '', type: 'mayorista', source: '' });

async function load() {
    rows.value = (await http.get<Paginated<Customer>>('/api/customers', { params: { q: q.value || undefined } })).data.data;
}
onMounted(load);

async function submit() {
    error.value = null;
    try {
        await http.post('/api/customers', Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')));
        Object.assign(form, { name: '', business_name: '', tax_id: '', phone: '', email: '', city: '', province: '', source: '' });
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-xl font-semibold">Clientes</h1>
        <ErrorBox :message="error" />
        <form class="card grid gap-2 md:grid-cols-5" @submit.prevent="submit">
            <input v-model="form.name" class="input" placeholder="Nombre / contacto" required />
            <input v-model="form.business_name" class="input" placeholder="Razón social" />
            <input v-model="form.tax_id" class="input" placeholder="CUIT/DNI" />
            <input v-model="form.phone" class="input" placeholder="Teléfono (ej. 351 123 4567)" />
            <input v-model="form.email" class="input" type="email" placeholder="Email" />
            <input v-model="form.city" class="input" placeholder="Ciudad" />
            <input v-model="form.province" class="input" placeholder="Provincia" />
            <select v-model="form.type" class="input"><option value="mayorista">Mayorista</option><option value="minorista">Minorista</option></select>
            <input v-model="form.source" class="input" placeholder="Origen (referido, visita…)" />
            <button class="btn">Agregar cliente</button>
        </form>
        <div class="card overflow-x-auto">
            <div class="mb-2 flex gap-2"><input v-model="q" class="input w-64" placeholder="Buscar" @keyup.enter="load" /><button class="btn-secondary" @click="load">Buscar</button></div>
            <table class="table">
                <thead><tr><th>Nombre</th><th>Razón social</th><th>Teléfono</th><th>Ciudad</th><th>Tipo</th><th>Origen</th></tr></thead>
                <tbody>
                    <tr v-for="c in rows" :key="c.id"><td>{{ c.name }}</td><td>{{ c.business_name }}</td><td>{{ c.phone_e164 }}</td><td>{{ c.city }}</td><td>{{ c.type }}</td><td>{{ c.source }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
