<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useList } from '@/composables/useList'
import { historyFilters, queryFilters } from '@/config/ticketFilters'
import { formatDate, ticketSubcategories } from '@/utils/format'
import AppIcon from '@/components/AppIcon.vue'
import FilterBar from '@/components/FilterBar.vue'
import PageHeading from '@/components/PageHeading.vue'
import PaginationBar from '@/components/PaginationBar.vue'
import RecordTable from '@/components/RecordTable.vue'
import StatePanel from '@/components/StatePanel.vue'
import VehicleIdentity from '@/components/VehicleIdentity.vue'

const route = useRoute()
const router = useRouter()
const filters = computed(() => queryFilters(route.query, historyFilters))
const params = computed(() => ({
    ...filters.value,
    page: route.query.page,
    per_page: route.query.per_page,
}))
const { rows, meta, total, loading, error, failure, reload } = useList(
    () => 'vehicles/' + route.params.id + '/support-tickets',
    params,
)
const overview = ref(null)
watch(meta, (result) => {
    if (result?.vehicle) overview.value = { vehicle: result.vehicle, counts: result.counts }
    if (result && result.current_page > result.last_page) changePage(result.last_page)
})
const listError = computed(() => {
    if (failure.value?.status === 404) return 'Veículo não encontrado.'
    if (failure.value?.status === 422)
        return Object.values(failure.value.errors).flat().join(' ') || error.value
    return error.value
})
const columns = [
    { key: 'title', label: 'Atendimento', primary: true },
    { key: 'dates', label: 'Datas' },
    { key: 'system', label: 'Sistema', type: 'entity' },
    { key: 'contact', label: 'Contato', type: 'entity' },
    { key: 'customer', label: 'Cliente', type: 'entity' },
    { key: 'classification', label: 'Categoria e subcategorias' },
    { key: 'description', label: 'Problema' },
    { key: 'resolution', label: 'Solução aplicada' },
    { key: 'status', label: 'Status', type: 'badge' },
]
const indicators = [
    { key: 'total', label: 'Total de atendimentos' },
    { key: 'not_closed', label: 'Em aberto' },
    { key: 'closed', label: 'Encerrados' },
]

function ticketLocation(row) {
    return { name: 'ticket-detail', params: { id: row.id }, query: { retorno: route.fullPath } }
}
function applyFilters(values) {
    router.replace({
        query: { ...queryFilters(values, historyFilters), page: 1, per_page: route.query.per_page },
    })
}
function changePage(page) {
    router.replace({ query: { ...params.value, page } })
}
</script>

<template>
    <RouterLink class="back-link" :to="{ name: 'vehicles' }">
        <AppIcon name="back" :size="16" />Voltar aos veículos
    </RouterLink>
    <PageHeading title="Histórico de atendimentos" />
    <section
        v-if="overview"
        class="panel history-overview"
        aria-label="Veículo e histórico completo"
    >
        <VehicleIdentity :vehicle="overview.vehicle" />
        <dl class="history-counts">
            <div v-for="indicator in indicators" :key="indicator.key">
                <dt>{{ indicator.label }}</dt>
                <dd>{{ overview.counts[indicator.key] }}</dd>
            </div>
        </dl>
    </section>
    <section class="panel">
        <div class="panel-heading">
            <div class="section-title">
                <AppIcon name="tickets" />
                <h2>Atendimentos encontrados</h2>
                <span v-if="meta" class="count-pill">{{ total }}</span>
            </div>
            <button
                type="button"
                class="icon-button"
                aria-label="Atualizar histórico"
                :disabled="loading"
                @click="reload"
            >
                <AppIcon name="refresh" :size="17" />
            </button>
        </div>
        <FilterBar
            v-if="failure?.status !== 404"
            :fields="historyFilters"
            :values="filters"
            label="Filtros do histórico"
            @apply="applyFilters"
        />
        <StatePanel
            v-if="loading || listError || !rows.length"
            :loading="loading"
            :error="listError"
            :title="
                overview?.counts.total
                    ? 'Nenhum atendimento encontrado'
                    : 'Nenhum atendimento vinculado'
            "
            :description="
                overview?.counts.total
                    ? 'Ajuste ou limpe os filtros para pesquisar novamente.'
                    : 'Os atendimentos vinculados a este veículo aparecerão aqui.'
            "
            @retry="reload"
        />
        <RecordTable
            v-else
            :rows="rows"
            :columns="columns"
            @view="router.push(ticketLocation($event))"
        >
            <template #cell-dates="{ row }">
                <div class="history-dates">
                    <span>Abertura {{ formatDate(row.created_at) }}</span>
                    <span v-if="row.closed_at">Encerramento {{ formatDate(row.closed_at) }}</span>
                </div>
            </template>
            <template #cell-classification="{ row }">
                <span>{{ row.problem_category?.name || 'Não classificado' }}</span>
                <ul v-if="ticketSubcategories(row).length" class="history-subcategories">
                    <li v-for="subcategory in ticketSubcategories(row)" :key="subcategory.id">
                        {{ subcategory.name }}
                    </li>
                </ul>
            </template>
            <template #actions="{ row }">
                <RouterLink
                    :to="ticketLocation(row)"
                    class="icon-button"
                    :aria-label="'Ver detalhes do atendimento ' + row.id"
                    title="Ver detalhes"
                >
                    <AppIcon name="chevron" :size="17" />
                </RouterLink>
            </template>
        </RecordTable>
        <PaginationBar :meta="meta" :loading="loading" @change="changePage" />
    </section>
</template>

<style scoped>
.history-overview {
    display: grid;
    gap: 18px;
    margin-bottom: 22px;
    padding: 19px 22px;
}
.history-counts {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
}
.history-counts > div {
    padding: 10px 12px;
    border: 1px solid var(--line);
    border-radius: 7px;
}
.history-counts dt,
.history-dates,
.history-subcategories {
    color: #65738b;
    font-size: 11px;
}
.history-counts dd {
    margin: 4px 0 0;
    font-size: 20px;
    font-weight: 600;
}
.history-dates {
    display: grid;
    gap: 4px;
    min-width: 135px;
}
.history-subcategories {
    padding-left: 15px;
    margin: 5px 0 0;
}
@media (max-width: 520px) {
    .history-overview {
        padding: 16px;
    }
    .history-counts > div {
        padding: 8px;
    }
}
</style>
