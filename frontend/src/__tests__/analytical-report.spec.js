import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import { api, ApiError } from '../services/api'
import * as downloads from '../utils/download'
import MetricsDashboard from '../components/metrics/MetricsDashboard.vue'
import MetricsFilters from '../components/metrics/MetricsFilters.vue'
import MetricsAnalyticalReportModal from '../components/metrics/MetricsAnalyticalReportModal.vue'

const endpoint = 'metrics/analytical-report/configuration'
const ticketsEndpoint = 'metrics/analytical-report/tickets'
const automaticTitle = 'Relatório analítico de erros de indicação — Ford'
const applied = {
    period: 'last_6_months',
    system_id: 1,
    problem_category_id: 3,
    problem_subcategory_id: 5,
    status: 'closed',
    classification: 'all',
    vehicle_presence: 'with',
    vehicle_plate: 'HFS4456',
    vehicle_brand_id: 4,
    vehicle_model: '2007',
    vehicle_line: 'light',
}
const resolved = {
    ...applied,
    date_from: '2026-06-01',
    date_to: '2026-11-25',
    timezone: 'America/Sao_Paulo',
}
const frozen = {
    ...applied,
    period: 'custom',
    date_from: resolved.date_from,
    date_to: resolved.date_to,
}
const filterCatalog = {
    systems: [{ id: 1, name: 'Indicar' }],
    categories: [{ id: 3, name: 'Erro de indicação' }],
    subcategories: [{ id: 5, name: 'Modelo divergente' }],
}
const section = (label, fields) => ({
    label,
    fields: Object.fromEntries(Object.entries(fields).map(([key, label]) => [key, { label }])),
})
const sections = {
    ticket: section('Informações do atendimento', {
        title: 'Título',
        system: 'Sistema',
        contact: 'Contato',
        customer: 'Cliente',
        previous_ticket: 'Atendimento anterior',
        description: 'Descrição do problema',
        problem_category: 'Categoria do problema',
        problem_subcategories: 'Subcategorias do problema',
    }),
    vehicle: section('Identificação veicular', {
        vehicle_plate: 'Placa',
        vehicle_brand: 'Marca',
        vehicle_model: 'Modelo completo',
        vehicle_line: 'Linha veicular',
    }),
    resolution: section('Resolução e confirmação', {
        resolution: 'Solução aplicada',
        verification_source: 'Fonte da confirmação',
        verified_at: 'Data e horário da confirmação',
        verified_by: 'Confirmado por',
        verification_notes: 'Como a resolução foi verificada',
    }),
}
const defaults = {
    selection: { mode: 'all', ids: [] },
    sections: Object.fromEntries(
        Object.entries(sections).map(([id, value]) => [
            id,
            { enabled: true, fields: Object.keys(value.fields) },
        ]),
    ),
    grouping: 'none',
    order: 'newest',
    empty_fields: 'smart',
    title: { mode: 'automatic', text: null },
    subtitle: null,
    layout: { pagination: 'automatic', columns: 'automatic', spacing: 'normal' },
}
const catalog = {
    sections,
    groupings: {
        none: { label: 'Sem agrupamento', required_fields: [] },
        system: { label: 'Por sistema', required_fields: ['system'] },
        category: { label: 'Por categoria', required_fields: ['problem_category'] },
        brand: { label: 'Por marca veicular', required_fields: ['vehicle_brand'] },
        model: {
            label: 'Por modelo veicular',
            required_fields: ['vehicle_brand', 'vehicle_model'],
        },
    },
    orders: {
        newest: 'Mais recentes primeiro',
        oldest: 'Mais antigos primeiro',
        title: 'Ordem alfabética do título',
    },
    empty_modes: {
        smart: 'Tratamento inteligente',
        show: 'Exibir todos os campos selecionados',
        hide: 'Ocultar campos vazios',
    },
    layout: {
        pagination: { automatic: 'Automática', compact: 'Compacta', individual: 'Individual' },
        columns: { automatic: 'Automáticas', one: 'Uma coluna', two: 'Duas colunas' },
        spacing: { compact: 'Compacto', normal: 'Normal', expanded: 'Ampliado' },
    },
    title: { max_length: 120 },
    subtitle: { max_length: 180 },
    defaults,
}
function pageOf(page, total = 150) {
    const first = (page - 1) * 20 + 1
    const last = Math.min(first + 19, total)
    return {
        data: Array.from({ length: Math.max(0, last - first + 1) }, (_, index) => ({
            id: first + index,
            title: 'Atendimento ' + (first + index),
            created_at: '2026-11-20T09:00:00-03:00',
            system: 'Indicar',
            category: 'Erro de indicação',
            subcategories: ['Modelo divergente', 'Ano divergente'],
            vehicle: { brand: 'Ford', model: 'Modelo de teste' },
            description: 'CONTEÚDO PRIVADO',
        })),
        meta: {
            current_page: page,
            from: total ? first : null,
            to: total ? last : null,
            total,
            last_page: Math.max(1, Math.ceil(total / 20)),
        },
    }
}
const normalized = ({ configuration }) => ({
    data: {
        configuration,
        title: configuration.title.text || automaticTitle,
        eligible_count: 150,
        selected_count:
            configuration.selection.mode === 'only'
                ? configuration.selection.ids.length
                : 150 - configuration.selection.ids.length,
    },
})
const pdf = (selectedCount = 150, content = 'teste') => ({
    blob: new Blob([`%PDF-1.7\n${content}\n%%EOF`], { type: 'application/pdf' }),
    filename: 'supportflow-relatorio-analitico-2026-11-25.pdf',
    selectedCount,
})
let wrapper, gets, posts, generation, save, createUrl, revokeUrl

