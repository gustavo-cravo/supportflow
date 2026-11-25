<script setup>
import { computed, nextTick, ref, shallowRef, useId, watch } from 'vue'
import AppIcon from '@/components/AppIcon.vue'
import AppModal from '@/components/AppModal.vue'
import StatePanel from '@/components/StatePanel.vue'
import { useAnalyticalReport } from '@/composables/useAnalyticalReport'
import { useNotifications } from '@/composables/useNotifications'
import { describeMetricFilters, metricNumber } from '@/config/ticketMetrics'
import { saveDownload } from '@/utils/download'
import AnalyticalReportTickets from './AnalyticalReportTickets.vue'
import AnalyticalReportContent from './AnalyticalReportContent.vue'
import AnalyticalReportPresentation from './AnalyticalReportPresentation.vue'
import MetricsPdfPreview from './MetricsPdfPreview.vue'

const props = defineProps({
    filters: { type: Object, required: true },
    resolvedFilters: { type: Object, required: true },
    filterCatalog: { type: Object, required: true },
    scopeChanged: Boolean,
})
const draft = defineModel('draft', { type: [Object, null], required: true })
const emit = defineEmits(['close'])

const prefix = 'analytical-report-' + useId()
const form = prefix + '-form'
const tabs = [
    { id: 'tickets', label: 'Atendimentos' },
    { id: 'content', label: 'Conteúdo' },
    { id: 'presentation', label: 'Apresentação' },
]
const active = ref('tickets')
const expanded = ref([])
const scroll = ref(null)
const feedback = ref(null)
const previewAction = ref(null)
const backAction = ref(null)
const preview = shallowRef(null)
const downloadError = ref('')
const { notify } = useNotifications()
let lastPreviewDownloadAt = -Infinity
const {
    catalog,
    catalogLoading,
    catalogError,
    tickets,
    meta,
    pageLoading,
    pageError,
    eligibleChanged,
    eligible,
    selected,
    configuration,
    fields,
    fieldLabels,
    missingGroupFields,
    textErrors,
    canGenerate,
    automaticTitle,
    titleLoading,
    action,
    busy,
    validationError,
    isSelected,
    changeTicket,
    restore,
    generate,
    abort,
    refreshSelection,
    loadCatalog,
    loadPage,
    loadAutomaticTitle,
    unmet,
} = useAnalyticalReport(draft, props.filters, props.resolvedFilters)
const criteria = computed(() =>
    describeMetricFilters(props.filters, props.resolvedFilters, props.filterCatalog),
)
const configurationExists = computed(() => !!catalog.value && !!draft.value)
const otherErrors = computed(() =>
    Object.entries(validationError.value?.errors ?? {})
        .filter(([key]) => !['configuration.title.text', 'configuration.subtitle'].includes(key))
        .flatMap(([, messages]) => messages),
)
const selectionError = computed(() =>
    Object.keys(validationError.value?.errors ?? {}).some((key) =>
        key.startsWith('configuration.selection'),
    ),
)

watch(catalog, (value) => {
    if (value) expanded.value = Object.keys(value.sections).slice(0, 1)
})
watch(
    configuration,
    () => {
        if (preview.value) void returnToConfiguration()
        downloadError.value = ''
    },
    { flush: 'sync' },
)

function selectTab(id) {
    active.value = id
    if (scroll.value) scroll.value.scrollTop = 0
}

async function moveTab(event) {
    const index = tabs.findIndex((tab) => tab.id === active.value)
    const positions = {
        ArrowLeft: (index + tabs.length - 1) % tabs.length,
        ArrowRight: (index + 1) % tabs.length,
        Home: 0,
        End: tabs.length - 1,
    }
    if (!Object.hasOwn(positions, event.key)) return
    event.preventDefault()
    selectTab(tabs[positions[event.key]].id)
    await nextTick()
    document.getElementById(prefix + '-tab-' + active.value)?.focus()
}

