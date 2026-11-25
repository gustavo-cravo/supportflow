import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import { api, ApiError } from '../services/api'
import {
    automaticMetricsPdfTitle,
    metricsPdfConfigurationEndpoint,
} from '../config/metricsPdfConfiguration'
import MetricsDashboard from '../components/metrics/MetricsDashboard.vue'
import MetricsPdfConfigurationModal from '../components/metrics/MetricsPdfConfigurationModal.vue'
import { useNotifications } from '../composables/useNotifications'
import * as downloads from '../utils/download'

// A small catalog fixture lets the tests verify that the UI follows the server's options.
const numeric = ['automatic', 'indicator', 'table']
const bars = ['automatic', 'horizontal_bar', 'vertical_bar', 'table']
const section = (label, entries, activation = Object.keys(entries)) => ({
    label,
    indicators: Object.fromEntries(
        Object.entries(entries).map(([key, [label, formats]]) => [key, { label, formats }]),
    ),
    activation_indicators: activation,
})
const catalog = {
    sections: {
        summary: section(
            'Resumo geral dos atendimentos',
            {
                total: ['Total de atendimentos', numeric],
                closed_percentage: ['Percentual de encerramento', numeric],
            },
            ['total'],
        ),
        systems: section('Distribuição por sistema', {
            tickets_by_system: ['Atendimentos por sistema', bars],
        }),
        categories: section('Categorias e problemas identificados', {
            subcategory_percentage: ['Participação do problema na categoria', bars],
        }),
        vehicles: section('Estatísticas veiculares', {
            lines: ['Distribuição por linha veicular', [...bars, 'doughnut']],
        }),
        vehicle_problems: section('Detalhamento dos problemas por veículo', {
            systems: ['Sistemas envolvidos', []],
            categories: ['Categorias e subcategorias', []],
            counts: ['Quantidade de atendimentos e ocorrências', []],
        }),
    },
    formats: {
        automatic: 'Automático',
        indicator: 'Indicador',
        horizontal_bar: 'Barras horizontais',
        vertical_bar: 'Barras verticais',
        table: 'Tabela',
        doughnut: 'Rosca',
        descriptive_group: 'Agrupamento descritivo',
    },
    vehicle_grouping: {
        section: 'vehicle_problems',
        dimensions: {
            brand: { label: 'Marca', semantic_fields: ['brand_name'] },
            model: {
                label: 'Marca e modelo',
                semantic_fields: ['brand_name', 'model_description'],
            },
            line: { label: 'Linha veicular', semantic_fields: ['vehicle_line'] },
        },
        formats: ['automatic', 'table', 'descriptive_group'],
        activation_primary: 'brand',
    },
    title: { modes: { automatic: 'Automático', custom: 'Personalizado' }, max_length: 120 },
    subtitle: { max_length: 180 },
    defaults: {
        enabled: false,
        format: 'automatic',
        title: { mode: 'automatic', text: null },
        subtitle: null,
        grouping: { primary: null, additional: [] },
    },
}
const scope = { period: 'last_6_months', classification: 'all', status: 'all' }
const resolvedFilters = { ...scope, date_from: '2026-06-01', date_to: '2026-11-25' }
const names = {
    systems: [
        { id: 1, name: 'Indicar' },
        { id: 2, name: 'HFS4456' },
    ],
    categories: [{ id: 3, name: 'Erro de indicação' }],
    subcategories: [{ id: 4, name: 'Falha de indicação veicular' }],
}
const endpoint = metricsPdfConfigurationEndpoint
const frozenDates = {
    period: 'custom',
    date_from: resolvedFilters.date_from,
    date_to: resolvedFilters.date_to,
}
let wrapper, get, generatePdf, save, file, createUrl, releaseUrl
const pdfViewerProperty = Object.getOwnPropertyDescriptor(navigator, 'pdfViewerEnabled')
beforeEach(() => {
    get = vi.spyOn(api, 'get').mockImplementation(async (path) => {
        if (path === endpoint) return { data: catalog }
        if (path === 'vehicle-brands/5') return { data: { name: 'Ford' } }
        throw new Error('Unexpected endpoint: ' + path)
    })
    file = {
        blob: new Blob(['document'], { type: 'application/pdf' }),
        filename: 'supportflow_relatorio_2026-11-25.pdf',
    }
    generatePdf = vi.spyOn(api, 'exportMetricsPdf').mockResolvedValue(file)
    save = vi.spyOn(downloads, 'saveDownload').mockImplementation(() => {})
    createUrl = vi.fn().mockReturnValue('blob:preview')
    releaseUrl = vi.fn()
    vi.stubGlobal(
        'URL',
        class extends URL {
            static createObjectURL = createUrl
            static revokeObjectURL = releaseUrl
        },
    )
    Object.defineProperty(navigator, 'pdfViewerEnabled', { value: true, configurable: true })
})
afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
    if (pdfViewerProperty) Object.defineProperty(navigator, 'pdfViewerEnabled', pdfViewerProperty)
    else Reflect.deleteProperty(navigator, 'pdfViewerEnabled')
    const { notices, dismiss } = useNotifications()
    notices.value.forEach((notice) => dismiss(notice.id))
})

