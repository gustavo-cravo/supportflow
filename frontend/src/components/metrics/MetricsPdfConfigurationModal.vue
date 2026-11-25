<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, shallowRef, watch } from 'vue'
import AppModal from '@/components/AppModal.vue'
import AppIcon from '@/components/AppIcon.vue'
import FormField from '@/components/FormField.vue'
import StatePanel from '@/components/StatePanel.vue'
import { api } from '@/services/api'
import { useNotifications } from '@/composables/useNotifications'
import { saveDownload } from '@/utils/download'
import { describeMetricFilters } from '@/config/ticketMetrics'
import {
    automaticMetricsPdfTitle,
    createMetricsPdfDraft,
    effectiveMetricsPdfConfiguration,
    metricsPdfConfigurationEndpoint,
    resetPdfFormats,
    setPdfSection,
    summarizeMetricsPdf,
} from '@/config/metricsPdfConfiguration'
import MetricsPdfSection from './MetricsPdfSection.vue'
import MetricsPdfPreview from './MetricsPdfPreview.vue'

const props = defineProps({
    filters: { type: Object, required: true },
    resolvedFilters: { type: Object, required: true },
    filterCatalog: { type: Object, required: true },
})
const draft = defineModel('draft', { type: [Object, null], required: true })
const emit = defineEmits(['close'])

const catalog = ref(null)
const brandName = ref('')
const loading = ref(true)
const loadError = ref('')
const advanced = ref(false)
const filtersExpanded = ref(false)
const expanded = reactive({})
const action = ref(null)
const busy = computed(() => action.value !== null)
const preview = shallowRef(null)
const serverError = ref(null)
const downloadError = ref('')
const outcome = ref(null)
const previewAction = ref(null)
const backAction = ref(null)
const requests = new AbortController()
const { notify } = useNotifications()
let lastPreviewDownloadAt = -Infinity

async function loadCatalog() {
    loading.value = true
    loadError.value = ''
    try {
        const { data } = await api.get(metricsPdfConfigurationEndpoint, undefined, requests.signal)
        if (requests.signal.aborted) return
        draft.value ??= createMetricsPdfDraft(data)
        catalog.value = data
    } catch {
        if (!requests.signal.aborted)
            loadError.value = 'Não foi possível carregar as opções do relatório.'
    } finally {
        if (!requests.signal.aborted) loading.value = false
    }
}
async function loadBrand(id) {
    try {
        const { data } = await api.get('vehicle-brands/' + id, undefined, requests.signal)
        if (!requests.signal.aborted) brandName.value = data.name
    } catch {
        // The read-only criteria still identify the brand filter when its label is unavailable.
    }
}
loadCatalog()
if (props.filters.vehicle_brand_id) loadBrand(props.filters.vehicle_brand_id)
onBeforeUnmount(() => requests.abort())

const criteria = computed(() =>
    describeMetricFilters(props.filters, props.resolvedFilters, props.filterCatalog, {
        brandName: brandName.value,
    }),
)
const configuration = computed(() =>
    catalog.value && draft.value
        ? effectiveMetricsPdfConfiguration(catalog.value, draft.value)
        : null,
)
const summary = computed(() =>
    configuration.value ? summarizeMetricsPdf(catalog.value, configuration.value) : null,
)
const isAutomatic = computed(() => draft.value?.title.mode === catalog.value?.defaults.title.mode)
const titleValue = computed(() =>
    isAutomatic.value
        ? automaticMetricsPdfTitle(
              catalog.value,
              configuration.value,
              props.filters,
              props.filterCatalog,
              brandName.value,
          )
        : draft.value.title.text,
)
const localErrors = computed(() => {
    if (!configuration.value) return {}
    const { title, subtitle } = configuration.value
    const titleLimit = catalog.value.title.max_length
    const subtitleLimit = catalog.value.subtitle.max_length
    let titleError
    if (!isAutomatic.value && !title.text) titleError = 'Informe o título personalizado.'
    else if (Array.from(title.text ?? '').length > titleLimit)
        titleError = `Informe até ${titleLimit} caracteres.`
    const subtitleError =
        Array.from(subtitle ?? '').length > subtitleLimit
            ? `Informe até ${subtitleLimit} caracteres.`
            : undefined
    return { title: titleError, subtitle: subtitleError }
})
const textErrors = computed(() => {
    const errors = serverError.value?.errors ?? {}
    return {
        title: localErrors.value.title
            ? [localErrors.value.title]
            : errors['configuration.title.text'],
        subtitle: localErrors.value.subtitle
            ? [localErrors.value.subtitle]
            : errors['configuration.subtitle'],
    }
})
const generalErrors = computed(() =>
    Object.entries(serverError.value?.errors ?? {})
        .filter(
            ([field]) => field !== 'configuration.title.text' && field !== 'configuration.subtitle',
        )
        .flatMap(([, messages]) => messages),
)
const canGenerate = computed(
    () =>
        !!summary.value?.sections &&
        !Object.values(localErrors.value).some(Boolean) &&
        serverError.value?.status !== 422 &&
        !busy.value,
)