function setTicketMode(mode) {
    draft.value.selection = { mode, ids: [] }
}

function updateTickets() {
    selectTab('tickets')
    void refreshSelection()
}

async function changePage(page) {
    if (pageLoading.value || busy.value) return
    await loadPage(page)
    if (active.value === 'tickets') {
        if (scroll.value) scroll.value.scrollTop = 0
        document.getElementById(prefix + '-panel-tickets')?.focus()
    }
}

function close() {
    abort()
    preview.value = null
    emit('close')
}

async function returnToConfiguration() {
    preview.value = null
    downloadError.value = ''
    lastPreviewDownloadAt = -Infinity
    await nextTick()
    previewAction.value?.focus()
}

function countDifference(file) {
    if (file.selectedCount === file.expectedCount) return ''
    const label = file.selectedCount === 1 ? 'atendimento' : 'atendimentos'
    return `O PDF inclui ${metricNumber(file.selectedCount)} ${label} no recorte atual. A configuração indicava ${metricNumber(file.expectedCount)}.`
}

function download(file, difference = '') {
    downloadError.value = ''
    try {
        saveDownload(file)
    } catch {
        downloadError.value = 'Não foi possível iniciar o download do PDF. Tente novamente.'
        return false
    }
    notify(['O download do PDF foi iniciado.', difference].filter(Boolean).join(' '))
    return true
}

function downloadPreview() {
    const now = performance.now()
    if (!preview.value || now - lastPreviewDownloadAt < 1000) return
    if (download(preview.value)) lastPreviewDownloadAt = now
}

async function submit(mode) {
    if (preview.value || !canGenerate.value) return
    downloadError.value = ''
    const file = await generate(mode)
    if (file) {
        if (mode === 'preview') {
            preview.value = file
            await nextTick()
            backAction.value?.focus()
            return
        }
        if (download(file, countDifference(file))) {
            close()
            return
        }
    }
    if (validationError.value && (textErrors.value.title || textErrors.value.subtitle)) {
        selectTab('presentation')
        await nextTick()
        document.getElementById(prefix + (textErrors.value.title ? '-title' : '-subtitle'))?.focus()
        return
    }
    await nextTick()
    feedback.value?.focus()
}
</script>