async function mountModal(filters = scope, retainedDraft = null) {
    wrapper = mount(
        defineComponent({
            setup() {
                return { draft: ref(retainedDraft), filters, resolvedFilters, names }
            },
            components: { MetricsPdfConfigurationModal },
            template:
                '<MetricsPdfConfigurationModal v-model:draft="draft" :filters="filters" :resolved-filters="resolvedFilters" :filter-catalog="names" />',
        }),
        { attachTo: document.body, global: { stubs: { Teleport: true } } },
    )
    await flushPromises()
}
const button = (name) => wrapper.findAll('button').find((node) => node.text() === name)
const sectionCheckbox = (key) => wrapper.get(`#metrics-pdf-section-${key}-enabled`)
const indicator = (key, id) => wrapper.get(`#metrics-pdf-section-${key}-${id}`)
const advanced = () => wrapper.get('.pdf-advanced input')
const submit = () => wrapper.get('#metrics-pdf-configuration').trigger('submit')
async function openPreview() {
    await button('Pré-visualizar').trigger('click')
    await flushPromises()
}
const title = () => wrapper.get('#metrics-pdf-title')
const subtitle = () => wrapper.get('#metrics-pdf-subtitle')
const primary = () => wrapper.get('#metrics-pdf-group-primary')
const format = (label) => wrapper.get(`select[aria-label="${label}"]`)
const selectedSummary = () => wrapper.get('.pdf-summary').text()
async function expand(key) {
    await wrapper.get(`button[aria-controls="metrics-pdf-section-${key}"]`).trigger('click')
}