watch(configuration, () => {
    if (preview.value) returnToConfiguration()
    serverError.value = null
    downloadError.value = ''
})

function setAll(enabled) {
    for (const key of Object.keys(catalog.value.sections))
        setPdfSection(catalog.value, draft.value, key, enabled, true)
}
function restoreDefaults() {
    draft.value = createMetricsPdfDraft(catalog.value)
    advanced.value = false
}
function restoreTitle() {
    draft.value.title = { ...catalog.value.defaults.title }
}
function editTitle(text) {
    draft.value.title = { mode: 'custom', text }
}
function close() {
    requests.abort()
    emit('close')
}

async function returnToConfiguration() {
    preview.value = null
    lastPreviewDownloadAt = -Infinity
    downloadError.value = ''
    await nextTick()
    previewAction.value?.focus()
}

function download(file) {
    downloadError.value = ''
    try {
        saveDownload(file)
    } catch {
        downloadError.value = 'Não foi possível iniciar o download do PDF. Tente novamente.'
        return false
    }
    notify('PDF gerado. O download foi iniciado.')
    return true
}

function downloadPreview() {
    const now = performance.now()
    if (!preview.value || now - lastPreviewDownloadAt < 1000) return
    if (download(preview.value)) lastPreviewDownloadAt = now
}

async function generate(mode) {
    if (!canGenerate.value) return
    const selection = configuration.value
    action.value = mode
    serverError.value = null
    downloadError.value = ''
    const filters = { ...props.filters }
    const { date_from, date_to } = props.resolvedFilters
    // Reuse the dates resolved by Métricas even if the relative period changes overnight.
    if (filters.period !== 'all' && filters.period !== 'custom' && date_from && date_to)
        Object.assign(filters, { period: 'custom', date_from, date_to })
    try {
        const file = await api.exportMetricsPdf(
            { ...filters, configuration: selection },
            mode === 'preview' ? 'inline' : 'attachment',
            requests.signal,
        )
        if (requests.signal.aborted || selection !== configuration.value) return
        if (mode === 'preview') preview.value = file
        else if (download(file)) close()
    } catch (exception) {
        if (!requests.signal.aborted && selection === configuration.value) {
            const message =
                exception.status === 502
                    ? 'O servidor enviou um PDF inválido ou vazio. Tente novamente.'
                    : [0, 408, 422].includes(exception.status)
                      ? exception.message
                      : 'Não foi possível gerar o relatório. Tente novamente.'
            serverError.value = { status: exception.status, errors: exception.errors, message }
        }
    } finally {
        if (!requests.signal.aborted) {
            action.value = null
            await nextTick()
            const field = textErrors.value.title
                ? 'title'
                : textErrors.value.subtitle
                  ? 'subtitle'
                  : null
            if (preview.value) backAction.value?.focus()
            else if (field) document.getElementById('metrics-pdf-' + field)?.focus()
            else outcome.value?.focus()
        }
    }
}
</script>

