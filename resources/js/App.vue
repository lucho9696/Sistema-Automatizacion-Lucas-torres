<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { session, logout, can } from './session';

const router = useRouter();
const links = computed(() =>
    [
        { to: '/', label: 'Dashboard', ability: 'reports.view' },
        { to: '/ventas', label: 'Ventas', ability: 'sales.create' },
        { to: '/stock', label: 'Stock', ability: 'inventory.view' },
        { to: '/catalogo', label: 'Catálogo', ability: 'catalog.manage' },
        { to: '/compras', label: 'Compras', ability: 'purchases.manage' },
        { to: '/clientes', label: 'Clientes', ability: 'customers.manage' },
        { to: '/prospectos', label: 'Prospectos', ability: 'crm.manage' },
        { to: '/whatsapp', label: 'WhatsApp', ability: 'whatsapp.view' },
        { to: '/radar', label: 'Radar', ability: 'radar.view' },
        { to: '/gastos', label: 'Gastos', ability: 'finance.manage' },
        { to: '/caja', label: 'Caja', ability: 'finance.manage' },
        { to: '/integraciones', label: 'Integraciones', ability: 'radar.view' },
    ].filter((l) => can(l.ability)),
);

async function doLogout() {
    await logout();
    router.push('/login');
}
</script>

<template>
    <div class="min-h-screen">
        <header v-if="session.me" class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-4 px-4 py-2">
                <span class="font-semibold">Sistema Comercial</span>
                <nav class="flex flex-wrap gap-1 text-sm">
                    <RouterLink
                        v-for="l in links"
                        :key="l.to"
                        :to="l.to"
                        class="rounded px-2 py-1 hover:bg-slate-100"
                        active-class="bg-slate-100 font-medium"
                        :exact-active-class="l.to === '/' ? 'bg-slate-100 font-medium' : ''"
                        >{{ l.label }}</RouterLink
                    >
                </nav>
                <div class="ml-auto flex items-center gap-2 text-sm">
                    <span class="text-slate-500">{{ session.me.name }} · {{ session.me.role }}</span>
                    <button class="btn-secondary" @click="doLogout">Salir</button>
                </div>
            </div>
        </header>
        <main class="mx-auto max-w-7xl px-4 py-6">
            <RouterView />
        </main>
    </div>
</template>