describe('PDF configuration modal', () => {
    it('starts with backend defaults and rejects an empty selection before sending a request', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
        await mountModal()
        expect(warn).not.toHaveBeenCalled()
        expect(get).toHaveBeenCalledWith(endpoint, undefined, expect.any(AbortSignal))
        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Personalizar relatório PDF')
        expect(wrapper.findAll('.pdf-section')).toHaveLength(5)
        expect(sectionCheckbox('summary').element.checked).toBe(false)
        expect(advanced().element.checked).toBe(false)
        expect(wrapper.find('#metrics-pdf-section-summary-total').exists()).toBe(false)
        expect(title().element.value).toBe('Relatório estatístico de atendimentos')
        expect(subtitle().element.value).toBe('')
        expect(selectedSummary()).toContain('0 seções · 0 indicadores · Apresentação automática')
        expect(button('Exportar PDF').element.disabled).toBe(true)
        expect(button('Pré-visualizar').element.disabled).toBe(true)
        await submit()
        expect(generatePdf).not.toHaveBeenCalled()
    })

    it('uses the catalog activation choices and maintains mixed states and independent expansions', async () => {
        await mountModal()
        await expand('summary')
        await expand('systems')
        await sectionCheckbox('summary').setValue(true)
        expect(indicator('summary', 'total').element.checked).toBe(true)
        expect(indicator('summary', 'closed_percentage').element.checked).toBe(false)
        expect(sectionCheckbox('summary').element.indeterminate).toBe(true)
        expect(sectionCheckbox('summary').attributes('aria-checked')).toBe('mixed')
        await indicator('summary', 'closed_percentage').setValue(true)
        expect(sectionCheckbox('summary').element.checked).toBe(true)
        expect(sectionCheckbox('summary').element.indeterminate).toBe(false)
        await expand('summary')
        expect(wrapper.find('#metrics-pdf-section-summary-total').exists()).toBe(false)
        expect(indicator('systems', 'tickets_by_system').exists()).toBe(true)
        await expand('summary')
        expect(indicator('summary', 'closed_percentage').element.checked).toBe(true)
        await sectionCheckbox('summary').setValue(false)
        expect(indicator('summary', 'total').element.checked).toBe(false)
        await indicator('summary', 'closed_percentage').setValue(true)
        await indicator('summary', 'closed_percentage').setValue(false)
        expect(sectionCheckbox('summary').attributes('aria-checked')).toBe('false')
        expect(button('Exportar PDF').element.disabled).toBe(true)
    })

    it('selects all sections and indicators without adding optional groups, clears only selections and resets the draft', async () => {
        await mountModal()
        await button('Selecionar tudo').trigger('click')
        expect(selectedSummary()).toContain('5 seções · 8 indicadores')
        await expand('vehicle_problems')
        expect(primary().element.value).toBe('brand')
        expect(wrapper.get('#metrics-pdf-group-add-model').element.checked).toBe(false)
        expect(wrapper.get('#metrics-pdf-group-add-line').element.checked).toBe(false)
        await title().setValue('Meu relatório')
        await subtitle().setValue('Subtítulo')
        await button('Limpar seleção').trigger('click')
        expect(selectedSummary()).toContain('0 seções · 0 indicadores')
        expect(title().element.value).toBe('Meu relatório')
        await advanced().setValue(true)
        await button('Restaurar configuração padrão').trigger('click')
        expect(advanced().element.checked).toBe(false)
        expect(primary().element.value).toBe('')
        expect(title().element.value).toBe('Relatório estatístico de atendimentos')
        expect(subtitle().element.value).toBe('')
    })

    it('shows allowed formats only, preserves selections when toggled and restores formats independently', async () => {
        await mountModal()
        await button('Selecionar tudo').trigger('click')
        await expand('summary')
        await expand('categories')
        await expand('vehicles')
        expect(wrapper.find('[aria-label="Formato de Total de atendimentos"]').exists()).toBe(false)
        await advanced().setValue(true)
        expect(
            format('Formato de Total de atendimentos')
                .findAll('option')
                .map((option) => option.element.value),
        ).toEqual(numeric)
        expect(
            format('Formato de Participação do problema na categoria')
                .findAll('option')
                .map((option) => option.element.value),
        ).toEqual(bars)
        await format('Formato de Distribuição por linha veicular').setValue('doughnut')
        expect(selectedSummary()).toContain('Apresentação personalizada')
        await advanced().setValue(false)
        expect(selectedSummary()).toContain('Apresentação personalizada')
        await advanced().setValue(true)
        expect(format('Formato de Distribuição por linha veicular').element.value).toBe('doughnut')
        await button('Restaurar formatos automáticos').trigger('click')
        expect(format('Formato de Distribuição por linha veicular').element.value).toBe('automatic')
        expect(indicator('vehicles', 'lines').element.checked).toBe(true)
        expect(selectedSummary()).toContain('5 seções · 8 indicadores · Apresentação automática')
    })

    it('accepts a grouping by itself and keeps additional groups unique and ordered with their formats', async () => {
        await mountModal()
        await expand('vehicle_problems')
        await primary().setValue('model')
        expect(title().element.value).toBe('Relatório de problemas por modelo veicular')
        expect(selectedSummary()).toContain('1 seção · 0 indicadores')
        await wrapper.get('#metrics-pdf-group-add-line').setValue(true)
        await wrapper.get('#metrics-pdf-group-add-brand').setValue(true)
        expect(selectedSummary()).toContain('Agrupamentos adicionais · Linha veicular, Marca')
        await advanced().setValue(true)
        await format('Formato do agrupamento adicional por Linha veicular').setValue('table')
        await primary().setValue('line')
        expect(wrapper.get('#metrics-pdf-group-format').element.value).toBe('table')
        expect(wrapper.find('#metrics-pdf-group-add-line').exists()).toBe(false)
        expect(wrapper.get('#metrics-pdf-group-add-model').element.checked).toBe(false)
        await indicator('vehicle_problems', 'counts').setValue(true)
        expect(primary().find('option[value=""]').element.disabled).toBe(true)
        await submit()
        await flushPromises()
        expect(generatePdf).toHaveBeenCalledWith(
            {
                ...scope,
                ...frozenDates,
                configuration: {
                    sections: {
                        vehicle_problems: {
                            enabled: true,
                            indicators: { counts: { enabled: true } },
                            grouping: {
                                primary: { by: 'line', format: 'table' },
                                additional: [{ by: 'brand', format: 'automatic' }],
                            },
                        },
                    },
                    title: { mode: 'automatic', text: null },
                    subtitle: null,
                },
            },
            'attachment',
            expect.any(AbortSignal),
        )
        expect(save).toHaveBeenCalledWith(file)
        expect(wrapper.find('iframe').exists()).toBe(false)
        expect(createUrl).not.toHaveBeenCalled()
    })

    it('adds the catalog primary dimension when a complement is selected first', async () => {
        await mountModal()
        await expand('vehicle_problems')
        await indicator('vehicle_problems', 'categories').setValue(true)
        expect(primary().element.value).toBe('brand')
        expect(sectionCheckbox('vehicle_problems').element.indeterminate).toBe(true)
        expect(selectedSummary()).toContain('1 seção · 1 indicador')
    })

    it('can remount with the dashboard draft and restores its selected values and formats', async () => {
        await mountModal()
        await expand('summary')
        await indicator('summary', 'closed_percentage').setValue(true)
        await advanced().setValue(true)
        await format('Formato de Percentual de encerramento').setValue('table')
        await subtitle().setValue('Análise do setor')
        const retained = wrapper.vm.draft
        wrapper.unmount()
        await mountModal(scope, retained)
        expect(subtitle().element.value).toBe('Análise do setor')
        expect(selectedSummary()).toContain('1 seção · 1 indicador · Apresentação personalizada')
        await expand('summary')
        await advanced().setValue(true)
        expect(indicator('summary', 'closed_percentage').element.checked).toBe(true)
        expect(format('Formato de Percentual de encerramento').element.value).toBe('table')
    })

    it('preserves a custom title across changes and restores the current automatic suggestion', async () => {
        await mountModal()
        await sectionCheckbox('summary').setValue(true)
        expect(title().element.value).toBe('Relatório de indicadores de atendimento')
        await title().setValue('Título escolhido')
        await expand('vehicle_problems')
        await primary().setValue('model')
        await expand('summary')
        await indicator('summary', 'total').setValue(false)
        expect(title().element.value).toBe('Título escolhido')
        expect(wrapper.get('.pdf-title-mode').text()).toBe('Personalizado')
        await button('Restaurar título automático').trigger('click')
        expect(title().element.value).toBe('Relatório de problemas por modelo veicular')
        expect(wrapper.get('.pdf-title-mode').text()).toBe('Automático')
    })

    it('shows applied vehicle filters read-only and sends the unchanged filters without including a plate in the suggestion', async () => {
        const filters = {
            ...scope,
            system_id: 1,
            problem_category_id: 3,
            problem_subcategory_id: 4,
            vehicle_presence: 'with',
            vehicle_brand_id: 5,
            vehicle_line: 'light',
            vehicle_plate: 'HFS4456',
            vehicle_model: 'Focus 2.0',
        }
        await mountModal(filters)
        await button('Filtros aplicados').trigger('click')
        const criteria = wrapper.get('#metrics-pdf-filters')
        expect(criteria.text()).toContain('Indicar')
        expect(criteria.text()).toContain('Falha de indicação veicular')
        expect(criteria.text()).toContain('Identificação veicularCom veículo')
        expect(criteria.text()).toContain('MarcaFord')
        expect(criteria.text()).toContain('ModeloFocus 2.0')
        expect(criteria.text()).toContain('Linha veicularLinha leve')
        expect(criteria.text()).toContain('Filtro de placa aplicado')
        expect(criteria.text()).not.toContain('HFS4456')
        expect(criteria.find('input').exists()).toBe(false)
        expect(title().element.value).toBe('Relatório de erros de indicação veicular — Ford')
        await sectionCheckbox('summary').setValue(true)
        await subtitle().setValue('  Análise do setor  ')
        await submit()
        await flushPromises()
        expect(generatePdf.mock.calls[0][0]).toEqual({
            ...filters,
            ...frozenDates,
            configuration: {
                sections: {
                    summary: {
                        enabled: true,
                        indicators: { total: { enabled: true, format: 'automatic' } },
                    },
                },
                title: { mode: 'automatic', text: null },
                subtitle: 'Análise do setor',
            },
        })
    })

    it('omits the plate filter value if it was copied into another visible filter label', async () => {
        await mountModal({
            ...scope,
            system_id: 2,
            vehicle_plate: 'hfs - 4456',
            vehicle_model: 'Série LX (HFS4456)',
        })
        await button('Filtros aplicados').trigger('click')
        const criteria = wrapper.get('#metrics-pdf-filters')
        expect(criteria.text()).toContain('Sistema selecionado')
        expect(criteria.text()).toContain('Filtro de descrição do modelo aplicado')
        expect(criteria.text()).not.toContain('HFS4456')
        expect(title().element.value).toBe('Relatório estatístico de atendimentos')
    })

    it('checks blank and overlong fields locally and displays backend text errors on the right field', async () => {
        await mountModal()
        await sectionCheckbox('summary').setValue(true)
        expect(subtitle().attributes('maxlength')).toBe('180')
        await title().setValue('  ')
        expect(button('Exportar PDF').element.disabled).toBe(true)
        expect(wrapper.get('#metrics-pdf-title-error').text()).toBe(
            'Informe o título personalizado.',
        )
        await title().setValue('  Recorte de atendimentos  ')
        await subtitle().setValue('a'.repeat(181))
        expect(button('Exportar PDF').element.disabled).toBe(true)
        expect(wrapper.get('#metrics-pdf-subtitle-error').text()).toContain('180')
        await submit()
        expect(generatePdf).not.toHaveBeenCalled()

        generatePdf.mockRejectedValueOnce(
            new ApiError(422, {
                errors: { 'configuration.subtitle': ['Informe um texto simples, sem HTML.'] },
            }),
        )
        await subtitle().setValue('<b>Equipe</b>')
        await submit()
        await flushPromises()
        expect(generatePdf.mock.calls[0][0].configuration.title).toEqual({
            mode: 'custom',
            text: 'Recorte de atendimentos',
        })
        expect(wrapper.get('#metrics-pdf-subtitle-error').text()).toContain('sem HTML')
        expect(subtitle().attributes('aria-invalid')).toBe('true')
        expect(document.activeElement).toBe(subtitle().element)
        expect(button('Exportar PDF').element.disabled).toBe(true)
        await subtitle().setValue('Equipe')
        expect(button('Exportar PDF').element.disabled).toBe(false)
        await submit()
        await flushPromises()
        expect(save).toHaveBeenCalledWith(file)
    })

    it('retries a failed catalog request and excludes both actions during a pending generation', async () => {
        get.mockRejectedValueOnce(new Error('failure'))
        await mountModal()
        expect(wrapper.text()).toContain('Não foi possível carregar as opções do relatório.')
        await button('Tentar novamente').trigger('click')
        await flushPromises()
        await sectionCheckbox('summary').setValue(true)
        let complete
        generatePdf.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    complete = resolve
                }),
        )
        await submit()
        await submit()
        expect(button('Pré-visualizar').element.disabled).toBe(true)
        expect(button('Preparando relatório PDF…').element.disabled).toBe(true)
        expect(generatePdf).toHaveBeenCalledTimes(1)
        complete(file)
        await flushPromises()
        expect(save).toHaveBeenCalledWith(file)
    })

    it('displays the received PDF and downloads the identical Blob without regenerating', async () => {
        await mountModal()
        await sectionCheckbox('summary').setValue(true)
        await openPreview()
        expect(generatePdf).toHaveBeenCalledWith(
            expect.any(Object),
            'inline',
            expect.any(AbortSignal),
        )
        expect(createUrl).toHaveBeenCalledWith(file.blob)
        expect(wrapper.get('iframe[title="Páginas do relatório PDF"]').attributes('src')).toBe(
            'blob:preview',
        )
        expect(document.activeElement).toBe(button('Voltar à configuração').element)
        await button('Baixar PDF').trigger('click')
        expect(save.mock.calls[0][0].blob).toBe(file.blob)
        expect(save.mock.calls[0][0].filename).toBe(file.filename)
        expect(generatePdf).toHaveBeenCalledTimes(1)
        expect(releaseUrl).not.toHaveBeenCalled()
        expect(wrapper.find('iframe').exists()).toBe(true)
    })

    it('starts one download for two consecutive clicks and allows an intentional retry', async () => {
        await mountModal()
        await sectionCheckbox('summary').setValue(true)
        await openPreview()
        const clock = vi.spyOn(performance, 'now').mockReturnValue(0)
        const download = button('Baixar PDF')
        await Promise.all([download.trigger('click'), download.trigger('click')])
        expect(save).toHaveBeenCalledExactlyOnceWith(file)
        expect(releaseUrl).not.toHaveBeenCalled()
        clock.mockReturnValue(1000)
        await download.trigger('click')
        expect(save).toHaveBeenCalledTimes(2)
        expect(generatePdf).toHaveBeenCalledTimes(1)
        wrapper.unmount()
        wrapper = null
        expect(releaseUrl).toHaveBeenCalledExactlyOnceWith('blob:preview')
    })

    it('returns to the same preferences, revokes the old preview and downloads a newly generated selection', async () => {
        await mountModal()
        await sectionCheckbox('summary').setValue(true)
        await expand('summary')
        await advanced().setValue(true)
        await format('Formato de Total de atendimentos').setValue('table')
        await expand('vehicle_problems')
        await primary().setValue('brand')
        await wrapper.get('#metrics-pdf-group-add-model').setValue(true)
        await indicator('vehicle_problems', 'systems').setValue(true)
        await title().setValue('Título da área')
        await subtitle().setValue('Subtítulo da área')
        await openPreview()
        await button('Voltar à configuração').trigger('click')
        expect(releaseUrl).toHaveBeenCalledExactlyOnceWith('blob:preview')
        expect(wrapper.find('iframe').exists()).toBe(false)
        expect(button('Baixar PDF')).toBeUndefined()
        expect(document.activeElement).toBe(button('Pré-visualizar').element)
        expect(title().element.value).toBe('Título da área')
        expect(subtitle().element.value).toBe('Subtítulo da área')
        expect(format('Formato de Total de atendimentos').element.value).toBe('table')
        expect(primary().element.value).toBe('brand')
        expect(wrapper.get('#metrics-pdf-group-add-model').element.checked).toBe(true)
        expect(indicator('vehicle_problems', 'systems').element.checked).toBe(true)

        const updated = { ...file, blob: new Blob(['updated'], { type: 'application/pdf' }) }
        generatePdf.mockResolvedValueOnce(updated)
        createUrl.mockReturnValueOnce('blob:updated')
        await subtitle().setValue('Novo subtítulo')
        await openPreview()
        expect(generatePdf.mock.calls.map(([body]) => body.configuration.subtitle)).toEqual([
            'Subtítulo da área',
            'Novo subtítulo',
        ])
        expect(wrapper.get('iframe').attributes('src')).toBe('blob:updated')
        await button('Baixar PDF').trigger('click')
        expect(save.mock.calls[0][0].blob).toBe(updated.blob)
        wrapper.unmount()
        wrapper = null
        expect(releaseUrl).toHaveBeenLastCalledWith('blob:updated')
    })

    it('offers an open-in-tab and download alternative when the native viewer is unavailable or fails to load', async () => {
        Object.defineProperty(navigator, 'pdfViewerEnabled', { value: false, configurable: true })
        await mountModal()
        await sectionCheckbox('summary').setValue(true)
        await openPreview()
        expect(wrapper.find('iframe').exists()).toBe(false)
        expect(wrapper.get('a[target="_blank"]').attributes('href')).toBe('blob:preview')
        expect(wrapper.text()).toContain('visualização dentro do modal está indisponível')
        await button('Voltar à configuração').trigger('click')
        Object.defineProperty(navigator, 'pdfViewerEnabled', { value: true, configurable: true })
        await openPreview()
        await wrapper.get('iframe').trigger('error')
        expect(wrapper.find('iframe').exists()).toBe(false)
        await button('Baixar PDF').trigger('click')
        expect(save).toHaveBeenCalledWith(file)
    })

    it.each([
        [0, 'conectar ao servidor'],
        [408, 'tempo limite'],
        [500, 'Não foi possível gerar o relatório'],
        [502, 'PDF inválido ou vazio'],
    ])(
        'preserves the draft for retry when generation returns status %s',
        async (status, message) => {
            generatePdf.mockRejectedValueOnce(new ApiError(status))
            await mountModal()
            await sectionCheckbox('summary').setValue(true)
            await title().setValue('Título conservado')
            await openPreview()
            expect(wrapper.get('[role="alert"]').text()).toContain(message)
            expect(title().element.value).toBe('Título conservado')
            expect(save).not.toHaveBeenCalled()
            expect(createUrl).not.toHaveBeenCalled()
            await openPreview()
            expect(wrapper.get('iframe').exists()).toBe(true)
        },
    )

    it('prevents duplicate previews and preserves the file after a failed download', async () => {
        let complete
        generatePdf.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    complete = resolve
                }),
        )
        await mountModal()
        await sectionCheckbox('summary').setValue(true)
        await openPreview()
        expect(button('Gerando pré-visualização…').element.disabled).toBe(true)
        await submit()
        expect(generatePdf).toHaveBeenCalledTimes(1)
        complete(file)
        await flushPromises()
        save.mockImplementationOnce(() => {
            throw new Error('private stack')
        })
        await button('Baixar PDF').trigger('click')
        expect(wrapper.text()).toContain('Não foi possível iniciar o download')
        expect(wrapper.text()).not.toContain('private stack')
        expect(wrapper.find('iframe').exists()).toBe(true)
        await button('Baixar PDF').trigger('click')
        expect(save).toHaveBeenCalledTimes(2)
        expect(generatePdf).toHaveBeenCalledTimes(1)
    })
})