<template>
    <AppModal
        :title="preview ? 'Pré-visualização do relatório' : 'Personalizar relatório PDF'"
        wide
        @close="close"
    >
        <template v-if="preview">
            <p v-if="downloadError" class="alert alert-error" role="alert">{{ downloadError }}</p>
            <MetricsPdfPreview :file="preview" />
        </template>
        <StatePanel
            v-else-if="loading || loadError"
            compact
            :loading="loading"
            :error="loadError"
            @retry="loadCatalog"
        />
        <form
            v-else-if="draft && catalog"
            id="metrics-pdf-configuration"
            aria-label="Configuração do PDF"
            :aria-busy="busy"
            @submit.prevent="generate('download')"
        >
            <fieldset class="pdf-form" :disabled="busy">
                <section class="pdf-form-area" aria-labelledby="metrics-pdf-identification">
                    <h3 id="metrics-pdf-identification">Identificação do relatório</h3>
                    <FormField
                        prefix="metrics-pdf"
                        :field="{
                            name: 'title',
                            label: 'Título do relatório',
                            required: true,
                            max: catalog.title.max_length,
                        }"
                        :model-value="titleValue"
                        :errors="textErrors"
                        @update:model-value="editTitle"
                    >
                        <template #label-action>
                            <span class="pdf-title-mode" role="status">{{
                                catalog.title.modes[draft.title.mode]
                            }}</span>
                        </template>
                    </FormField>
                    <button
                        v-if="!isAutomatic"
                        type="button"
                        class="text-button pdf-auto-title"
                        @click="restoreTitle"
                    >
                        Restaurar título automático
                    </button>
                    <FormField
                        v-model="draft.subtitle"
                        prefix="metrics-pdf"
                        :field="{
                            name: 'subtitle',
                            label: 'Subtítulo',
                            max: catalog.subtitle.max_length,
                        }"
                        :errors="textErrors"
                    />
                </section>

                <section class="pdf-form-area">
                    <button
                        type="button"
                        class="text-button pdf-filters-toggle"
                        aria-controls="metrics-pdf-filters"
                        :aria-expanded="filtersExpanded"
                        @click="filtersExpanded = !filtersExpanded"
                    >
                        <AppIcon name="chevron" :size="14" /> Filtros aplicados
                    </button>
                    <div id="metrics-pdf-filters" :hidden="!filtersExpanded">
                        <dl v-if="filtersExpanded" class="detail-grid pdf-criteria">
                            <div v-for="criterion in criteria" :key="criterion.label">
                                <dt>{{ criterion.label }}</dt>
                                <dd>{{ criterion.value }}</dd>
                            </div>
                        </dl>
                    </div>
                    <p v-if="!filtersExpanded" class="pdf-muted">{{ criteria[0].value }}</p>
                </section>

                <section class="pdf-form-area" aria-labelledby="metrics-pdf-content">
                    <h3 id="metrics-pdf-content">Seções e indicadores</h3>
                    <div class="pdf-selection-actions">
                        <button type="button" class="text-button" @click="setAll(true)">
                            Selecionar tudo
                        </button>
                        <button type="button" class="text-button" @click="setAll(false)">
                            Limpar seleção
                        </button>
                        <button type="button" class="text-button" @click="restoreDefaults">
                            Restaurar configuração padrão
                        </button>
                    </div>
                    <div class="pdf-advanced">
                        <label>
                            <input v-model="advanced" type="checkbox" />
                            Exibir configurações visuais avançadas
                        </label>
                        <div v-if="advanced" class="pdf-advanced-help">
                            <p class="pdf-muted">Expanda uma seção para ajustar seus formatos.</p>
                            <button
                                type="button"
                                class="text-button"
                                @click="resetPdfFormats(draft, catalog.defaults.format)"
                            >
                                Restaurar formatos automáticos
                            </button>
                        </div>
                    </div>
                    <div class="pdf-sections">
                        <MetricsPdfSection
                            v-for="(definition, key) in catalog.sections"
                            :key="key"
                            v-model="draft.sections[key]"
                            :section-id="key"
                            :definition="definition"
                            :formats="catalog.formats"
                            :default-format="catalog.defaults.format"
                            :vehicle-grouping="
                                key === catalog.vehicle_grouping.section
                                    ? catalog.vehicle_grouping
                                    : undefined
                            "
                            :advanced="advanced"
                            :expanded="!!expanded[key]"
                            @toggle="
                                (enabled, partial) =>
                                    setPdfSection(catalog, draft, key, enabled, partial)
                            "
                            @expand="expanded[key] = !expanded[key]"
                        />
                    </div>
                </section>

                <section class="pdf-form-area pdf-summary" aria-labelledby="metrics-pdf-summary">
                    <h3 id="metrics-pdf-summary">Resumo da configuração</h3>
                    <div aria-live="polite">
                        <p>
                            {{ summary.sections }}
                            {{ summary.sections === 1 ? 'seção' : 'seções' }} ·
                            {{ summary.indicators }}
                            {{ summary.indicators === 1 ? 'indicador' : 'indicadores' }} ·
                            {{
                                summary.customized
                                    ? 'Apresentação personalizada'
                                    : 'Apresentação automática'
                            }}
                        </p>
                        <p v-if="summary.additional.length">
                            Agrupamentos adicionais · {{ summary.additional.join(', ') }}
                        </p>
                        <p v-if="!summary.sections" class="pdf-muted">
                            Selecione pelo menos um indicador ou agrupamento para gerar o relatório.
                        </p>
                    </div>
                </section>
            </fieldset>
            <div
                v-if="serverError || downloadError"
                ref="outcome"
                class="alert alert-error pdf-outcome"
                role="alert"
                tabindex="-1"
            >
                <p>{{ downloadError || serverError.message }}</p>
                <ul v-if="generalErrors.length">
                    <li v-for="(message, index) in generalErrors" :key="index">{{ message }}</li>
                </ul>
            </div>
        </form>
        <template #footer>
            <template v-if="preview">
                <button
                    ref="backAction"
                    type="button"
                    class="btn btn-secondary"
                    @click="returnToConfiguration"
                >
                    <AppIcon name="back" :size="16" /> Voltar à configuração
                </button>
                <button type="button" class="btn btn-primary" @click="downloadPreview">
                    <AppIcon name="download" :size="16" /> Baixar PDF
                </button>
            </template>
            <template v-else>
                <span v-if="busy" class="sr-only" role="status">
                    {{
                        action === 'preview'
                            ? 'Gerando pré-visualização…'
                            : 'Preparando relatório PDF…'
                    }}
                </span>
                <button type="button" class="btn btn-secondary" @click="close">Cancelar</button>
                <button
                    ref="previewAction"
                    type="button"
                    class="btn btn-secondary"
                    :disabled="!canGenerate"
                    @click="generate('preview')"
                >
                    <span v-if="action === 'preview'" class="spinner spinner-small"></span>
                    {{ action === 'preview' ? 'Gerando pré-visualização…' : 'Pré-visualizar' }}
                </button>
                <button
                    type="submit"
                    class="btn btn-primary"
                    form="metrics-pdf-configuration"
                    :disabled="!canGenerate"
                >
                    <span v-if="action === 'download'" class="spinner spinner-small"></span>
                    <AppIcon v-else name="download" :size="16" />
                    {{ action === 'download' ? 'Preparando relatório PDF…' : 'Exportar PDF' }}
                </button>
            </template>
        </template>
    </AppModal>
