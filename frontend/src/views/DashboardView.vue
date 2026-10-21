<script setup>
import { onBeforeUnmount, ref } from 'vue'
import { api } from '@/services/api'
import AppIcon from '@/components/AppIcon.vue'
import DashboardSection from '@/components/DashboardSection.vue'
import PageHeading from '@/components/PageHeading.vue'
import StatePanel from '@/components/StatePanel.vue'
const data = ref(null),
    loading = ref(true),
    error = ref('')
let controller
async function load() {
    controller?.abort()
    controller = new AbortController()
    const active = controller
    loading.value = true
    error.value = ''
    try {
        const result = await api.get('dashboard/summary', undefined, active.signal)
        if (!active.signal.aborted) data.value = result.data
    } catch (exception) {
        if (!active.signal.aborted) error.value = exception.message
    } finally {
        if (!active.signal.aborted) loading.value = false
    }
}
const metrics = [
    {
        key: 'open',
        label: 'Atendimentos abertos',
        icon: 'tickets',
        color: 'blue',
        to: '/atendimentos?status=open',
    },
    {
        key: 'in_progress',
        label: 'Em andamento',
        icon: 'clock',
        color: 'violet',
        to: '/atendimentos?status=in_progress',
    },
    {
        key: 'waiting',
        label: 'Aguardando retorno',
        icon: 'users',
        color: 'amber',
        to: '/atendimentos?status=waiting',
    },
    {
        key: 'overdue_tasks',
        label: 'Pendências atrasadas',
        icon: 'alert',
        color: 'red',
        to: '/pendencias?filter=overdue',
    },
    {
        key: 'today_reminders',
        label: 'Lembretes de hoje',
        icon: 'bell',
        color: 'green',
        to: '/lembretes?filter=today',
    },
]
load()
onBeforeUnmount(() => controller?.abort())
</script>
<template>
    <PageHeading
        title="Seu dia, sob controle."
        description="Uma visão clara dos atendimentos e do que precisa da sua atenção."
        eyebrow="VISÃO GERAL"
        ><button type="button" class="btn btn-secondary" :disabled="loading" @click="load">
            <AppIcon name="refresh" :size="16" />Atualizar</button
        ><RouterLink class="btn btn-primary" to="/atendimentos/novo"
            ><AppIcon name="plus" :size="18" />Novo atendimento</RouterLink
        ></PageHeading
    ><StatePanel v-if="loading || error" :loading="loading" :error="error" @retry="load" /><template
        v-else-if="data"
        ><div class="metric-grid">
            <RouterLink
                v-for="metric in metrics"
                :key="metric.key"
                class="metric-card"
                :to="metric.to"
                ><div class="metric-top">
                    <span class="metric-icon" :class="metric.color"
                        ><AppIcon :name="metric.icon" :size="21" /></span
                    ><AppIcon name="arrow" :size="15" />
                </div>
                <strong>{{ data.counts[metric.key] }}</strong
                ><span>{{ metric.label }}</span></RouterLink
            >
        </div>
        <div class="quick-actions">
            <div>
                <span class="quick-icon"><AppIcon name="plus" :size="18" /></span>
                <div>
                    <strong>O próximo passo, a um clique.</strong>
                    <p>Registre agora para não precisar lembrar depois.</p>
                </div>
            </div>
            <div>
                <RouterLink class="btn btn-secondary btn-sm" to="/pendencias?novo=1"
                    ><AppIcon name="tasks" :size="16" />Nova pendência</RouterLink
                ><RouterLink class="btn btn-secondary btn-sm" to="/lembretes?novo=1"
                    ><AppIcon name="bell" :size="16" />Novo lembrete</RouterLink
                >
            </div>
        </div>
        <div class="dashboard-grid">
            <DashboardSection
                title="Atendimentos recentes"
                icon="tickets"
                kind="ticket"
                :rows="data.recent_tickets"
                link="/atendimentos"
                empty="Tudo pronto para o primeiro atendimento"
                class="dashboard-recent"
            /><DashboardSection
                title="Lembretes de hoje"
                icon="calendar"
                :rows="data.today_reminders"
                link="/lembretes?filter=today"
                empty="Nenhum lembrete para hoje"
            /><DashboardSection
                title="Pendências em atenção"
                icon="tasks"
                kind="task"
                :rows="data.overdue_tasks"
                link="/pendencias?filter=overdue"
                empty="Nenhuma pendência atrasada"
            /><DashboardSection
                title="Lembretes atrasados"
                icon="alert"
                :rows="data.overdue_reminders"
                link="/lembretes?filter=overdue"
                empty="Seus lembretes estão em dia"
            /><DashboardSection
                title="Próximos retornos"
                icon="clock"
                :rows="data.upcoming_reminders"
                link="/lembretes?filter=upcoming"
                empty="O caminho está livre por aqui"
            />
        </div>
        <p class="dashboard-footnote">
            <span class="live-dot"></span>Informações reais dos seus registros · Horário de Brasília
        </p></template
    >
</template>