beforeEach(() => {
    gets = vi.spyOn(api, 'get').mockImplementation(async (path, params) => {
        if (path === endpoint) return { data: catalog }
        if (path === ticketsEndpoint) return pageOf(params.page)
        if (path === 'metrics/summary')
            return {
                data: { total: 150, not_closed: 1, closed: 149, systems_count: 1 },
                meta: { filters: resolved },
            }
        if (
            ['systems', 'categories', 'subcategories', 'monthly'].some(
                (name) => path === 'metrics/' + name,
            )
        )
            return { data: [] }
        return { data: [], meta: { last_page: 1 } }
    })
    posts = vi.spyOn(api, 'post').mockImplementation(async (path, payload) => normalized(payload))
    generation = vi
        .spyOn(api, 'exportAnalyticalPdf')
        .mockImplementation(async (payload) => pdf(normalized(payload).data.selected_count))
    save = vi.spyOn(downloads, 'saveDownload').mockImplementation(() => {})
    let urlIndex = 0
    createUrl = vi.fn(() => `blob:analytical-${++urlIndex}`)
    revokeUrl = vi.fn()
    vi.stubGlobal(
        'URL',
        class extends URL {
            static createObjectURL = createUrl
            static revokeObjectURL = revokeUrl
        },
    )
})
afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
})

async function mountModal() {
    wrapper = mount(
        defineComponent({
            components: { MetricsAnalyticalReportModal },
            setup() {
                return { open: ref(true), draft: ref(null), applied, resolved, filterCatalog }
            },
            template:
                '<MetricsAnalyticalReportModal v-if="open" v-model:draft="draft" :filters="applied" :resolved-filters="resolved" :filter-catalog="filterCatalog" @close="open = false" />',
        }),
        { attachTo: document.body, global: { stubs: { Teleport: true } } },
    )
    await flushPromises()
}
const button = (label) => wrapper.findAll('button').find((node) => node.text() === label)
const tab = (name) => button(name).trigger('click')
const rows = () => wrapper.get('.analytical-tickets').findAll('input')
const summary = () =>
    Object.fromEntries(
        wrapper
            .findAll('.analytical-report-summary dl div')
            .map((item) => [item.get('dt').text(), item.get('dd').text()]),
    )
const submit = () => wrapper.get('form.analytical-report').trigger('submit')
const startPreview = () => button('Pré-visualizar').trigger('click')
const back = () => button('Voltar às configurações').trigger('click')
const field = (name) => wrapper.get(`[id^="analytical-report-"][id$="-${name}"]:is(input, select)`)
const sectionControl = (label) =>
    wrapper
        .findAll('.analytical-field-heading label')
        .find((node) => node.text() === label)
        .get('input')
const individual = (label) =>
    wrapper
        .findAll('.analytical-field-options label')
        .find((node) => node.text() === label)
        .get('input')
const expand = (id) =>
    wrapper
        .get(`[aria-controls^="analytical-report-"][aria-controls$="-${id}"]:not([role="tab"])`)
        .trigger('click')

