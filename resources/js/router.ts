import { createRouter, createWebHistory } from 'vue-router';
import { session, loadSession, can } from './session';

const routes = [
    { path: '/login', component: () => import('./views/LoginView.vue'), meta: { public: true } },
    { path: '/', component: () => import('./views/DashboardView.vue'), meta: { ability: 'reports.view' } },
    { path: '/ventas', component: () => import('./views/SalesView.vue'), meta: { ability: 'sales.create' } },
    { path: '/ventas/nueva', component: () => import('./views/SaleNewView.vue'), meta: { ability: 'sales.create' } },
    { path: '/ventas/:id', component: () => import('./views/SaleDetailView.vue'), meta: { ability: 'sales.create' }, props: true },
    { path: '/stock', component: () => import('./views/StockView.vue'), meta: { ability: 'inventory.view' } },
    { path: '/catalogo', component: () => import('./views/CatalogView.vue'), meta: { ability: 'catalog.manage' } },
    { path: '/compras', component: () => import('./views/PurchasesView.vue'), meta: { ability: 'purchases.manage' } },
    { path: '/compras/:id', component: () => import('./views/PurchaseDetailView.vue'), meta: { ability: 'purchases.manage' }, props: true },
    { path: '/clientes', component: () => import('./views/CustomersView.vue'), meta: { ability: 'customers.manage' } },
    { path: '/gastos', component: () => import('./views/ExpensesView.vue'), meta: { ability: 'finance.manage' } },
    { path: '/caja', component: () => import('./views/CashView.vue'), meta: { ability: 'finance.manage' } },
    { path: '/prospectos', component: () => import('./views/LeadsView.vue'), meta: { ability: 'crm.manage' } },
    { path: '/prospectos/:id', component: () => import('./views/LeadDetailView.vue'), meta: { ability: 'crm.manage' }, props: true },
    { path: '/radar', component: () => import('./views/RadarView.vue'), meta: { ability: 'radar.view' } },
    { path: '/radar/:id', component: () => import('./views/RadarDetailView.vue'), meta: { ability: 'radar.view' }, props: true },
    { path: '/whatsapp', component: () => import('./views/WhatsAppView.vue'), meta: { ability: 'whatsapp.view' } },
    { path: '/integraciones', component: () => import('./views/IntegrationsView.vue'), meta: { ability: 'radar.view' } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
];

export const router = createRouter({ history: createWebHistory(), routes });

router.beforeEach(async (to) => {
    if (!session.loaded) await loadSession();
    if (to.meta.public) return true;
    if (!session.me) return '/login';
    const ability = to.meta.ability as string | undefined;
    if (ability && !can(ability)) {
        // Vendedor sin acceso a reportes: lo llevamos a ventas.
        return to.path === '/' && can('sales.create') ? '/ventas' : false;
    }
    return true;
});
