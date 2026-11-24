<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ticketSubcategories } from '@/utils/format'
import { queryFilters, ticketFilters, vehicleFilters } from '@/config/ticketFilters'
import { useList } from '@/composables/useList'
import AppIcon from '@/components/AppIcon.vue'
import FilterBar from '@/components/FilterBar.vue'
import PageHeading from '@/components/PageHeading.vue'
import PaginationBar from '@/components/PaginationBar.vue'
import RecordTable from '@/components/RecordTable.vue'
import StatePanel from '@/components/StatePanel.vue'
const route = useRoute(),
    router = useRouter()
const appliedTickets = computed(() => queryFilters(route.query, ticketFilters))
const appliedVehicles = computed(() => queryFilters(route.query, vehicleFilters))
const activeVehicleFilters = computed(() => Object.keys(appliedVehicles.value).length)
const params = computed(() => ({
    ...appliedTickets.value,
    ...appliedVehicles.value,
    page: route.query.page,
    per_page: route.query.per_page,
}))
const { rows, meta, total, loading, error, failure, reload } = useList('support-tickets', params)
const listError = computed(() =>
    failure.value?.status === 422
        ? [...new Set(Object.values(failure.value.errors).flat())].join(' ') || error.value
        : error.value,
)
function applyFilters(filters, retained) {
    return router.replace({
        query: {
            ...queryFilters({ ...retained, ...filters }, [...ticketFilters, ...vehicleFilters]),
            page: 1,
            per_page: route.query.per_page,
        },
    })
}
const tableRows = computed(() =>
    rows.value.map((row) => ({ ...row, problem_subcategories: ticketSubcategories(row) })),
)
const columns = [
    { key: 'title', label: 'Atendimento', primary: true },
    { key: 'system', label: 'Sistema', type: 'entity' },
    { key: 'contact', label: 'Contato', type: 'entity' },
    { key: 'customer', label: 'Cliente', type: 'entity' },
    { key: 'problem_subcategories', label: 'Subcategorias', type: 'entities' },
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
            :fields="ticketFilters"
            :values="appliedTickets"
            @apply="applyFilters($event, appliedVehicles)"
        />
        <details class="vehicle-filters">
            <summary>
                Filtros veiculares
                <span v-if="activeVehicleFilters" class="count-pill">
                    {{ activeVehicleFilters }} {{ activeVehicleFilters === 1 ? 'ativo' : 'ativos' }}
                </span>
            </summary>
            <FilterBar
                :fields="vehicleFilters"
                :values="appliedVehicles"
                label="Filtros veiculares"
                clear-label="Limpar filtros veiculares"
                @apply="applyFilters($event, appliedTickets)"
            />
        </details>
        <StatePanel
            v-if="loading || error || !rows.length"
            :loading="loading"
            :error="listError"
            title="Nenhum atendimento por aqui"
            description="Registre um novo atendimento ou ajuste os filtros da pesquisa."
            @retry="reload"
            ><RouterLink class="btn btn-primary" to="/atendimentos/novo"
                ><AppIcon name="plus" :size="16" />Novo atendimento</RouterLink
            ></StatePanel
        ><RecordTable
            v-else
            :rows="tableRows"
            :columns="columns"
            @view="router.push('/atendimentos/' + $event.id)"
            @edit="router.push('/atendimentos/' + $event.id + '/editar')"
        >
            <template #primary-context="{ row }">
                <div v-if="row.vehicle" class="ticket-vehicle-context">
                    <strong>{{ row.vehicle.plate }}</strong>
                    <span
                        v-if="row.vehicle.brand?.name || row.vehicle.model_description"
                        class="line-clamp"
                        :title="
                            [row.vehicle.brand?.name, row.vehicle.model_description]
                                .filter(Boolean)
                                .join(' · ')
                        "
                    >
                        {{
                            [row.vehicle.brand?.name, row.vehicle.model_description]
                                .filter(Boolean)
                                .join(' · ')
                        }}
                    </span>
                </div>
            </template> </RecordTable
        ><PaginationBar
            v-if="!error"
            :meta="meta"
            :loading="loading"
            @change="router.replace({ query: { ...route.query, page: $event } })"
        />
    </section>
</template>
<style scoped>
.vehicle-filters {
    border-bottom: 1px solid var(--line);
}
.vehicle-filters summary {
    padding: 13px 22px;
    cursor: pointer;
    color: #536179;
    font-size: 12px;
    font-weight: 600;
}
.vehicle-filters .filter-bar {
    border-bottom: 0;
}
.ticket-vehicle-context {
    display: grid;
    gap: 3px;
    margin-top: 6px;
    max-width: 27ch;
    color: #65738b;
    font-size: 10px;
    overflow-wrap: anywhere;
}
.ticket-vehicle-context strong {
    color: var(--blue);
    letter-spacing: 0.03em;
}
</style>
