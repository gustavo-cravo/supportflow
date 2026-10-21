<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import ToastList from '@/components/ToastList.vue'
const route = useRoute(),
    mobileOpen = ref(false),
    now = ref(new Date())
const mobileMedia = window.matchMedia?.('(max-width: 760px)')
const mobileLayout = ref(mobileMedia?.matches ?? false)
function updateLayout(event) {
    mobileLayout.value = event.matches
    if (!event.matches) mobileOpen.value = false
}
mobileMedia?.addEventListener('change', updateLayout)
watch(mobileOpen, async (open) => {
    if (open) {
        await nextTick()
        document.querySelector('.sidebar-mobile-close')?.focus()
    }
})
const clock = setInterval(() => {
    now.value = new Date()
}, 60000)
const today = computed(() =>
    new Intl.DateTimeFormat('pt-BR', {
        timeZone: 'America/Sao_Paulo',
        weekday: 'short',
        day: 'numeric',
        month: 'long',
    }).format(now.value),
)
const groups = [
    { label: 'PRINCIPAL', links: [{ to: '/', label: 'Dashboard', icon: 'dashboard' }] },
    {
        label: 'GESTÃO DO DIA A DIA',
        links: [
            { to: '/atendimentos', label: 'Atendimentos', icon: 'tickets' },
            { to: '/pendencias', label: 'Pendências', icon: 'tasks' },
            { to: '/lembretes', label: 'Lembretes', icon: 'bell' },
            { to: '/conhecimento', label: 'Base de conhecimento', icon: 'book' },
        ],
    },
    {
        label: 'CADASTROS',
        links: [
            { to: '/contatos', label: 'Contatos', icon: 'users' },
            { to: '/clientes', label: 'Clientes', icon: 'building' },
            { to: '/sistemas', label: 'Sistemas', icon: 'layers' },
        ],
    },
]
watch(
    () => route.fullPath,
    () => {
        mobileOpen.value = false
    },
)
onBeforeUnmount(() => {
    clearInterval(clock)
    mobileMedia?.removeEventListener('change', updateLayout)
})
</script>

<template>
    <a href="#main-content" class="skip-link">Ir para o conteúdo</a>
    <div v-if="mobileOpen" class="sidebar-scrim" @click="mobileOpen = false"></div>
    <aside
        class="sidebar"
        :class="{ 'sidebar-open': mobileOpen }"
        :inert="mobileLayout && !mobileOpen"
        @keydown.esc="mobileOpen = false"
    >
        <RouterLink to="/" class="brand"
            ><span class="brand-mark"><AppIcon name="logo" :size="28" /></span
            ><span
                >Support<span class="brand-accent">Flow</span
                ><small>Seu suporte, em dia.</small></span
            ></RouterLink
        ><button
            type="button"
            class="icon-button sidebar-mobile-close"
            aria-label="Fechar navegação"
            @click="mobileOpen = false"
        >
            <AppIcon name="close" />
        </button>
        <nav aria-label="Navegação principal">
            <div v-for="group in groups" :key="group.label" class="nav-group">
                <p>{{ group.label }}</p>
                <RouterLink
                    v-for="link in group.links"
                    :key="link.to"
                    :to="link.to"
                    active-class="active"
                    class="nav-link"
                    ><AppIcon :name="link.icon" :size="20" /><span>{{
                        link.label
                    }}</span></RouterLink
                >
            </div>
        </nav>
        <div class="sidebar-footer">
            <span class="workspace-avatar">SF</span>
            <div><strong>Meu espaço</strong><small>Organização pessoal</small></div>
            <span class="live-dot"></span>
        </div>
    </aside>
    <div class="app-body" :inert="mobileLayout && mobileOpen">
        <header class="topbar">
            <div class="breadcrumb">
                <button
                    type="button"
                    class="icon-button mobile-menu"
                    aria-label="Abrir navegação"
                    :aria-expanded="mobileOpen"
                    @click="mobileOpen = !mobileOpen"
                >
                    <AppIcon name="menu" /></button
                ><span class="breadcrumb-root">Meu espaço</span
                ><AppIcon name="chevron" :size="13" /><strong>{{ route.meta.title }}</strong>
            </div>
            <div class="topbar-date">
                <AppIcon name="calendar" :size="16" /><span>{{ today }}</span>
            </div>
        </header>
        <main id="main-content" class="main-content" tabindex="-1">
            <RouterView v-slot="{ Component }"
                ><component :is="Component" :key="route.path"
            /></RouterView>
        </main>
        <footer class="app-footer">
            <span>SupportFlow</span><span>Menos pendências. Mais clareza.</span>
        </footer>
    </div>
    <ToastList />
</template>
