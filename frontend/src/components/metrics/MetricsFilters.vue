<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { api } from '@/services/api'
import { defaultMetricFilters, metricParams, metricPeriods } from '@/config/ticketMetrics'
import { queryFilters, vehicleFilters } from '@/config/ticketFilters'
import { ticketStatuses } from '@/utils/format'
import FilterFields from '@/components/FilterFields.vue'
import StatePanel from '@/components/StatePanel.vue'
const props = defineProps({ applied: { type: Object, required: true } })
const emit = defineEmits(['apply', 'catalog'])
const draft = reactive(defaultMetricFilters())
const vehicleDraft = ref({})
const activeVehicleFilters = computed(
    () => Object.keys(queryFilters(props.applied, vehicleFilters)).length,
)
const catalog = ref({ systems: [], categories: [], subcategories: [] })
const loading = ref(true),
    error = ref(''),
    validation = ref(''),
    notice = ref('')
let controller
async function allPages(endpoint, signal) {
    const rows = []
    let page = 1,
        last = 1
    do {
        const result = await api.get(endpoint, { page, per_page: 100 }, signal)
        if (signal.aborted) return []
        rows.push(...result.data)
        last = result.meta.last_page
        page++
    } while (page <= last)
    return rows
}
async function loadCatalog() {
    controller?.abort()
    const active = (controller = new AbortController())
    loading.value = true
    error.value = ''
    try {
        const [systems, categories, subcategories] = await Promise.all(
            ['systems', 'problem-categories', 'problem-subcategories'].map((endpoint) =>
                allPages(endpoint, active.signal),
            ),
        )
        if (!active.signal.aborted) {
            catalog.value = { systems, categories, subcategories }
            emit('catalog', catalog.value)
        }
    } catch (exception) {
        if (!active.signal.aborted) {
            error.value = exception.message
            active.abort()
        }
    } finally {
        if (controller === active) loading.value = false
    }
}
const associated = (row) =>
    !draft.system_id ||
    row.system_associations.some((system) => String(system.id) === String(draft.system_id))
