import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { api } from '@/services/api'

const endpoint = 'metrics/analytical-report/configuration'
const copy = (value) => JSON.parse(JSON.stringify(value))

export function useAnalyticalReport(draft, applied, resolved) {
    const filters = { ...applied }
    if (filters.period !== 'all' && resolved.date_from && resolved.date_to) {
        // Keep the Dashboard's resolved period even if the day changes during configuration.
        Object.assign(filters, {
            period: 'custom',
            date_from: resolved.date_from,
            date_to: resolved.date_to,
        })
    }

    const requests = new AbortController()
    const { signal } = requests
    const abort = () => requests.abort()
    onBeforeUnmount(abort)

    const catalog = shallowRef(null)
    const catalogLoading = ref(true)
    const catalogError = ref('')
    const tickets = shallowRef([])
    const meta = shallowRef(null)
    const pageLoading = ref(true)
    const pageError = ref('')
    const eligibleChanged = ref(false)
    const titleLoading = ref(false)
    const automaticTitle = ref('')
    const action = ref(null)
    const busy = computed(() => action.value !== null)
    const validationError = shallowRef(null)
    let requestedPage = 1
    let titleRequest
    let resolvedTitleContext

    const eligible = computed(() => meta.value?.total ?? 0)
    const ids = computed(() => new Set(draft.value?.selection.ids))
    const selected = computed(() => {
        const mode = draft.value?.selection.mode
        if (mode === 'all_except') return Math.max(0, eligible.value - ids.value.size)
        if (mode === 'only') return ids.value.size
        return eligible.value
    })
    const configuration = computed(() => {
        if (!draft.value || !catalog.value) return null
        const { selection, sections, grouping, order, empty_fields, title, subtitle, layout } =
            draft.value
        return {
            selection: { mode: selection.mode, ids: [...ids.value] },
            sections: Object.fromEntries(
                Object.entries(catalog.value.sections).flatMap(([id, section]) => {
                    const fields = Object.keys(section.fields).filter((field) =>
                        sections[id]?.fields.includes(field),
                    )
                    return fields.length ? [[id, { enabled: true, fields }]] : []
                }),
            ),
            grouping,
            order,
            empty_fields,
            title: { mode: title.mode, text: title.mode === 'custom' ? title.text.trim() : null },
            subtitle: subtitle?.trim() || null,
            layout: { ...layout },
        }
    })
    const fields = computed(() =>
        Object.values(configuration.value?.sections ?? {}).flatMap((section) => section.fields),
    )
    const scopedTitleFields = [
        ['system_id', 'system'],
        ['problem_category_id', 'problem_category'],
        ['problem_subcategory_id', 'problem_subcategories'],
        ['vehicle_brand_id', 'vehicle_brand'],
    ].filter(([filter]) => filters[filter])
    const titleContext = computed(() =>
        scopedTitleFields
            .map(([, field]) => field)
            .filter((field) => !fields.value.includes(field))
            .join(','),
    )
    const fieldLabels = computed(() =>
        Object.fromEntries(
            Object.values(catalog.value?.sections ?? {}).flatMap(({ fields }) =>
                Object.entries(fields).map(([id, field]) => [id, field.label]),
            ),
        ),
    )
    const unmet = (group) =>
        (catalog.value?.groupings[group]?.required_fields ?? []).filter(
            (field) => !fields.value.includes(field),
        )
    const missingGroupFields = computed(() => unmet(configuration.value?.grouping))
    const lengthError = (value, max) =>
        Array.from(value ?? '').length > max ? [`Informe até ${max} caracteres.`] : undefined
    const localErrors = computed(() => {
        if (!configuration.value) return {}
        const { title, subtitle } = configuration.value
        return {
            title:
                title.mode === 'custom' && !title.text
                    ? ['Informe o título personalizado.']
                    : lengthError(title.text, catalog.value.title.max_length),
            subtitle: lengthError(subtitle, catalog.value.subtitle.max_length),
        }
    })
    const textErrors = computed(() => ({
        title: localErrors.value.title ?? validationError.value?.errors['configuration.title.text'],
        subtitle:
            localErrors.value.subtitle ?? validationError.value?.errors['configuration.subtitle'],
    }))
    const canGenerate = computed(
        () =>
            !!configuration.value &&
            !!meta.value &&
            !catalogLoading.value &&
            !pageLoading.value &&
            !pageError.value &&
            !busy.value &&
            validationError.value?.status !== 422 &&
            eligible.value > 0 &&
            selected.value > 0 &&
            fields.value.length > 0 &&
            !missingGroupFields.value.length &&
            !Object.values(localErrors.value).some(Boolean),
    )

    watch(
        configuration,
        () => {
            validationError.value = null
        },
        { flush: 'sync' },
    )

    async function loadCatalog() {
        catalogLoading.value = true
        catalogError.value = ''
        try {
            const { data } = await api.get(endpoint, undefined, signal)
            if (signal.aborted) return
            draft.value ??= copy(data.defaults)
            catalog.value = data
        } catch {
            if (!signal.aborted)
                catalogError.value = 'Não foi possível carregar as opções do relatório.'
        } finally {
            if (!signal.aborted) catalogLoading.value = false
        }
    }

    async function loadAutomaticTitle() {
        if (!catalog.value || !eligible.value || signal.aborted) return
        const context = titleContext.value
        if (
            titleRequest?.context === context ||
            (resolvedTitleContext === context && automaticTitle.value)
        )
            return
        titleRequest?.controller.abort()
        const request = { context, controller: new AbortController() }
        titleRequest = request
        automaticTitle.value = ''
        titleLoading.value = true
        const defaults = catalog.value.defaults
        const omitted = new Set(context.split(','))
        const sections = Object.fromEntries(
            Object.entries(defaults.sections).map(([id, section]) => [
                id,
                { ...section, fields: section.fields.filter((field) => !omitted.has(field)) },
            ]),
        )
        try {
            const { data } = await api.post(
                endpoint,
                { ...filters, configuration: { ...defaults, sections } },
                AbortSignal.any([signal, request.controller.signal]),
            )
            if (!signal.aborted && titleRequest === request) {
                resolvedTitleContext = context
                automaticTitle.value = data.title
            }
        } catch {
            // Generation recalculates the title even when the initial suggestion is unavailable.
        } finally {
            if (!signal.aborted && titleRequest === request) {
                titleRequest = null
                titleLoading.value = false
            }
        }
    }

    async function loadPage(page = requestedPage) {
        requestedPage = page
        pageLoading.value = true
        pageError.value = ''
        try {
            const result = await api.get(
                'metrics/analytical-report/tickets',
                { ...filters, page, per_page: 20 },
                signal,
            )
            if (signal.aborted) return
            if (page > result.meta.last_page && result.meta.total) {
                await loadPage(result.meta.last_page)
                return
            }
            if (meta.value && result.meta.total !== eligible.value) {
                eligibleChanged.value = true
                validationError.value = null
            }
            tickets.value = result.data
            meta.value = result.meta
        } catch {
            if (!signal.aborted) pageError.value = 'Não foi possível consultar os atendimentos.'
        } finally {
            if (!signal.aborted) pageLoading.value = false
        }
    }

    function isSelected(id) {
        return draft.value.selection.mode === 'only' ? ids.value.has(id) : !ids.value.has(id)
    }

    function changeTicket(id, checked) {
        if (busy.value || pageLoading.value || !tickets.value.some((row) => row.id === id)) return
        const only = draft.value.selection.mode === 'only'
        const changed = new Set(ids.value)
        if (checked === only) changed.add(id)
        else changed.delete(id)
        draft.value.selection = {
            mode: only ? 'only' : changed.size ? 'all_except' : 'all',
            ids: [...changed],
        }
    }

    function restore(part) {
        const defaults = copy(catalog.value.defaults)
        if (part === 'content') {
            draft.value.sections = defaults.sections
            draft.value.empty_fields = defaults.empty_fields
            return
        }
        if (part === 'presentation') {
            const { grouping, order, title, subtitle, layout } = defaults
            Object.assign(draft.value, { grouping, order, title, subtitle, layout })
            return
        }
        draft.value = defaults
    }

    async function refreshSelection() {
        if (busy.value || pageLoading.value) return
        validationError.value = null
        await loadPage()
    }

    async function generate(mode) {
        if (signal.aborted || !canGenerate.value) return
        const requested = configuration.value
        const expectedCount = selected.value
        action.value = mode
        validationError.value = null
        try {
            const file = await api.exportAnalyticalPdf(
                { ...filters, configuration: requested },
                mode === 'preview' ? 'inline' : 'attachment',
                signal,
            )
            if (signal.aborted || requested !== configuration.value) return
            return { ...file, expectedCount }
        } catch (error) {
            if (!signal.aborted && requested === configuration.value) {
                validationError.value = {
                    status: error.status,
                    message:
                        error.status === 502
                            ? 'O servidor enviou um PDF inválido ou vazio. Tente novamente.'
                            : [0, 408, 422].includes(error.status)
                              ? error.message
                              : 'Não foi possível gerar o relatório. Tente novamente.',
                    errors: error.status === 422 ? (error.errors ?? {}) : {},
                }
            }
        } finally {
            if (!signal.aborted) action.value = null
        }
    }

    void loadCatalog()
    void loadPage()
    watch([catalog, eligible, titleContext], loadAutomaticTitle)

    return {
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
    }
}
