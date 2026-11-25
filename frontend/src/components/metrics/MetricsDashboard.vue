<script setup>
import { computed, onBeforeUnmount, ref, shallowRef } from 'vue'
import { api } from '@/services/api'
import {
    defaultMetricFilters,
    metricParams,
    metricDrilldown,
    metricNumber,
    metricPercentage,
    describeMetricFilters,
} from '@/config/ticketMetrics'
import PageHeading from '@/components/PageHeading.vue'
import AppIcon from '@/components/AppIcon.vue'
import StatePanel from '@/components/StatePanel.vue'
import MetricsFilters from './MetricsFilters.vue'
import MetricsSystemsChart from './MetricsSystemsChart.vue'
import MetricsMonthlyChart from './MetricsMonthlyChart.vue'
import MetricsTicketsModal from './MetricsTicketsModal.vue'
import MetricsExportModal from './MetricsExportModal.vue'
import MetricsPdfConfigurationModal from './MetricsPdfConfigurationModal.vue'
import VehicleMetricsSection from './VehicleMetricsSection.vue'
import './metrics.css'
const applied = ref(metricParams(defaultMetricFilters()))
const result = ref(null),
    loading = ref(true),
    error = ref(''),
    fieldErrors = ref([])
const selectedKey = ref(null),
    drilldown = ref(null)
const exportOpen = ref(false),
    catalog = ref({})
const pdfScope = shallowRef(null)
const pdfDraft = ref(null)
const exportCriteria = computed(() =>
    result.value ? describeMetricFilters(applied.value, result.value.filters, catalog.value) : [],
)
const categoryKey = (row) =>
    row.problem_category_id === null ? 'unclassified' : String(row.problem_category_id)
