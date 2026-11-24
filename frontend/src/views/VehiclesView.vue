<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { useList } from '@/composables/useList'
import { useNotifications } from '@/composables/useNotifications'
import { vehicleBrands, vehicleLineLabel, vehicles } from '@/config/vehicleCatalog'
import AppIcon from '@/components/AppIcon.vue'
import AppModal from '@/components/AppModal.vue'
import FilterBar from '@/components/FilterBar.vue'
import PageHeading from '@/components/PageHeading.vue'
import PaginationBar from '@/components/PaginationBar.vue'
import RecordTable from '@/components/RecordTable.vue'
import ResourceForm from '@/components/ResourceForm.vue'
import StatePanel from '@/components/StatePanel.vue'
import VehicleFormModal from '@/components/VehicleFormModal.vue'

const tabs = [
    { id: 'vehicles', label: 'Veículos', config: vehicles },
    { id: 'brands', label: 'Marcas', config: vehicleBrands },
]
const indicators = [
    { key: 'total', label: 'Total de veículos', icon: 'car', color: 'blue' },
    { key: 'light', label: 'Linha leve', icon: 'car', color: 'green' },
    { key: 'heavy', label: 'Linha pesada', icon: 'car', color: 'violet' },
]
const activeTab = ref('vehicles')
const listParams = reactive({ vehicles: { page: 1 }, brands: { page: 1 } })
const params = computed(() => listParams[activeTab.value])
const config = computed(() => tabs.find((tab) => tab.id === activeTab.value).config)
const isVehicles = computed(() => activeTab.value === 'vehicles')
const { rows, total, meta, loading, error, reload } = useList(() => config.value.endpoint, params)
const { notify } = useNotifications()
const formOpen = ref(false)
const editing = ref(null)
const detail = ref(null)
const hasFilters = computed(() => config.value.filters.some((field) => params.value[field.name]))
const recordName = (record) => record.plate ?? record.name
const detailFields = computed(() => {
    const record = detail.value
    return isVehicles.value
        ? [
              { label: 'Placa', value: record.plate },
              { label: 'Marca', value: record.brand?.name ?? 'Marca não informada' },
              { label: 'Linha veicular', value: vehicleLineLabel(record.vehicle_line) },
              { label: 'Atendimentos vinculados', value: record.support_tickets_count ?? '—' },
              {
                  label: 'Descrição do modelo',
                  value: record.model_description || 'Modelo não informado',
                  full: true,
              },
          ]
        : [
              { label: 'Nome da marca', value: record.name },
              { label: 'Veículos vinculados', value: record.vehicles_count ?? '—' },
          ]
})

function changePage(page) {
    params.value.page = page
}

function applyFilters(filters) {
    listParams[activeTab.value] = { ...filters, page: 1 }
}

watch(meta, (pagination) => {
    if (pagination?.current_page > pagination?.last_page) changePage(pagination.last_page)
})

async function moveTab(event) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return
    event.preventDefault()
    const index = tabs.findIndex((tab) => tab.id === activeTab.value)
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? 1 : (index + 1) % tabs.length
    activeTab.value = tabs[next].id
    await nextTick()
    document.getElementById('catalog-tab-' + activeTab.value)?.focus()
}

function openForm(record = null) {
    detail.value = null
    editing.value = record
    formOpen.value = true
}

function saved() {
    formOpen.value = false
    notify(isVehicles.value ? 'Veículo salvo com sucesso.' : 'Marca salva com sucesso.')
    reload()
}

function reportFailure(error) {
    if (error.status !== 422) notify(error.message, 'error')
}
</script>

