<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import StatePanel from '@/components/StatePanel.vue'
import VehicleMetricsTable from './VehicleMetricsTable.vue'
import { api } from '@/services/api'
import { metricDrilldown, metricNumber, metricPercentage } from '@/config/ticketMetrics'
import { vehicleLineLabel } from '@/config/vehicleCatalog'

const props = defineProps({ filters: { type: Object, required: true } })
const emit = defineEmits(['tickets'])
const open = ref(false)
const loading = ref(false)
const error = ref('')
const data = ref(null)
const dimension = ref('brand')
const level = ref('subcategory')
const group = ref('')
let controller

const indicators = [
    ['with_vehicle', 'Atendimentos com veículo'],
    ['without_vehicle', 'Atendimentos sem veículo'],
    ['identification_percentage', 'Atendimentos com identificação veicular'],
    ['distinct_vehicles', 'Veículos distintos'],
    ['distinct_models', 'Modelos distintos'],
    ['vehicles_without_model', 'Veículos distintos sem modelo'],
    ['light_tickets', 'Atendimentos de linha leve'],
    ['heavy_tickets', 'Atendimentos de linha pesada'],
]
const recurrence = [
    ['repeat_vehicles', 'Veículos com dois ou mais atendimentos'],
    ['repeat_tickets', 'Atendimentos desses veículos recorrentes'],
    ['max_tickets_per_vehicle', 'Maior número de atendimentos por veículo'],
]
const brand = { key: 'brand_name', label: 'Marca' }
const model = { key: 'model_description', label: 'Modelo' }
const line = { key: 'vehicle_line', label: 'Linha' }
const total = { key: 'total', label: 'Atendimentos' }
const vehicles = { key: 'vehicles', label: 'Veículos distintos' }
const brandColumns = [brand, total, vehicles, { key: 'percentage', label: 'Participação' }]
const modelColumns = [brand, model, line, total, vehicles]
const vehicleColumns = [{ key: 'plate', label: 'Placa' }, brand, model, line, total]
const problemColumns = computed(() => [
    ...(dimension.value === 'line' ? [line] : [brand]),
    ...(dimension.value === 'model' ? [model] : []),
    { key: 'system_name', label: 'Sistema' },
    { key: 'category_name', label: 'Categoria' },
    ...(level.value === 'subcategory'
        ? [
              { key: 'subcategory_name', label: 'Subcategoria' },
              { key: 'total', label: 'Ocorrências' },
          ]
        : [total]),
])
const modelLabel = (row) => row.model_description || 'Modelo não informado'
function groupKey(row) {
    if (dimension.value === 'line') return row.vehicle_line
    if (dimension.value === 'model') return row.brand_id + ':' + row.model_key
    return String(row.brand_id)
}
function groupLabel(row) {
    if (dimension.value === 'line') return vehicleLineLabel(row.vehicle_line)
    if (dimension.value === 'model') return row.brand_name + ' · ' + modelLabel(row)
    return row.brand_name
}
const groups = computed(() => {
    const source = { brand: data.value?.brands, model: data.value?.models, line: data.value?.lines }
    return (source[dimension.value] || []).filter((row) => row.total)
})
const problemRows = computed(() =>
    (data.value?.problems[dimension.value] || []).filter(
        (row) => row.level === level.value && (!group.value || groupKey(row) === group.value),
    ),
)
async function load() {
    controller?.abort()
    const active = (controller = new AbortController())
    loading.value = true
    error.value = ''
    try {
        const result = await api.get('metrics/vehicles', props.filters, active.signal)
        if (!active.signal.aborted) data.value = result.data
    } catch (exception) {
        if (!active.signal.aborted)
            error.value =
                [...new Set(Object.values(exception.errors || {}).flat())].join(' ') ||
                exception.message
    } finally {
        if (controller === active) loading.value = false
    }
}
function toggle(event) {
    open.value = event.target.open
    if (open.value && !data.value && !loading.value) load()
}
watch(
    () => props.filters,
    () => {
        controller?.abort()
        data.value = null
        loading.value = false
        error.value = ''
        group.value = ''
        if (open.value) load()
    },
)
watch(dimension, () => {
    group.value = ''
})
onBeforeUnmount(() => controller?.abort())

function tickets(row, type, label) {
    const filters = row.level
        ? metricDrilldown(props.filters, row, row.level === 'subcategory')
        : { ...props.filters }
    if (row.level) filters.system_id = row.system_id
    if (type === 'line') filters.vehicle_line = row.vehicle_line
    if (type === 'brand' || type === 'model') filters.vehicle_brand_id = row.brand_id
    if (type === 'model') filters.group_vehicle_model = row.model_key
    if (type === 'vehicle') filters.group_vehicle_id = row.vehicle_id
    emit('tickets', { filters, label })
}
</script>