</template>

<style scoped>
:global(.modal-backdrop:has(#metrics-pdf-configuration)) {
    overflow: clip;
}
.pdf-form {
    display: grid;
    min-width: 0;
    gap: 18px;
    margin: 0;
    padding: 0;
    border: 0;
}
.pdf-form-area {
    display: grid;
    min-width: 0;
    gap: 8px;
}
.pdf-form-area h3 {
    font-size: 13px;
    font-weight: 650;
}
.pdf-title-mode,
.pdf-muted {
    color: var(--muted);
    font-size: 12px;
}
.pdf-auto-title,
.pdf-filters-toggle {
    justify-self: start;
}
.pdf-filters-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.pdf-filters-toggle[aria-expanded='true'] svg {
    transform: rotate(90deg);
}
.pdf-criteria dd {
    overflow-wrap: anywhere;
}
.pdf-selection-actions,
.pdf-advanced-help {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px 16px;
}
.pdf-advanced {
    display: grid;
    gap: 4px;
    padding-block: 4px;
}
.pdf-advanced label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    justify-self: start;
    min-height: 28px;
}
.pdf-advanced input {
    width: 16px;
    height: 16px;
    flex: none;
}
.pdf-sections {
    display: grid;
    gap: 6px;
}
.pdf-summary {
    border-top: 1px solid var(--line);
    padding-top: 12px;
    font-size: 12px;
    line-height: 1.55;
}
.pdf-outcome {
    margin: 12px 0 0;
}
.pdf-outcome ul {
    padding-inline-start: 20px;
}
</style>