<template>
    <PageHeading title="Veículos">
        <button type="button" class="btn btn-primary" aria-haspopup="dialog" @click="openForm()">
            <AppIcon name="plus" :size="18" />{{ config.newLabel }}
        </button>
    </PageHeading>
    <div class="catalog-tabs" role="tablist" aria-label="Cadastros veiculares" @keydown="moveTab">
        <button
            v-for="tab in tabs"
            :id="'catalog-tab-' + tab.id"
            :key="tab.id"
            type="button"
            role="tab"
            :aria-selected="activeTab === tab.id"
            :aria-controls="'catalog-panel-' + tab.id"
            :tabindex="activeTab === tab.id ? 0 : -1"
            @click="activeTab = tab.id"
        >
            {{ tab.label }}
        </button>
    </div>

    <section
        v-for="tab in tabs"
        v-show="activeTab === tab.id"
        :id="'catalog-panel-' + tab.id"
        :key="tab.id"
        role="tabpanel"
        :aria-labelledby="'catalog-tab-' + tab.id"
    >
        <template v-if="activeTab === tab.id">
            <div
                v-if="isVehicles && meta?.counts"
                class="metric-grid catalog-indicators"
                aria-label="Totais do cadastro"
            >
                <div v-for="indicator in indicators" :key="indicator.key" class="metric-card">
                    <div class="metric-top">
                        <span class="metric-icon" :class="indicator.color">
                            <AppIcon :name="indicator.icon" :size="21" />
                        </span>
                    </div>
                    <strong>{{ meta.counts[indicator.key] }}</strong>
                    <span>{{ indicator.label }}</span>
                </div>
            </div>

            <div class="panel">
                <div class="panel-heading">
                    <div class="section-title">
                        <AppIcon :name="config.icon" />
                        <h2>{{ isVehicles ? 'Veículos cadastrados' : 'Marcas cadastradas' }}</h2>
                        <span class="count-pill">{{ total }}</span>
                    </div>
                    <button
                        class="icon-button"
                        type="button"
                        :disabled="loading"
                        aria-label="Atualizar listagem"
                        @click="reload"
                    >
                        <AppIcon name="refresh" :size="18" />
                    </button>
                </div>
                <FilterBar :fields="config.filters" :values="params" @apply="applyFilters" />
                <StatePanel
                    v-if="loading || error || !rows.length"
                    :loading="loading"
                    :error="error"
                    :title="
                        hasFilters
                            ? 'Nenhum resultado encontrado'
                            : isVehicles
                              ? 'Nenhum veículo cadastrado'
                              : 'Nenhuma marca cadastrada'
                    "
                    :description="
                        hasFilters
                            ? 'Ajuste ou limpe os filtros para pesquisar novamente.'
                            : 'Use o botão de cadastro para adicionar o primeiro registro.'
                    "
                    @retry="reload"
                />
                <RecordTable v-else :rows="rows" :columns="config.columns" @view="detail = $event">
                    <template #cell-model_description="{ row }">
                        <span v-if="row.model_description" class="model-preview muted">
                            {{ row.model_description }}
                        </span>
                        <span v-else class="muted">Modelo não informado</span>
                    </template>
                    <template #cell-vehicle_line="{ row }">
                        <span class="badge" :class="'line-' + row.vehicle_line">
                            <span class="badge-dot"></span>{{ vehicleLineLabel(row.vehicle_line) }}
                        </span>
                    </template>
                    <template #actions="{ row }">
                        <RouterLink
                            v-if="isVehicles"
                            class="icon-button"
                            :to="{ name: 'vehicle-history', params: { id: row.id } }"
                            :aria-label="'Histórico de atendimentos de ' + row.plate"
                            title="Histórico de atendimentos"
                        >
                            <AppIcon name="clock" :size="17" />
                        </RouterLink>
                        <button
                            type="button"
                            class="icon-button"
                            :aria-label="'Visualizar ' + recordName(row)"
                            @click="detail = row"
                        >
                            <AppIcon name="chevron" :size="17" />
                        </button>
                        <button
                            type="button"
                            class="icon-button"
                            :aria-label="'Editar ' + recordName(row)"
                            @click="openForm(row)"
                        >
                            <AppIcon name="edit" :size="17" />
                        </button>
                    </template>
                </RecordTable>
                <PaginationBar :meta="meta" :loading="loading" @change="changePage" />
            </div>
        </template>
    </section>

    <AppModal v-if="detail" :title="recordName(detail)" @close="detail = null">
        <dl class="detail-grid catalog-detail">
            <div
                v-for="field in detailFields"
                :key="field.label"
                :class="{ 'field-full': field.full }"
            >
                <dt>{{ field.label }}</dt>
                <dd>{{ field.value }}</dd>
            </div>
        </dl>
        <template #footer>
            <RouterLink
                v-if="isVehicles"
                class="btn btn-secondary"
                :to="{ name: 'vehicle-history', params: { id: detail.id } }"
            >
                <AppIcon name="clock" :size="16" />Histórico de atendimentos
            </RouterLink>
            <button type="button" class="btn btn-secondary" @click="detail = null">Fechar</button>
        </template>
    </AppModal>
    <VehicleFormModal
        v-if="formOpen && isVehicles"
        :record="editing"
        @close="formOpen = false"
        @saved="saved"
    />
    <ResourceForm
        v-else-if="formOpen"
        :config="vehicleBrands"
        :record="editing"
        prefix="vehicle-brand"
        @close="formOpen = false"
        @saved="saved"
        @failed="reportFailure"
    />
</template>

<style scoped>
.catalog-tabs {
    display: flex;
    gap: 6px;
    border-bottom: 1px solid var(--line);
    margin-bottom: 25px;
}
.catalog-tabs button {
    border: 0;
    border-bottom: 2px solid transparent;
    padding: 11px 18px;
    background: transparent;
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
}
.catalog-tabs button[aria-selected='true'] {
    color: var(--blue);
    border-bottom-color: var(--blue);
}
.catalog-indicators.metric-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}
.catalog-indicators .metric-card:hover {
    transform: none;
    border-color: #e7ebf2;
}
.model-preview {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    overflow: hidden;
    overflow-wrap: anywhere;
    max-width: 37ch;
}
.line-light {
    background: #eaf8f1;
    color: #28704d;
}
.line-heavy {
    background: #f1edff;
    color: #68459d;
}
.catalog-detail > div {
    min-width: 0;
}
.catalog-detail dd {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
@media (max-width: 520px) {
    .catalog-indicators.metric-grid {
        gap: 8px;
    }
    .catalog-indicators .metric-card {
        display: flex;
        grid-column: auto;
        align-items: stretch;
        gap: 0;
        padding: 11px;
    }
    .catalog-indicators .metric-top {
        margin: 0 0 9px;
    }
}
</style>