it('prioritizes filtered topics and report content before using a safe brand or system suffix', () => {
    const generate = (sections, filters, brand) =>
        automaticMetricsPdfTitle(catalog, { sections }, filters, names, brand)
    expect(generate({}, { system_id: 1 })).toBe('Relatório de atendimentos — Indicar')
    expect(generate({}, { system_id: 2, vehicle_plate: 'HFS4456' })).toBe(
        'Relatório estatístico de atendimentos',
    )
    expect(generate({}, { problem_category_id: 3, vehicle_brand_id: 5 }, 'Ford')).toBe(
        'Relatório de erros de indicação — Ford',
    )
    expect(generate({ vehicles: {} }, { problem_category_id: 3 })).toBe(
        'Relatório de erros de indicação veicular',
    )
    expect(generate({ vehicles: {} }, {})).toBe('Relatório de estatísticas veiculares')
    expect(generate({}, { vehicle_brand_id: 5, system_id: 1 }, 'Marca '.repeat(30))).toBe(
        'Relatório de atendimentos — Indicar',
    )
})

async function mountMetrics() {
    const metricResults = {
        summary: { total: 0 },
        systems: [],
        categories: [],
        subcategories: [],
        monthly: [],
    }
    get.mockImplementation(async (path, params) => {
        if (path === endpoint) return { data: catalog }
        if (path.startsWith('metrics/'))
            return {
                data: metricResults[path.split('/')[1]],
                meta: { filters: { ...resolvedFilters, ...params } },
            }
        return { data: [], meta: { last_page: 1 } }
    })
    wrapper = mount(MetricsDashboard, {
        attachTo: document.body,
        global: { stubs: { Teleport: true } },
    })
    await flushPromises()
}