const selected = computed(() =>
    result.value?.categories.find((row) => categoryKey(row) === selectedKey.value),
)
const subcategories = computed(
    () =>
        result.value?.subcategories.filter(
            (row) => row.problem_category_id === selected.value?.problem_category_id,
        ) || [],
)
const subcategoryOccurrences = computed(() =>
    subcategories.value.reduce(
        (total, row) => total + (row.problem_subcategory_id === null ? 0 : row.total),
        0,
    ),
)
const indicators = [
    { key: 'total', label: 'Total de atendimentos', icon: 'tickets', color: 'blue' },
    { key: 'not_closed', label: 'Não encerrados', icon: 'clock', color: 'amber' },
    { key: 'closed', label: 'Encerrados', icon: 'check', color: 'green' },
    { key: 'systems_count', label: 'Sistemas com atendimentos', icon: 'layers', color: 'violet' },
]
let controller
async function load(params = applied.value) {
    if (controller && loading.value && JSON.stringify(params) === JSON.stringify(applied.value))
        return
    controller?.abort()
    const active = (controller = new AbortController())
    applied.value = { ...params }
    loading.value = true
    result.value = null
    error.value = ''
    fieldErrors.value = []
    drilldown.value = null
    exportOpen.value = false
    closePdfConfiguration()
    try {
        const [summary, systems, categories, subcategories, monthly] = await Promise.all(
            ['summary', 'systems', 'categories', 'subcategories', 'monthly'].map((endpoint) =>
                api.get('metrics/' + endpoint, params, active.signal),
            ),
        )
        if (!active.signal.aborted) {
            result.value = {
                summary: summary.data,
                filters: summary.meta.filters,
                systems: systems.data,
                categories: categories.data,
                subcategories: subcategories.data,
                monthly: monthly.data,
            }
            if (!categories.data.some((row) => categoryKey(row) === selectedKey.value))
                selectedKey.value = categories.data.length ? categoryKey(categories.data[0]) : null
        }
    } catch (exception) {
        if (!active.signal.aborted) {
            error.value = exception.message
            fieldErrors.value = Object.values(exception.errors || {}).flat()
            active.abort()
        }
    } finally {
        if (controller === active) loading.value = false
    }
}
function closePdfConfiguration() {
    pdfScope.value = null
    pdfDraft.value = null
}
function openPdfConfiguration() {
    if (!result.value) return
    pdfScope.value = {
        filters: { ...applied.value },
        resolvedFilters: { ...result.value.filters },
    }
}
function openTickets(row, isSubcategory = false) {
    drilldown.value = {
        filters: metricDrilldown(applied.value, row, isSubcategory),
        label: row
            ? isSubcategory
                ? row.subcategory_name
                : row.category_name
            : 'Todos os resultados filtrados',
    }
}
function dateLabel(value) {
    return value?.split('-').reverse().join('/')
}
load()
onBeforeUnmount(() => controller?.abort())
</script>
<template>
    <div class="metrics-dashboard">
        <PageHeading
            title="Métricas dos atendimentos"
            description="Explore o histórico, identifique recorrências e acompanhe a evolução dos seus atendimentos."
            eyebrow="ANÁLISE ESTATÍSTICA"
        >
            <div class="metrics-report-actions">
                <button
                    type="button"
                    class="btn btn-secondary"
                    :disabled="loading || !!error || !result"
                    @click="exportOpen = true"
                >
                    <AppIcon name="download" :size="16" />Exportar Excel
                </button>
                <button
                    type="button"
                    class="btn btn-secondary"
                    :disabled="loading || !!error || !result"
                    @click="openPdfConfiguration"
                >
                    <AppIcon name="download" :size="16" />Exportar PDF
                </button>
            </div>
        </PageHeading>
        <MetricsFilters :applied="applied" @apply="load" @catalog="catalog = $event" />
        <div :aria-busy="loading">
            <StatePanel v-if="loading || error" :loading="loading" :error="error" @retry="load()" />
            <ul v-if="error && fieldErrors.length" class="metrics-validation" role="alert">
                <li v-for="message in fieldErrors" :key="message">{{ message }}</li>
            </ul>
            <template v-else-if="result">
                <p class="metrics-applied-context" role="status">
                    Filtros aplicados ·
                    {{
                        result.filters.date_from
                            ? dateLabel(result.filters.date_from) +
                              ' a ' +
                              dateLabel(result.filters.date_to)
                            : 'Todo o histórico'
                    }}
                    · Horário de Brasília
                </p>
                <div class="metric-grid metrics-indicators">
                    <article
                        v-for="indicator in indicators"
                        :key="indicator.key"
                        class="metric-card"
                    >
                        <div class="metric-top">
                            <span class="metric-icon" :class="indicator.color"
                                ><AppIcon :name="indicator.icon" :size="21"
                            /></span>
                        </div>
                        <strong>{{ metricNumber(result.summary[indicator.key]) }}</strong
                        ><span>{{ indicator.label }}</span>
                    </article>
                </div>
                <StatePanel
                    v-if="!result.summary.total"
                    compact
                    title="Nenhum atendimento no período"
                    description="Não há ocorrências para os filtros aplicados. Ajuste as opções para consultar outro conjunto de registros."
                />
                <MetricsSystemsChart :rows="result.systems" />
                <MetricsMonthlyChart :rows="result.monthly" :period="applied.period" />
                <section class="panel" aria-labelledby="metrics-categories-title">
                    <div class="panel-heading">
                        <div>
                            <h2 id="metrics-categories-title">Categorias mais recorrentes</h2>
                            <p class="metrics-caption">
                                Selecione uma categoria para detalhar suas subcategorias.
                            </p>
                        </div>
                    </div>
                    <div v-if="result.categories.length" class="table-scroll">
                        <table class="metrics-ranking">
                            <caption class="sr-only">
                                Ranking por quantidade de ocorrências, da maior para a menor
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">Categoria</th>
                                    <th scope="col">Ocorrências</th>
                                    <th scope="col">Participação</th>
                                    <th scope="col">Consulta</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in result.categories"
                                    :key="categoryKey(row)"
                                    :class="{
                                        'metrics-row-selected': selectedKey === categoryKey(row),
                                    }"
                                >
                                    <th scope="row">
                                        <button
                                            class="text-button metrics-category-button"
                                            type="button"
                                            :aria-pressed="selectedKey === categoryKey(row)"
                                            :aria-label="'Detalhar ' + row.category_name"
                                            aria-controls="metrics-subcategories"
                                            @click="selectedKey = categoryKey(row)"
                                        >
                                            {{ row.category_name }}</button
                                        ><small
                                            v-if="row.is_active === false"
                                            class="metrics-inactive muted"
                                            >Inativa · Histórico preservado</small
                                        >
                                    </th>
                                    <td>{{ metricNumber(row.total) }}</td>
                                    <td>{{ metricPercentage(row.percentage) }}</td>
                                    <td>
                                        <button
                                            class="text-button"
                                            type="button"
                                            :aria-label="'Ver atendimentos de ' + row.category_name"
                                            @click="openTickets(row)"
                                        >
                                            Ver atendimentos
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <StatePanel
                        v-else
                        compact
                        title="Nenhuma categoria com ocorrências"
                        description="Não há categorias ou registros sem classificação para os filtros aplicados."
                    />
                </section>
                <section
                    id="metrics-subcategories"
                    class="panel"
                    aria-labelledby="metrics-subcategories-title"
                >
                    <div class="panel-heading">
                        <div>
                            <h2 id="metrics-subcategories-title">Detalhamento das subcategorias</h2>
                            <p class="metrics-caption" aria-live="polite">
                                {{
                                    selected
                                        ? selected.category_name +
                                          ' · ' +
                                          metricNumber(selected.total) +
                                          (selected.total === 1
                                              ? ' atendimento'
                                              : ' atendimentos') +
                                          ' · ' +
                                          metricNumber(subcategoryOccurrences) +
                                          (subcategoryOccurrences === 1 ? ' vínculo' : ' vínculos')
                                        : 'Selecione uma categoria no ranking acima.'
                                }}
                            </p>
                            <p v-if="subcategoryOccurrences" class="metrics-caption">
                                Cada vínculo conta uma ocorrência. O percentual usa os atendimentos
                                da categoria e pode somar mais de 100%.
                            </p>
                        </div>
                    </div>
                    <div v-if="subcategories.length" class="table-scroll">
                        <table>
                            <caption class="sr-only">
                                Subcategorias da categoria selecionada e participação dentro dela
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">Subcategoria</th>
                                    <th scope="col">Ocorrências</th>
                                    <th scope="col">Na categoria</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in subcategories"
                                    :key="row.problem_subcategory_id ?? 'none'"
                                >
                                    <th scope="row">
                                        <button
                                            class="text-button"
                                            type="button"
                                            :aria-label="
                                                'Ver atendimentos de ' + row.subcategory_name
                                            "
                                            @click="openTickets(row, true)"
                                        >
                                            {{ row.subcategory_name }}</button
                                        ><small
                                            v-if="row.is_active === false"
                                            class="metrics-inactive muted"
                                            >Inativa · Histórico preservado</small
                                        >
                                    </th>
                                    <td>{{ metricNumber(row.total) }}</td>
                                    <td>{{ metricPercentage(row.percentage) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <StatePanel
                        v-else
                        compact
                        title="Nenhuma subcategoria com ocorrências"
                        description="Selecione uma categoria com registros para consultar o detalhamento."
                    />
                </section>
                <section class="panel metrics-related" aria-labelledby="metrics-related-title">
                    <div>
                        <h2 id="metrics-related-title">Atendimentos relacionados</h2>
                        <p class="metrics-caption">
                            Consulte os registros que compõem os resultados, sem alterar a análise.
                        </p>
                    </div>
                    <button class="btn btn-secondary" type="button" @click="openTickets()">
                        Consultar atendimentos
                    </button>
                </section>
            </template>
        </div>
        <VehicleMetricsSection :filters="applied" @tickets="drilldown = $event" />
        <MetricsTicketsModal
            v-if="drilldown"
            :filters="drilldown.filters"
            :label="drilldown.label"
            @close="drilldown = null"
        />
        <MetricsExportModal
            v-if="exportOpen && result"
            :filters="applied"
            :criteria="exportCriteria"
            @close="exportOpen = false"
        />
        <MetricsPdfConfigurationModal
            v-if="pdfScope"
            v-model:draft="pdfDraft"
            :filters="pdfScope.filters"
            :resolved-filters="pdfScope.resolvedFilters"
            :filter-catalog="catalog"
            @close="closePdfConfiguration"
        />
    </div>
</template>

<style scoped>
.metrics-report-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 9px;
}
@media (max-width: 730px) {
    .metrics-report-actions {
        justify-content: flex-start;
    }
}
</style>