const categories = computed(() => catalog.value.categories.filter(associated))
const subcategories = computed(() =>
    catalog.value.subcategories.filter(
        (row) => String(row.problem_category_id) === String(draft.category) && associated(row),
    ),
)
function changeSystem() {
    if (
        draft.category &&
        draft.category !== 'unclassified' &&
        !categories.value.some((row) => String(row.id) === String(draft.category))
    ) {
        draft.category = ''
        draft.subcategory = ''
        notice.value =
            'A classificação foi redefinida porque não possui vínculo com o sistema selecionado. Aplique os filtros para atualizar os resultados.'
    } else if (
        draft.subcategory &&
        draft.subcategory !== 'without_subcategory' &&
        !subcategories.value.some((row) => String(row.id) === String(draft.subcategory))
    ) {
        draft.subcategory = ''
        notice.value =
            'A subcategoria foi redefinida porque não possui vínculo com o sistema selecionado. Aplique os filtros para atualizar os resultados.'
    } else notice.value = ''
}
function changeCategory() {
    draft.subcategory = ''
    notice.value = ''
}
function apply() {
    validation.value = ''
    if (
        draft.period === 'custom' &&
        (!draft.date_from ||
            !draft.date_to ||
            draft.date_from > draft.date_to ||
            draft.date_from < '1000-01-01' ||
            draft.date_to > '9999-12-30')
    ) {
        validation.value =
            'Informe as duas datas, com a data final igual ou posterior à inicial, entre 01/01/1000 e 30/12/9999.'
        return
    }
    notice.value = ''
    const available = vehicleFilters.filter((field) => !field.disabledWhen?.(vehicleDraft.value))
    emit('apply', { ...metricParams(draft), ...queryFilters(vehicleDraft.value, available) })
}
function reset() {
    Object.assign(draft, defaultMetricFilters())
    vehicleDraft.value = {}
    apply()
}
function clearVehicleFilters() {
    vehicleDraft.value = {}
    const filters = { ...props.applied }
    for (const { name } of vehicleFilters) delete filters[name]
    emit('apply', filters)
}
loadCatalog()
onBeforeUnmount(() => controller?.abort())
</script>
<template>
    <section class="panel metrics-filters" aria-labelledby="metrics-filters-title">
        <div class="panel-heading"><h2 id="metrics-filters-title">Filtros estatísticos</h2></div>
        <StatePanel v-if="error" compact :error="error" @retry="loadCatalog" />
        <form class="metrics-filter-form" aria-label="Filtros estatísticos" @submit.prevent="apply">
            <div class="filter-field">
                <label for="metrics-period">Período</label
                ><select id="metrics-period" v-model="draft.period" class="input">
                    <option
                        v-for="period in metricPeriods"
                        :key="period.value"
                        :value="period.value"
                    >
                        {{ period.label }}
                    </option>
                </select>
            </div>
            <template v-if="draft.period === 'custom'">
                <div class="filter-field">
                    <label for="metrics-date-from">Data inicial</label
                    ><input
                        id="metrics-date-from"
                        v-model="draft.date_from"
                        class="input"
                        type="date"
                        min="1000-01-01"
                        max="9999-12-30"
                        required
                        :aria-invalid="!!validation"
                        :aria-describedby="validation ? 'metrics-date-error' : undefined"
                    />
                </div>
                <div class="filter-field">
                    <label for="metrics-date-to">Data final</label
                    ><input
                        id="metrics-date-to"
                        v-model="draft.date_to"
                        class="input"
                        type="date"
                        :min="draft.date_from || '1000-01-01'"
                        max="9999-12-30"
                        required
                        :aria-invalid="!!validation"
                        :aria-describedby="validation ? 'metrics-date-error' : undefined"
                    />
                </div>
            </template>
            <div class="filter-field">
                <label for="metrics-system">Sistema</label
                ><select
                    id="metrics-system"
                    v-model="draft.system_id"
                    class="input"
                    :disabled="loading || !!error"
                    @change="changeSystem"
                >
                    <option value="">Todos os sistemas</option>
                    <option v-for="row in catalog.systems" :key="row.id" :value="row.id">
                        {{ row.name }}{{ row.is_active ? '' : ' (inativo)' }}
                    </option>
                </select>
            </div>
            <div class="filter-field">
                <label for="metrics-status">Status</label
                ><select id="metrics-status" v-model="draft.status" class="input">
                    <option value="all">Todos os status</option>
                    <option value="not_closed">Não encerrados</option>
                    <option
                        v-for="status in ticketStatuses"
                        :key="status.value"
                        :value="status.value"
                    >
                        {{ status.label }}
                    </option>
                </select>
            </div>
            <div class="filter-field">
                <label for="metrics-category">Categoria</label
                ><select
                    id="metrics-category"
                    v-model="draft.category"
                    class="input"
                    :disabled="loading || !!error"
                    @change="changeCategory"
                >
                    <option value="">Todas as categorias</option>
                    <option value="unclassified">Sem classificação</option>
                    <option v-for="row in categories" :key="row.id" :value="row.id">
                        {{ row.name }}{{ row.is_active ? '' : ' (inativa)' }}
                    </option>
                </select>
            </div>
            <div class="filter-field">
                <label for="metrics-subcategory">Subcategoria</label
                ><select
                    id="metrics-subcategory"
                    v-model="draft.subcategory"
                    class="input"
                    :disabled="
                        loading || !!error || !draft.category || draft.category === 'unclassified'
                    "
                >
                    <option value="">Todas as subcategorias</option>
                    <option value="without_subcategory">Sem subcategoria</option>
                    <option v-for="row in subcategories" :key="row.id" :value="row.id">
                        {{ row.name }}{{ row.is_active ? '' : ' (inativa)' }}
                    </option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">Aplicar filtros</button
                ><button type="button" class="text-button" @click="reset">Redefinir filtros</button>
            </div>
            <details class="vehicle-filters metrics-vehicle-filters">
                <summary>
                    Filtros veiculares
                    <span v-if="activeVehicleFilters" class="count-pill">
                        {{ activeVehicleFilters }}
                        {{ activeVehicleFilters === 1 ? 'ativo' : 'ativos' }}
                    </span>
                </summary>
                <div class="filter-bar metrics-vehicle-fields">
                    <FilterFields v-model="vehicleDraft" :fields="vehicleFilters" />
                    <div class="filter-actions">
                        <button class="text-button" type="button" @click="clearVehicleFilters">
                            Limpar filtros veiculares
                        </button>
                    </div>
                </div>
            </details>
            <p
                v-if="validation"
                id="metrics-date-error"
                class="field-error metrics-filter-note"
                role="alert"
            >
                {{ validation }}
            </p>
            <p v-if="notice" class="metrics-filter-note" role="status">{{ notice }}</p>
            <p class="metrics-filter-note muted">
                {{ loading ? 'Carregando opções… ' : '' }}Altere as opções e aplique os filtros.
                Inclui cadastros e vínculos históricos; datas no horário de Brasília.
            </p>
        </form>
    </section>
</template>