it('opens from Metrics with the applied filters and restores focus on Escape while leaving Excel available', async () => {
    const download = vi.spyOn(api, 'download')
    await mountMetrics()
    await wrapper.get('#metrics-period').setValue('all')
    await wrapper.get('form[aria-label="Filtros estatísticos"]').trigger('submit')
    await flushPromises()
    await wrapper.get('#metrics-period').setValue('this_month')
    const open = button('Exportar PDF')
    open.element.focus()
    await open.trigger('click')
    await flushPromises()
    await sectionCheckbox('summary').setValue(true)
    await openPreview()
    expect(generatePdf.mock.calls[0][0].period).toBe('all')
    expect(download).not.toHaveBeenCalled()
    await wrapper.get('[role="dialog"]').trigger('keydown', { key: 'Escape' })
    await flushPromises()
    expect(wrapper.findComponent(MetricsPdfConfigurationModal).exists()).toBe(false)
    expect(document.activeElement).toBe(open.element)
    expect(wrapper.get('#metrics-period').element.value).toBe('this_month')
    expect(releaseUrl).toHaveBeenCalledWith('blob:preview')
    await button('Exportar Excel').trigger('click')
    expect(wrapper.get('#metrics-export-form').exists()).toBe(true)
})

it('drops the preview and any pending file when new dashboard filters are applied', async () => {
    await mountMetrics()
    await button('Exportar PDF').trigger('click')
    await flushPromises()
    await sectionCheckbox('summary').setValue(true)
    await openPreview()
    await wrapper.get('#metrics-period').setValue('all')
    const applyFilters = () =>
        wrapper.get('form[aria-label="Filtros estatísticos"]').trigger('submit')
    await applyFilters()
    await flushPromises()
    expect(releaseUrl).toHaveBeenCalledWith('blob:preview')
    expect(wrapper.findComponent(MetricsPdfConfigurationModal).exists()).toBe(false)

    await button('Exportar PDF').trigger('click')
    await flushPromises()
    expect(sectionCheckbox('summary').element.checked).toBe(false)
    await sectionCheckbox('summary').setValue(true)
    let complete
    generatePdf.mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                complete = resolve
            }),
    )
    await openPreview()
    const [payload, disposition, signal] = generatePdf.mock.calls[1]
    expect(payload.period).toBe('all')
    expect(disposition).toBe('inline')
    await wrapper.get('#metrics-status').setValue('closed')
    await applyFilters()
    await flushPromises()
    expect(signal.aborted).toBe(true)
    complete(file)
    await flushPromises()
    expect(createUrl).toHaveBeenCalledTimes(1)
    expect(save).not.toHaveBeenCalled()
})
