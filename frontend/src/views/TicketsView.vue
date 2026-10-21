<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ticketStatuses } from '@/utils/format'
import { useList } from '@/composables/useList'
import AppIcon from '@/components/AppIcon.vue'
import FilterBar from '@/components/FilterBar.vue'
import PageHeading from '@/components/PageHeading.vue'
import PaginationBar from '@/components/PaginationBar.vue'
import RecordTable from '@/components/RecordTable.vue'
import StatePanel from '@/components/StatePanel.vue'
const route = useRoute(),
    router = useRouter()
const fields = [
    {
        name: 'q',
        label: 'Pesquisar',
        type: 'search',
        placeholder: 'Buscar por título ou descrição…',
    },
    { name: 'status', label: 'Status', type: 'select', options: ticketStatuses },
    { name: 'system_id', label: 'Sistema', type: 'relation', endpoint: 'systems' },
    { name: 'contact_id', label: 'Contato', type: 'relation', endpoint: 'contacts' },
    { name: 'customer_id', label: 'Cliente', type: 'relation', endpoint: 'customers' },
    { name: 'created_from', label: 'Abertura · de', type: 'date' },
    { name: 'created_to', label: 'Abertura · até', type: 'date' },
]
const params = computed(() =>
    Object.fromEntries(
        ['page', 'per_page', ...fields.map((field) => field.name)]
            .filter((key) => route.query[key] !== undefined)
            .map((key) => [key, route.query[key]]),
    ),
)
const { rows, meta, total, loading, error, reload } = useList('support-tickets', params)
const columns = [
    { key: 'title', label: 'Atendimento', primary: true },
    { key: 'system', label: 'Sistema', type: 'entity' },
    { key: 'contact', label: 'Contato', type: 'entity' },
    { key: 'customer', label: 'Cliente', type: 'entity' },
    { key: 'status', label: 'Status', type: 'badge' },
    { key: 'created_at', label: 'Abertura', type: 'date' },
]
</script>
<template>
    <PageHeading
        title="Atendimentos"
        description="Acompanhe cada solicitação, do primeiro contato à resolução."
        eyebrow="SEU ESPAÇO DE TRABALHO"
        ><RouterLink to="/atendimentos/novo" class="btn btn-primary"
            ><AppIcon name="plus" :size="18" />Novo atendimento</RouterLink
        ></PageHeading
    >
    <section class="panel">
        <div class="panel-heading">
            <div class="section-title">
                <AppIcon name="tickets" />
                <h2>Todos os atendimentos</h2>
                <span class="count-pill">{{ total }}</span>
            </div>
            <button
                class="icon-button"
                type="button"
                :disabled="loading"
                aria-label="Atualizar atendimentos"
                @click="reload"
            >
                <AppIcon name="refresh" :size="18" />
            </button>
        </div>
        <FilterBar
            :fields="fields"
            :values="route.query"
            @apply="router.replace({ query: { ...$event, page: 1 } })"
        /><StatePanel
            v-if="loading || error || !rows.length"
            :loading="loading"
            :error="error"
            title="Nenhum atendimento por aqui"
            description="Registre um novo atendimento ou ajuste os filtros da pesquisa."
            @retry="reload"
            ><RouterLink class="btn btn-primary" to="/atendimentos/novo"
                ><AppIcon name="plus" :size="16" />Novo atendimento</RouterLink
            ></StatePanel
        ><RecordTable
            v-else
            :rows="rows"
            :columns="columns"
            @view="router.push('/atendimentos/' + $event.id)"
            @edit="router.push('/atendimentos/' + $event.id + '/editar')"
        /><PaginationBar
            v-if="!error"
            :meta="meta"
            :loading="loading"
            @change="router.replace({ query: { ...route.query, page: $event } })"
        />
    </section>
</template>