describe('analytical configuration modal', () => {
    it('loads catalog defaults and snapshots every Dashboard criterion before querying or requesting a title', async () => {
        await mountModal()
        expect(gets).toHaveBeenCalledWith(endpoint, undefined, expect.any(AbortSignal))
        expect(gets).toHaveBeenCalledWith(
            ticketsEndpoint,
            { ...frozen, page: 1, per_page: 20 },
            expect.any(AbortSignal),
        )
        expect(posts).toHaveBeenCalledExactlyOnceWith(
            endpoint,
            { ...frozen, configuration: defaults },
            expect.any(AbortSignal),
        )
        expect(summary()).toEqual({
            Elegíveis: '150',
            Selecionados: '150',
            Campos: '17',
            Seções: '3',
        })
        expect(wrapper.get('.analytical-filters').text()).toContain(
            '25/11/2026',
            'Erro de indicação',
            'Modelo divergente',
            'Filtro de placa aplicado',
        )
        expect(wrapper.text()).not.toContain('HFS4456', 'CONTEÚDO PRIVADO')
        expect(rows().every((row) => row.element.checked)).toBe(true)
        expect(button('Validar configuração')).toBeUndefined()
        expect(button('Pré-visualizar').element.disabled).toBe(false)
        expect(button('Exportar PDF').element.disabled).toBe(false)
    })

    it('retains excluded and explicitly included rows across pages and tabs', async () => {
        await mountModal()
        await rows()[0].setValue(false)
        await rows()[1].setValue(false)
        expect(summary().Selecionados).toBe('148')
        await tab('Conteúdo')
        expect(summary().Selecionados).toBe('148')
        await tab('Atendimentos')
        await button('Próxima').trigger('click')
        await flushPromises()
        expect(rows()[0].element.checked).toBe(true)
        await button('Anterior').trigger('click')
        await flushPromises()
        expect(
            rows()
                .slice(0, 3)
                .map((row) => row.element.checked),
        ).toEqual([false, false, true])
        await startPreview()
        await flushPromises()
        expect(generation.mock.lastCall[0].configuration.selection).toEqual({
            mode: 'all_except',
            ids: [1, 2],
        })
        await back()
        await button('Limpar seleção').trigger('click')
        expect(summary().Selecionados).toBe('0')
        expect(button('Exportar PDF').element.disabled).toBe(true)
        await rows()[0].setValue(true)
        await button('Próxima').trigger('click')
        await flushPromises()
        expect(rows()[0].element.checked).toBe(false)
        await rows()[1].setValue(true)
        expect(summary().Selecionados).toBe('2')
        await startPreview()
        await flushPromises()
        expect(generation.mock.lastCall[0].configuration.selection).toEqual({
            mode: 'only',
            ids: [1, 22],
        })
        await back()
        await button('Selecionar todos').trigger('click')
        expect(summary().Selecionados).toBe('150')
        await startPreview()
        await flushPromises()
        expect(generation.mock.lastCall[0].configuration.selection).toEqual({
            mode: 'all',
            ids: [],
        })
    })

    it('represents all 17 fields, empty sections and the native mixed checkbox state', async () => {
        await mountModal()
        await tab('Conteúdo')
        await expand('vehicle')
        await expand('resolution')
        expect(wrapper.findAll('.analytical-field-options input')).toHaveLength(17)
        expect(wrapper.findAll('.analytical-field-heading input')).toHaveLength(3)
        await sectionControl('Identificação veicular').setValue(false)
        expect(summary()).toMatchObject({ Campos: '13', Seções: '2' })
        await individual('Marca').setValue(true)
        expect(sectionControl('Identificação veicular').element.indeterminate).toBe(true)
        expect(sectionControl('Identificação veicular').attributes('aria-checked')).toBe('mixed')
        await sectionControl('Identificação veicular').setValue(true)
        expect(summary().Campos).toBe('17')
        await button('Limpar seleção de campos').trigger('click')
        expect(summary()).toMatchObject({ Campos: '0', Seções: '0' })
        expect(button('Exportar PDF').element.disabled).toBe(true)
        await field('empty-fields').setValue('hide')
        await button('Selecionar todos os campos').trigger('click')
        expect(summary().Campos).toBe('17')
        await individual('Solução aplicada').setValue(false)
        await button('Restaurar conteúdo padrão').trigger('click')
        expect(field('empty-fields').element.value).toBe('smart')
        expect(summary().Campos).toBe('17')
    })

    it('uses catalog dependencies and preserves an incompatible grouping until the user fixes it', async () => {
        await mountModal()
        await tab('Apresentação')
        expect(
            field('grouping')
                .findAll('option')
                .map((option) => option.element.value),
        ).toEqual(['none', 'system', 'category', 'brand', 'model'])
        await field('grouping').setValue('model')
        await tab('Conteúdo')
        await expand('vehicle')
        await individual('Marca').setValue(false)
        expect(wrapper.get('[role="alert"]').text()).toContain('Por modelo veicular', 'Marca')
        expect(button('Exportar PDF').element.disabled).toBe(true)
        await tab('Apresentação')
        expect(field('grouping').element.value).toBe('model')
        expect(field('grouping').get('option[value="brand"]').element.disabled).toBe(true)
        await field('grouping').setValue('category')
        await tab('Conteúdo')
        expect(individual('Marca').element.checked).toBe(false)
        await individual('Título').setValue(false)
        await tab('Apresentação')
        await field('order').setValue('title')
        expect(button('Exportar PDF').element.disabled).toBe(false)
    })

    it('sends all presentation options and preserves the draft, active tab and automatic title controls', async () => {
        await mountModal()
        await tab('Apresentação')
        expect(field('title').element.value).toBe(automaticTitle)
        expect(field('subtitle').element.value).toBe('')
        for (const [name, value] of Object.entries({
            grouping: 'system',
            order: 'oldest',
            pagination: 'individual',
            columns: 'two',
            spacing: 'expanded',
        }))
            await field(name).setValue(value)
        expect(wrapper.text()).toContain('Cada atendimento começa em uma nova página')
        await field('title').setValue('  Revisão técnica  ')
        await field('subtitle').setValue(' Conferência ')
        await tab('Conteúdo')
        await field('empty-fields').setValue('show')
        await tab('Apresentação')
        expect(field('title').element.value).toBe('  Revisão técnica  ')
        expect(posts).toHaveBeenCalledTimes(1)
        await startPreview()
        await flushPromises()
        expect(generation.mock.lastCall).toEqual([
            {
                ...frozen,
                configuration: {
                    ...defaults,
                    grouping: 'system',
                    order: 'oldest',
                    empty_fields: 'show',
                    title: { mode: 'custom', text: 'Revisão técnica' },
                    subtitle: 'Conferência',
                    layout: { pagination: 'individual', columns: 'two', spacing: 'expanded' },
                },
            },
            'inline',
            expect.any(AbortSignal),
        ])
        await back()
        expect(button('Apresentação').attributes('aria-selected')).toBe('true')
        expect(field('title').element.value).toBe('  Revisão técnica  ')
        expect(field('subtitle').element.value).toBe(' Conferência ')
        expect(field('grouping').element.value).toBe('system')
        expect(field('order').element.value).toBe('oldest')
        expect(field('pagination').element.value).toBe('individual')
        expect(field('columns').element.value).toBe('two')
        expect(field('spacing').element.value).toBe('expanded')
        await button('Restaurar título automático').trigger('click')
        expect(field('title').element.value).toBe(automaticTitle)
        expect(field('subtitle').element.value).toBe(' Conferência ')
        expect(posts).toHaveBeenCalledTimes(1)
        await button('Restaurar apresentação padrão').trigger('click')
        expect(field('pagination').element.value).toBe('automatic')
        await tab('Conteúdo')
        expect(field('empty-fields').element.value).toBe('show')
        await button('Restaurar toda a configuração').trigger('click')
        expect(field('empty-fields').element.value).toBe('smart')
    })

    it('blocks both actions while generation is in progress', async () => {
        await mountModal()
        let finish
        generation.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finish = () => resolve(pdf())
                }),
        )
        await startPreview()
        expect(button('Gerando pré-visualização…').element.disabled).toBe(true)
        expect(button('Exportar PDF').element.disabled).toBe(true)
        await button('Gerando pré-visualização…').trigger('click')
        await submit()
        expect(generation).toHaveBeenCalledOnce()
        finish()
        await flushPromises()
        expect(wrapper.get('iframe').attributes('src')).toBe('blob:analytical-1')
        expect(generation).toHaveBeenCalledOnce()
    })

    it('shows 422 errors by field, preserves choices and unlocks validation after a correction', async () => {
        await mountModal()
        await rows()[0].setValue(false)
        await tab('Apresentação')
        await field('subtitle').setValue('<script>')
        await tab('Atendimentos')
        generation.mockRejectedValueOnce(
            new ApiError(422, {
                errors: {
                    'configuration.subtitle': ['Informe um texto simples.'],
                    'configuration.selection.ids': ['Um atendimento está fora do recorte.'],
                },
            }),
        )
        await submit()
        await flushPromises()
        expect(field('subtitle').attributes('aria-invalid')).toBe('true')
        expect(document.activeElement).toBe(field('subtitle').element)
        expect(wrapper.text()).toContain(
            'Informe um texto simples.',
            'Um atendimento está fora do recorte.',
        )
        expect(summary().Selecionados).toBe('149')
        expect(button('Exportar PDF').element.disabled).toBe(true)
        await field('subtitle').setValue('Revisão')
        await startPreview()
        await flushPromises()
        expect(wrapper.find('iframe').exists()).toBe(true)
    })

    it('blocks invalid local content lengths or an empty custom title', async () => {
        await mountModal()
        await tab('Apresentação')
        await field('title').setValue('  ')
        expect(wrapper.text()).toContain('Informe o título personalizado.')
        await field('title').setValue('Título válido')
        await field('subtitle').setValue('Á'.repeat(181))
        expect(wrapper.text()).toContain('Informe até 180 caracteres.')
        await submit()
        expect(posts).toHaveBeenCalledTimes(1)
        expect(generation).not.toHaveBeenCalled()
    })

    it('shows an empty recorte without attempting automatic validation', async () => {
        gets.mockImplementationOnce(async () => ({ data: catalog })).mockResolvedValueOnce(
            pageOf(1, 0),
        )
        await mountModal()
        expect(wrapper.text()).toContain('Nenhum atendimento elegível')
        expect(summary()).toMatchObject({ Elegíveis: '0', Selecionados: '0' })
        expect(button('Exportar PDF').element.disabled).toBe(true)
        await tab('Apresentação')
        expect(field('title').element.value).toBe('')
        expect(posts).not.toHaveBeenCalled()
    })

    it.each(['catalog', 'tickets'])('lets the user retry a failed %s request', async (failed) => {
        if (failed === 'catalog') gets.mockRejectedValueOnce(new ApiError(0))
        else gets.mockResolvedValueOnce({ data: catalog }).mockRejectedValueOnce(new ApiError(0))
        await mountModal()
        expect(wrapper.text()).toContain('Não foi possível carregar')
        expect(button('Exportar PDF').element.disabled).toBe(true)
        await button('Tentar novamente').trigger('click')
        await flushPromises()
        expect(summary().Selecionados).toBe('150')
        expect(button('Exportar PDF').element.disabled).toBe(false)
    })

    it('retries the automatic title and allows keyboard navigation across the tabs', async () => {
        posts.mockRejectedValueOnce(new ApiError(0))
        await mountModal()
        await button('Atendimentos').trigger('keydown', { key: 'End' })
        expect(document.activeElement).toBe(button('Apresentação').element)
        expect(wrapper.text()).toContain('Não foi possível obter o título automático.')
        await button('Tentar obter o título').trigger('click')
        await flushPromises()
        expect(field('title').element.value).toBe(automaticTitle)
        await button('Apresentação').trigger('keydown', { key: 'ArrowLeft' })
        expect(document.activeElement).toBe(button('Conteúdo').element)
        expect(button('Conteúdo').attributes('aria-selected')).toBe('true')
    })

    it('updates the automatic title when a selected filter field is removed from the report', async () => {
        await mountModal()
        posts.mockResolvedValueOnce({
            data: { title: 'Relatório analítico de erros de indicação — Indicar' },
        })
        await tab('Conteúdo')
        await expand('vehicle')
        await individual('Marca').setValue(false)
        await tab('Apresentação')
        await flushPromises()

        expect(posts.mock.lastCall[1].configuration.sections.vehicle.fields).not.toContain(
            'vehicle_brand',
        )
        expect(field('title').element.value).toBe(
            'Relatório analítico de erros de indicação — Indicar',
        )
    })

    it('discards an older automatic title response and keeps manual edits while suggestions refresh', async () => {
        await mountModal()
        let finishOld
        posts.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finishOld = resolve
                }),
        )
        await tab('Conteúdo')
        await expand('vehicle')
        await individual('Marca').setValue(false)
        const older = posts.mock.lastCall[2]
        await tab('Apresentação')
        await field('title').setValue('Título manual de auditoria')
        await tab('Conteúdo')
        posts.mockResolvedValueOnce({ data: { title: 'Relatório analítico de atendimentos' } })
        await individual('Categoria do problema').setValue(false)
        expect(older.aborted).toBe(true)
        finishOld({ data: { title: 'Resposta anterior ao último ajuste' } })
        await flushPromises()
        await tab('Apresentação')
        expect(field('title').element.value).toBe('Título manual de auditoria')
        await button('Restaurar título automático').trigger('click')
        expect(field('title').element.value).toBe('Relatório analítico de atendimentos')
    })

    it('previews the received bytes, downloads the same Blob once, preserves paging and replaces the document on a new generation', async () => {
        await mountModal()
        await tab('Conteúdo')
        await expand('vehicle')
        await individual('Placa').setValue(false)
        await tab('Atendimentos')
        await button('Próxima').trigger('click')
        await flushPromises()
        await rows()[0].setValue(false)
        const first = pdf(149, 'primeira emissão')
        const second = pdf(148, 'segunda emissão')
        generation.mockResolvedValueOnce(first).mockResolvedValueOnce(second)

        await startPreview()
        await flushPromises()
        expect(createUrl).toHaveBeenCalledExactlyOnceWith(first.blob)
        expect(wrapper.text()).toContain('Carregando visualização')
        await wrapper.get('iframe').trigger('load')
        expect(wrapper.text()).not.toContain('Carregando visualização')
        expect(document.activeElement).toBe(button('Voltar às configurações').element)
        await button('Baixar PDF').trigger('click')
        await button('Baixar PDF').trigger('click')
        expect(save).toHaveBeenCalledExactlyOnceWith(
            expect.objectContaining({ filename: first.filename }),
        )
        expect(save.mock.calls[0][0].blob).toBe(first.blob)
        expect(generation).toHaveBeenCalledOnce()

        await back()
        expect(revokeUrl).toHaveBeenCalledWith('blob:analytical-1')
        expect(wrapper.find('iframe').exists()).toBe(false)
        expect(document.activeElement).toBe(button('Pré-visualizar').element)
        expect(button('Atendimentos').attributes('aria-selected')).toBe('true')
        expect(rows()[0].element.checked).toBe(false)
        await rows()[1].setValue(false)
        await tab('Conteúdo')
        expect(individual('Placa').element.checked).toBe(false)
        await startPreview()
        await flushPromises()
        expect(generation.mock.lastCall[0].configuration.selection).toEqual({
            mode: 'all_except',
            ids: [21, 22],
        })
        expect(createUrl).toHaveBeenLastCalledWith(second.blob)
        expect(wrapper.get('iframe').attributes('src')).toBe('blob:analytical-2')
        await button('Baixar PDF').trigger('click')
        expect(save.mock.lastCall[0].blob).toBe(second.blob)
        expect(generation).toHaveBeenCalledTimes(2)
        await wrapper.get('button[aria-label="Fechar janela"]').trigger('click')
        expect(revokeUrl).toHaveBeenCalledWith('blob:analytical-2')
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    })

    it('invalidates a preview immediately if the current draft changes externally', async () => {
        await mountModal()
        await startPreview()
        await flushPromises()
        wrapper.vm.draft.subtitle = 'Configuração mais recente'
        await flushPromises()
        expect(revokeUrl).toHaveBeenCalledWith('blob:analytical-1')
        expect(button('Baixar PDF')).toBeUndefined()
        expect(button('Pré-visualizar')).toBeDefined()
    })

    it('keeps an unavailable inline document accessible for download and reports the effective count', async () => {
        await mountModal()
        const changed = pdf(151)
        generation.mockResolvedValueOnce(changed)
        await startPreview()
        await flushPromises()
        await wrapper.get('iframe').trigger('error')
        expect(wrapper.find('iframe').exists()).toBe(false)
        expect(wrapper.get('a[target="_blank"]').attributes('href')).toBe('blob:analytical-1')
        expect(wrapper.text()).toContain('O PDF inclui 151 atendimentos no recorte atual.')
        expect(wrapper.text()).toContain('A configuração indicava 150.')
        await button('Baixar PDF').trigger('click')
        expect(save.mock.lastCall[0].blob).toBe(changed.blob)
    })

    it.each([
        [0, 'Não foi possível conectar ao servidor'],
        [408, 'excedeu o tempo limite'],
        [500, 'Não foi possível gerar o relatório'],
        [502, 'PDF inválido ou vazio'],
    ])('preserves choices and permits direct export after a %i error', async (status, message) => {
        await mountModal()
        await rows()[0].setValue(false)
        generation.mockRejectedValueOnce(
            new ApiError(status, { message: 'Private technical error' }),
        )
        await submit()
        await flushPromises()
        expect(wrapper.text()).toContain(message)
        expect(wrapper.text()).not.toContain('Private technical error')
        expect(summary().Selecionados).toBe('149')
        expect(save).not.toHaveBeenCalled()
        expect(createUrl).not.toHaveBeenCalled()
        await submit()
        await flushPromises()
        expect(generation.mock.lastCall).toEqual([
            {
                ...frozen,
                configuration: { ...defaults, selection: { mode: 'all_except', ids: [1] } },
            },
            'attachment',
            expect.any(AbortSignal),
        ])
        expect(save).toHaveBeenCalledOnce()
        expect(generation).toHaveBeenCalledTimes(2)
        expect(createUrl).not.toHaveBeenCalled()
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    })

    it('keeps the configuration open if the direct browser download fails', async () => {
        await mountModal()
        save.mockImplementationOnce(() => {
            throw new Error('Private system path')
        })
        await submit()
        await flushPromises()
        expect(wrapper.text()).toContain('Não foi possível iniciar o download do PDF.')
        expect(summary().Selecionados).toBe('150')
        expect(document.activeElement.textContent).toContain('Não foi possível iniciar')
        await submit()
        await flushPromises()
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    })

    it('aborts the session on cancel and never presents a subsequently received file', async () => {
        await mountModal()
        let finish
        generation.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finish = resolve
                }),
        )
        await startPreview()
        const signal = generation.mock.lastCall[2]
        await button('Cancelar').trigger('click')
        expect(signal.aborted).toBe(true)
        finish(pdf())
        await flushPromises()
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
        expect(createUrl).not.toHaveBeenCalled()
        expect(save).not.toHaveBeenCalled()
    })

    it('offers to refresh the eligible list when selected records have changed', async () => {
        await mountModal()
        generation.mockRejectedValueOnce(
            new ApiError(422, {
                errors: {
                    'configuration.selection.ids': ['Um atendimento está fora do recorte.'],
                },
            }),
        )
        await startPreview()
        await flushPromises()
        gets.mockResolvedValueOnce(pageOf(1, 149))
        await button('Atualizar atendimentos').trigger('click')
        await flushPromises()
        expect(wrapper.text()).toContain('O total de elegíveis mudou.')
        expect(summary().Selecionados).toBe('149')
        expect(button('Pré-visualizar').element.disabled).toBe(false)
    })
})

