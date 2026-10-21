import { createRouter, createWebHistory } from 'vue-router'

export const routes = [
    {
        path: '/',
        name: 'dashboard',
        component: () => import('@/views/DashboardView.vue'),
        meta: { title: 'Dashboard' },
    },
    {
        path: '/atendimentos',
        name: 'tickets',
        component: () => import('@/views/TicketsView.vue'),
        meta: { title: 'Atendimentos' },
    },
    {
        path: '/atendimentos/novo',
        name: 'ticket-new',
        component: () => import('@/views/TicketFormView.vue'),
        meta: { title: 'Novo atendimento' },
    },
    {
        path: '/atendimentos/:id(\\d+)/editar',
        name: 'ticket-edit',
        component: () => import('@/views/TicketFormView.vue'),
        meta: { title: 'Editar atendimento' },
    },
    {
        path: '/atendimentos/:id(\\d+)',
        name: 'ticket-detail',
        component: () => import('@/views/TicketDetailView.vue'),
        meta: { title: 'Detalhes do atendimento' },
    },
    ...[
        ['pendencias', 'pending-tasks', 'Pendências'],
        ['lembretes', 'reminders', 'Lembretes'],
        ['conhecimento', 'knowledge-articles', 'Base de conhecimento'],
        ['contatos', 'contacts', 'Contatos'],
        ['clientes', 'customers', 'Clientes'],
        ['sistemas', 'systems', 'Sistemas'],
    ].map(([path, kind, title]) => ({
        path: '/' + path,
        name: kind,
        component: () => import('@/views/ResourceListView.vue'),
        props: { kind },
        meta: { title },
    })),
    {
        path: '/:pathMatch(.*)*',
        component: () => import('@/views/NotFoundView.vue'),
        meta: { title: 'Página não encontrada' },
    },
]
const router = createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes,
    scrollBehavior: () => ({ top: 0 }),
})
router.afterEach((to) => {
    const title = typeof to.meta.title === 'string' ? to.meta.title : ''
    document.title = (title || 'Dashboard') + ' · SupportFlow'
})
export default router