<template>
    <AppModal
        :title="
            preview
                ? 'Pré-visualização do relatório analítico'
                : 'Relatório analítico de atendimentos'
        "
        wide
        @close="close"
    >
        <div v-if="preview" class="analytical-report-preview">
            <p v-if="countDifference(preview)" class="alert alert-info" role="status">
                {{ countDifference(preview) }}
            </p>
            <p v-if="downloadError" class="alert alert-error" role="alert">{{ downloadError }}</p>
            <MetricsPdfPreview :file="preview" show-loading />
        </div>
        <form
            v-else
            :id="form"
            class="analytical-report"
            novalidate
            @submit.prevent="submit('download')"
        >
            <div class="analytical-report-header">
                <template v-if="configurationExists">
                    <div class="analytical-report-summary">
                        <dl aria-label="Resumo da configuração" aria-live="polite">
                            <div>
                                <dt>Elegíveis</dt>
                                <dd>{{ meta ? metricNumber(eligible) : '—' }}</dd>
                            </div>
                            <div>
                                <dt>Selecionados</dt>
                                <dd>{{ meta ? metricNumber(selected) : '—' }}</dd>
                            </div>
                            <div>
                                <dt>Campos</dt>
                                <dd>{{ metricNumber(fields.length) }}</dd>
                            </div>
                            <div>
                                <dt>Seções</dt>
                                <dd>
                                    {{ metricNumber(Object.keys(configuration.sections).length) }}
                                </dd>
                            </div>
                        </dl>
                        <button
                            class="text-button"
                            type="button"
                            :disabled="busy"
                            @click="restore()"
                        >
                            Restaurar toda a configuração
                        </button>
                    </div>
                    <nav
                        class="analytical-report-tabs"
                        role="tablist"
                        aria-label="Configuração do relatório"
                        @keydown="moveTab"
                    >
                        <button
                            v-for="tab in tabs"
                            :id="prefix + '-tab-' + tab.id"
                            :key="tab.id"
                            type="button"
                            role="tab"
                            :aria-selected="active === tab.id"
                            :aria-controls="prefix + '-panel-' + tab.id"
                            :tabindex="active === tab.id ? 0 : -1"
                            @click="selectTab(tab.id)"
                        >
                            {{ tab.label }}
                        </button>
                    </nav>
                </template>
            </div>

            <div ref="scroll" class="analytical-report-scroll">
                <StatePanel
                    v-if="!configurationExists"
                    compact
                    :loading="catalogLoading"
                    :error="catalogError"
                    @retry="loadCatalog"
                />
                <template v-else>
                    <p v-if="scopeChanged" class="alert alert-info" role="status">
                        Os filtros do Dashboard mudaram. Este relatório conserva o recorte da
                        abertura.
                    </p>
                    <p v-if="eligibleChanged" class="alert alert-info" role="status">
                        O total de elegíveis mudou. Confira a seleção antes de gerar o PDF.
                    </p>
                    <p v-if="meta && eligible && !selected" class="alert alert-info">
                        Selecione pelo menos um atendimento.
                    </p>
                    <p v-if="!fields.length" class="alert alert-info">
                        Selecione pelo menos um campo em Conteúdo.
                    </p>
                    <p v-if="missingGroupFields.length" class="alert alert-error" role="alert">
                        {{ catalog.groupings[draft.grouping].label }} exige os campos
                        {{ missingGroupFields.map((id) => fieldLabels[id]).join(' e ') }}. Selecione
                        os campos em Conteúdo ou altere o agrupamento.
                    </p>
                    <div
                        v-if="validationError || downloadError"
                        ref="feedback"
                        class="alert alert-error"
                        role="alert"
                        tabindex="-1"
                    >
                        <p>{{ downloadError || validationError.message }}</p>
                        <ul v-if="otherErrors.length">
                            <li v-for="(message, index) in otherErrors" :key="index">
                                {{ message }}
                            </li>
                        </ul>
                        <p v-if="selectionError">
                            Atualize a listagem e revise os atendimentos selecionados. Se
                            necessário, refaça a seleção.
                        </p>
                        <button
                            v-if="selectionError"
                            type="button"
                            class="text-button"
                            @click="updateTickets"
                        >
                            Atualizar atendimentos
                        </button>
                    </div>

                    <fieldset :disabled="busy" class="analytical-report-panels">
                        <section
                            v-for="tab in tabs"
                            :id="prefix + '-panel-' + tab.id"
                            :key="tab.id"
                            :hidden="active !== tab.id"
                            :aria-labelledby="prefix + '-tab-' + tab.id"
                            role="tabpanel"
                            :tabindex="active === tab.id ? 0 : -1"
                        >
                            <template v-if="active === tab.id">
                                <AnalyticalReportTickets
                                    v-if="tab.id === 'tickets'"
                                    :criteria="criteria"
                                    :tickets="tickets"
                                    :meta="meta"
                                    :loading="pageLoading"
                                    :error="pageError"
                                    :busy="busy"
                                    :is-selected="isSelected"
                                    @page="changePage"
                                    @ticket="changeTicket"
                                    @all="setTicketMode('all')"
                                    @clear="setTicketMode('only')"
                                />
                                <AnalyticalReportContent
                                    v-else-if="tab.id === 'content'"
                                    v-model="draft"
                                    v-model:expanded="expanded"
                                    :catalog="catalog"
                                    :prefix="prefix"
                                    @restore="restore('content')"
                                />
                                <AnalyticalReportPresentation
                                    v-else
                                    v-model="draft"
                                    :catalog="catalog"
                                    :prefix="prefix"
                                    :automatic-title="automaticTitle"
                                    :title-loading="titleLoading"
                                    :title-retry="eligible > 0"
                                    :errors="textErrors"
                                    :unmet="unmet"
                                    :field-labels="fieldLabels"
                                    @restore="restore('presentation')"
                                    @title="loadAutomaticTitle"
                                />
                            </template>
                        </section>
                    </fieldset>
                </template>
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
                    Voltar às configurações
                </button>
                <button type="button" class="btn btn-primary" @click="downloadPreview">
                    <AppIcon name="download" :size="16" /> Baixar PDF
                </button>
            </template>
            <template v-else>
                <span v-if="busy" class="sr-only" role="status">
                    {{
                        action === 'preview' ? 'Gerando pré-visualização…' : 'Preparando download…'
                    }}
                </span>
                <button type="button" class="btn btn-secondary" @click="close">Cancelar</button>
                <button
                    ref="previewAction"
                    type="button"
                    class="btn btn-secondary"
                    :disabled="!canGenerate"
                    @click="submit('preview')"
                >
                    <span
                        v-if="action === 'preview'"
                        class="spinner spinner-small"
                        aria-hidden="true"
                    ></span>
                    {{ action === 'preview' ? 'Gerando pré-visualização…' : 'Pré-visualizar' }}
                </button>
                <button type="submit" class="btn btn-primary" :form="form" :disabled="!canGenerate">
                    <span
                        v-if="action === 'download'"
                        class="spinner spinner-small"
                        aria-hidden="true"
                    ></span>
                    <AppIcon v-else name="download" :size="16" />
                    {{ action === 'download' ? 'Preparando download…' : 'Exportar PDF' }}
                </button>
            </template>
        </template>
    </AppModal>