it('opens from Metrics, preserves the old scope when external filters change and reopens with defaults', async () => {
    const storage = vi.spyOn(Storage.prototype, 'setItem')
    wrapper = mount(MetricsDashboard, {
        attachTo: document.body,
        global: { stubs: { Teleport: true, VehicleMetricsSection: true } },
    })
    await flushPromises()
    expect(button('Exportar PDF estatístico')).toBeDefined()
    expect(button('Exportar Excel')).toBeDefined()
    const open = button('Relatório analítico (PDF)')
    open.element.focus()
    await open.trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="dialog"] h2').text()).toBe('Relatório analítico de atendimentos')
    await rows()[0].setValue(false)
    await tab('Apresentação')
    await field('title').setValue('Rascunho temporário')
    wrapper
        .findComponent(MetricsFilters)
        .vm.$emit('apply', { period: 'all', status: 'open', classification: 'all' })
    await flushPromises()
    expect(wrapper.text()).toContain('Os filtros do Dashboard mudaram.')
    await startPreview()
    await flushPromises()
    expect(generation.mock.lastCall[0]).toMatchObject({ period: 'custom', status: 'all' })
    await back()
    await button('Cancelar').trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(document.activeElement).toBe(open.element)
    await open.trigger('click')
    await flushPromises()
    expect(summary()).toMatchObject({ Selecionados: '150', Campos: '17' })
    expect(posts.mock.lastCall[1]).toEqual({
        period: 'all',
        status: 'open',
        classification: 'all',
        configuration: defaults,
    })
    expect(wrapper.text()).not.toContain('Rascunho temporário')
    expect(storage).not.toHaveBeenCalled()
})