<template>
    <details class="panel metrics-vehicle-section" @toggle="toggle">
        <summary>Estatísticas veiculares</summary>
        <div v-if="open" class="metrics-vehicle-content" :aria-busy="loading">
            <StatePanel
                v-if="loading || error"
                compact
                :loading="loading"
                :error="error"
                @retry="load"
            />
            <template v-else-if="data">
                <div class="metric-grid metrics-indicators">
                    <article v-for="[key, label] in indicators" :key="key" class="metric-card">
                        <strong>{{
                            key === 'identification_percentage'
                                ? metricPercentage(data.summary[key])
                                : metricNumber(data.summary[key])
                        }}</strong>
                        <span>{{ label }}</span>
                    </article>
                </div>
                <StatePanel
                    v-if="!data.summary.with_vehicle"
                    compact
                    title="Nenhum atendimento com veículo"
                    description="Ajuste os filtros para consultar outro conjunto de atendimentos."
                />
                <template v-else>
                    <section class="panel" aria-label="Distribuição por linha veicular">
                        <div class="panel-heading">
                            <div>
                                <h3>Distribuição por linha veicular</h3>
                                <p class="metrics-caption">
                                    Participação entre os atendimentos com veículo nos filtros
                                    aplicados.
                                </p>
                            </div>
                        </div>
                        <ul class="metrics-bars">
                            <li v-for="row in data.lines" :key="row.vehicle_line">
                                <div class="metrics-bar-label">
                                    <button
                                        v-if="row.total"
                                        class="text-button"
                                        type="button"
                                        @click="
                                            tickets(row, 'line', vehicleLineLabel(row.vehicle_line))
                                        "
                                    >
                                        {{ vehicleLineLabel(row.vehicle_line) }}
                                    </button>
                                    <span v-else>{{ vehicleLineLabel(row.vehicle_line) }}</span>
                                    <span
                                        >{{ metricNumber(row.total) }} atendimentos ·
                                        {{ metricPercentage(row.percentage) }} ·
                                        {{ metricNumber(row.vehicles) }} veículos distintos</span
                                    >
                                </div>
                                <div class="metrics-bar-track" aria-hidden="true">
                                    <span :style="{ width: row.percentage + '%' }" />
                                </div>
                            </li>
                        </ul>
                    </section>
                    <VehicleMetricsTable
                        title="Ranking de marcas"
                        :rows="data.brands"
                        :columns="brandColumns"
                    >
                        <template #actions="{ row }">
                            <button
                                class="text-button"
                                type="button"
                                @click="tickets(row, 'brand', row.brand_name)"
                            >
                                Ver atendimentos
                            </button>
                        </template>
                    </VehicleMetricsTable>
                    <VehicleMetricsTable
                        title="Ranking de modelos"
                        :rows="data.models"
                        :columns="modelColumns"
                    >
                        <template #actions="{ row }">
                            <button
                                class="text-button"
                                type="button"
                                @click="
                                    tickets(row, 'model', row.brand_name + ' · ' + modelLabel(row))
                                "
                            >
                                Ver atendimentos
                            </button>
                        </template>
                    </VehicleMetricsTable>
                    <VehicleMetricsTable
                        title="Recorrência por placa"
                        :rows="data.vehicles"
                        :columns="vehicleColumns"
                    >
                        <template #controls>
                            <dl class="metrics-vehicle-recurrence">
                                <div v-for="[key, label] in recurrence" :key="key">
                                    <dt>{{ label }}</dt>
                                    <dd>{{ metricNumber(data.recurrence[key]) }}</dd>
                                </div>
                            </dl>
                        </template>
                        <template #actions="{ row }">
                            <div class="metrics-vehicle-actions">
                                <button
                                    class="text-button"
                                    type="button"
                                    @click="tickets(row, 'vehicle', row.plate)"
                                >
                                    Ver atendimentos
                                </button>
                                <RouterLink
                                    class="text-button"
                                    :to="{
                                        name: 'vehicle-history',
                                        params: { id: row.vehicle_id },
                                    }"
                                    >Histórico veicular</RouterLink
                                >
                            </div>
                        </template>
                    </VehicleMetricsTable>
                    <VehicleMetricsTable
                        title="Problemas recorrentes"
                        :rows="problemRows"
                        :columns="problemColumns"
                    >
                        <template #controls>
                            <div class="filter-bar metrics-problem-filters">
                                <div class="filter-field">
                                    <label for="metrics-vehicle-dimension">Agrupar por</label>
                                    <select
                                        id="metrics-vehicle-dimension"
                                        v-model="dimension"
                                        class="input"
                                    >
                                        <option value="brand">Marca</option>
                                        <option value="model">Modelo</option>
                                        <option value="line">Linha veicular</option>
                                    </select>
                                </div>
                                <div class="filter-field">
                                    <label for="metrics-vehicle-group">Grupo</label>
                                    <select
                                        id="metrics-vehicle-group"
                                        v-model="group"
                                        class="input"
                                    >
                                        <option value="">Todos os grupos</option>
                                        <option
                                            v-for="row in groups"
                                            :key="groupKey(row)"
                                            :value="groupKey(row)"
                                        >
                                            {{ groupLabel(row) }}
                                        </option>
                                    </select>
                                </div>
                                <div class="filter-field">
                                    <label for="metrics-vehicle-level">Classificação</label>
                                    <select
                                        id="metrics-vehicle-level"
                                        v-model="level"
                                        class="input"
                                    >
                                        <option value="category">Categoria</option>
                                        <option value="subcategory">Subcategoria</option>
                                    </select>
                                </div>
                                <p class="metrics-caption">
                                    {{
                                        level === 'category'
                                            ? 'Cada atendimento conta uma vez por categoria.'
                                            : 'Cada vínculo com uma subcategoria conta uma ocorrência. O total pode ultrapassar o número de atendimentos.'
                                    }}
                                </p>
                            </div>
                        </template>
                        <template #actions="{ row }">
                            <button
                                class="text-button"
                                type="button"
                                @click="
                                    tickets(
                                        row,
                                        dimension,
                                        groupLabel(row) +
                                            ' · ' +
                                            (row.subcategory_name || row.category_name),
                                    )
                                "
                            >
                                Ver atendimentos
                            </button>
                        </template>
                    </VehicleMetricsTable>
                    <p class="metrics-caption">
                        Os totais refletem os atendimentos registrados no SupportFlow.
                    </p>
                </template>
            </template>
        </div>
    </details>
</template>