</template>

<style scoped>
:global(.modal:has(.analytical-report)) {
    max-width: 810px;
}
:global(.modal:has(.analytical-report) > .modal-body) {
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    padding: 0;
}
.analytical-report-preview {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
}
.analytical-report {
    display: flex;
    flex-direction: column;
    min-height: 0;
    --analytical-padding: 24px;
}
.analytical-report-header {
    flex-shrink: 0;
    padding: 10px var(--analytical-padding) 0;
    font-size: 12px;
}
.analytical-report-summary {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 6px 12px;
    margin: 8px 0 6px;
}
.analytical-report-summary dl {
    display: flex;
    flex-wrap: wrap;
    gap: 2px 14px;
}
.analytical-report-summary dl div {
    display: flex;
    gap: 4px;
}
.analytical-report-summary dt {
    color: var(--muted);
}
.analytical-report-summary dd {
    font-weight: 700;
}
.analytical-report-tabs {
    display: flex;
    align-items: stretch;
    gap: 3px;
    border-bottom: 1px solid var(--line);
}
.analytical-report-tabs button {
    font: inherit;
    font-weight: 650;
    border: 0;
    border-bottom: 2px solid transparent;
    padding: 8px 11px;
    background: transparent;
    color: inherit;
    cursor: pointer;
}
.analytical-report-tabs button[aria-selected='true'] {
    color: var(--blue);
    border-color: currentColor;
}
.analytical-report-scroll {
    min-height: 0;
    overflow-y: auto;
    padding: 14px var(--analytical-padding) 18px;
}
.analytical-report-scroll .alert {
    margin-bottom: 10px;
    overflow-wrap: anywhere;
}
.analytical-report-scroll .alert ul {
    margin-left: 17px;
    list-style: disc;
}
.analytical-report-panels {
    min-width: 0;
    border: 0;
}
.analytical-report-panels section {
    outline-offset: -2px;
}
@media (max-width: 520px) {
    .analytical-report {
        --analytical-padding: 14px;
    }
    .analytical-report-tabs button {
        padding-inline: 7px;
    }
}
</style>
