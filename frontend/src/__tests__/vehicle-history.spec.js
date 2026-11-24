import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import App from '../App.vue'
import { routes } from '../router'
import { api, ApiError } from '../services/api'

const brand = { id: 7, name: 'Volkswagen' }
const vehicle = {
    id: 3,
    plate: 'HFS4456',
    brand,
    brand_id: brand.id,
    model_description: null,
    vehicle_line: 'light',
    support_tickets_count: 3,
}
const system = { id: 2, name: 'Consultas' }
const category = { id: 3, name: 'Identificação' }
const subcategories = [
    { id: 5, name: 'Placa' },
    { id: 6, name: 'Modelo' },
]
const ticket = {
    id: 10,
    title: 'Consulta veicular',
    description: 'Modelo divergente no aplicativo.',
    resolution: 'Cadastro central conferido.',
    status: 'closed',
    created_at: '2026-11-20T23:30:00-03:00',
    closed_at: '2026-11-21T10:00:00-03:00',
    system,
    contact: { name: 'Ana' },
    customer: { name: 'Oficina' },
    problem_category: category,
    problem_subcategories: subcategories,
    vehicle,
    vehicle_id: vehicle.id,
    pending_tasks: [],
    reminders: [],
    follow_up_tickets: [],
}
const unlinked = { ...ticket, id: 11, vehicle: null, vehicle_id: null, title: 'Sem identificação' }
const counts = { total: 3, not_closed: 2, closed: 1 }
const historyApi = 'vehicles/3/support-tickets'
const history = '/veiculos/3/historico'
const result = (data, extra = {}) => ({
    data,
    meta: { total: data.length, current_page: 1, last_page: 1, from: 1, to: data.length, ...extra },
})
const button = (scope, label) => scope.findAll('button').find((item) => item.text() === label)
let wrapper, router, get

beforeEach(() => {
    get = vi.spyOn(api, 'get').mockImplementation(async (path, query = {}) => {
        if (path === 'vehicles')
            return result([vehicle], { counts: { total: 1, light: 1, heavy: 0 } })
        if (path === 'vehicles/99/support-tickets') throw new ApiError(404)
        if (path === historyApi) {
            const rows = query.status === 'open' ? [] : [ticket]
            return result(rows, {
                counts,
                vehicle,
                current_page: Number(query.page || 1),
                last_page: 2,
                total: query.status ? rows.length : counts.total,
            })
        }
        if (path === 'support-tickets/10') return { data: ticket }
        if (path === 'support-tickets/11') return { data: unlinked }
        if (path === 'support-tickets') {
            if (query.vehicle_presence === 'without') return result([unlinked])
            if (query.vehicle_presence === 'with' || query.vehicle_plate) return result([ticket])
            return result([ticket, unlinked])
        }
        const lookups = {
            systems: [system],
            'problem-categories': [category],
            'problem-subcategories': subcategories,
            'vehicle-brands': [brand],
        }
        const [kind, id] = path.split('/')
        if (id) return { data: lookups[kind]?.find((item) => item.id === Number(id)) }
        return result(lookups[kind] ?? [])
    })
})

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.restoreAllMocks()
})

async function render(path) {
    router = createRouter({ history: createMemoryHistory(), routes })
    await router.push(path)
    wrapper = mount(App, { attachTo: document.body, global: { plugins: [router] } })
    await flushPromises()
}

async function follow(selector, target) {
    await wrapper.get(selector).trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe(target))
    await flushPromises()
}

async function choose(field, label) {
    await wrapper.get('#filter-' + field).trigger('click')
    await flushPromises()
    await button(wrapper.get('#filter-' + field + '-options'), label).trigger('click')
}

describe('histórico de atendimentos por veículo', () => {
    it('abre a partir da página Veículos e apresenta a identidade, os totais e o atendimento original', async () => {
        await render('/veiculos')
        await follow('a[aria-label="Histórico de atendimentos de HFS4456"]', history)
        expect(wrapper.get('.vehicle-identity').text()).toContain('Modelo não informado')
        expect(wrapper.get('.vehicle-identity').text()).toContain('Volkswagen')
        expect(wrapper.get('.vehicle-identity').text()).toContain('Linha leve')
        expect(wrapper.findAll('.history-counts dd').map((row) => row.text())).toEqual([
            '3',
            '2',
            '1',
        ])
        const row = wrapper.get('tbody tr').text()
        for (const phrase of [
            '#0010',
            '20/11/2026',
            '21/11/2026',
            'Consultas',
            'Ana',
            'Oficina',
            'Identificação',
            'Placa',
            'Modelo',
            ticket.description,
            ticket.resolution,
            'Encerrado',
        ]) {
            expect(row).toContain(phrase)
        }
        await follow('a[aria-label="Ver detalhes do atendimento 10"]', '/atendimentos/10')
        expect(wrapper.get('.vehicle-identity').text()).toContain('HFS4456')
        expect(wrapper.get('.back-link').attributes('href')).toBe(history)
    })

    it('combina filtros, mantém totais, pagina e restaura o contexto ao voltar', async () => {
        await render(history + '?per_page=1')
        await choose('system_id', system.name)
        await choose('problem_category_id', category.name)
        await choose('problem_subcategory_id', subcategories[1].name)
        await wrapper.get('#filter-created_from').setValue('2026-11-17')
        await wrapper.get('#filter-created_to').setValue('2026-11-20')
        await wrapper.get('form[aria-label="Filtros do histórico"]').trigger('submit')
        await flushPromises()
        expect(get).toHaveBeenLastCalledWith(
            historyApi,
            expect.objectContaining({
                system_id: '2',
                problem_category_id: '3',
                problem_subcategory_id: '6',
                created_from: '2026-11-17',
                created_to: '2026-11-20',
                per_page: '1',
                page: '1',
            }),
            expect.any(AbortSignal),
        )
        expect(wrapper.findAll('.history-counts dd').map((row) => row.text())).toEqual([
            '3',
            '2',
            '1',
        ])
        await button(wrapper, 'Próxima').trigger('click')
        await flushPromises()
        expect(get.mock.lastCall[1].page).toBe('2')
        const currentUrl = router.currentRoute.value.fullPath
        expect(currentUrl).not.toContain('status=')
        await follow('a[aria-label="Ver detalhes do atendimento 10"]', '/atendimentos/10')
        expect(router.currentRoute.value.query.retorno).toBe(currentUrl)
        expect(wrapper.get('.section-bottom-link[href^="/veiculos/"]').attributes('href')).toBe(
            currentUrl,
        )
        await follow('.back-link', history)
        expect(router.currentRoute.value.fullPath).toBe(currentUrl)

        await wrapper.get('#filter-status').setValue('open')
        await wrapper.get('form[aria-label="Filtros do histórico"]').trigger('submit')
        await flushPromises()
        expect(wrapper.text()).toContain('Nenhum atendimento encontrado')
        expect(wrapper.findAll('.history-counts dd').map((row) => row.text())).toEqual([
            '3',
            '2',
            '1',
        ])
        await button(wrapper, 'Limpar').trigger('click')
        await flushPromises()
        expect(get.mock.lastCall[1]).toEqual({ page: '1', per_page: '1' })
    })

    it('retorna à última página válida quando os resultados da consulta diminuem', async () => {
        get.mockResolvedValueOnce(
            result([], {
                vehicle,
                counts,
                total: 2,
                current_page: 4,
                last_page: 2,
                from: null,
                to: null,
            }),
        )
        await render(history + '?status=waiting&per_page=1&page=4')
        await vi.waitFor(() => expect(router.currentRoute.value.query.page).toBe('2'))
        await flushPromises()
        expect(get.mock.lastCall[1]).toEqual({ status: 'waiting', per_page: '1', page: '2' })
        expect(wrapper.get('tbody tr').text()).toContain(ticket.title)
    })

    it('diferencia veículo inexistente, histórico vazio e falhas da consulta', async () => {
        await render('/veiculos/99/historico')
        expect(wrapper.text()).toContain('Veículo não encontrado.')
        get.mockResolvedValueOnce(
            result([], { vehicle, counts: { total: 0, not_closed: 0, closed: 0 } }),
        )
        await router.push(history)
        await flushPromises()
        expect(wrapper.text()).toContain('Nenhum atendimento vinculado')
        get.mockRejectedValueOnce(new ApiError(0))
        await wrapper.get('[aria-label="Atualizar histórico"]').trigger('click')
        await flushPromises()
        expect(wrapper.text()).toContain('Não foi possível conectar')
        get.mockRejectedValueOnce(
            new ApiError(422, { errors: { created_to: ['Confira o período informado.'] } }),
        )
        await button(wrapper, 'Tentar novamente').trigger('click')
        await flushPromises()
        expect(wrapper.text()).toContain('Confira o período informado.')
    })
})

describe('filtros veiculares na listagem geral de atendimentos', () => {
    it('mostra o resumo apenas em linhas vinculadas e destaca filtros ativos mesmo recolhidos', async () => {
        await render('/atendimentos?status=closed')
        expect(wrapper.findAll('.ticket-vehicle-context')).toHaveLength(1)
        expect(wrapper.get('.ticket-vehicle-context').text()).toContain('HFS4456')
        expect(wrapper.get('.ticket-vehicle-context').text()).toContain('Volkswagen')
        expect(wrapper.get('details.vehicle-filters').element.open).toBe(false)
        await router.replace({ query: { status: 'closed', vehicle_plate: 'hfs-44' } })
        await flushPromises()
        expect(wrapper.get('details.vehicle-filters').element.open).toBe(false)
        expect(wrapper.get('.vehicle-filters summary').text()).toContain('1 ativo')
    })

    it('combina filtros, mantém os normais ao limpar e omite campos contraditórios', async () => {
        await render('/atendimentos?status=closed&q=consulta&per_page=15')
        const disclosure = wrapper.get('details.vehicle-filters')
        disclosure.element.open = true
        const form = wrapper.get('form[aria-label="Filtros veiculares"]')
        await form.get('#filter-vehicle_presence').setValue('with')
        await form.get('#filter-vehicle_plate').setValue('hfs 44')
        await choose('vehicle_brand_id', brand.name)
        await form.get('#filter-vehicle_model').setValue('Trend')
        await form.get('#filter-vehicle_line').setValue('light')
        await form.trigger('submit')
        await flushPromises()
        expect(get.mock.lastCall[1]).toEqual({
            status: 'closed',
            q: 'consulta',
            vehicle_presence: 'with',
            vehicle_plate: 'hfs 44',
            vehicle_brand_id: '7',
            vehicle_model: 'Trend',
            vehicle_line: 'light',
            page: '1',
            per_page: '15',
        })
        await form.get('#filter-vehicle_line').setValue('heavy')
        await form.trigger('submit')
        await flushPromises()
        expect(get.mock.lastCall[1].vehicle_line).toBe('heavy')
        await form.get('#filter-vehicle_presence').setValue('without')
        for (const name of ['vehicle_plate', 'vehicle_brand_id', 'vehicle_model', 'vehicle_line']) {
            expect(form.get('#filter-' + name).element.disabled).toBe(true)
        }
        await form.trigger('submit')
        await flushPromises()
        expect(get.mock.lastCall[1]).toEqual({
            status: 'closed',
            q: 'consulta',
            vehicle_presence: 'without',
            page: '1',
            per_page: '15',
        })
        expect(router.currentRoute.value.fullPath).not.toContain('vehicle_plate=')
        expect(wrapper.find('.ticket-vehicle-context').exists()).toBe(false)

        await button(form, 'Limpar filtros veiculares').trigger('click')
        await flushPromises()
        expect(get.mock.lastCall[1]).toEqual({
            status: 'closed',
            q: 'consulta',
            page: '1',
            per_page: '15',
        })
    })

    it('orienta a correção de um link salvo com filtros incompatíveis', async () => {
        const message = 'Remova os filtros específicos para buscar atendimentos sem veículo.'
        get.mockRejectedValueOnce(
            new ApiError(422, { errors: { vehicle_plate: [message], vehicle_line: [message] } }),
        )
        await render(
            '/atendimentos?status=closed&vehicle_presence=without&vehicle_plate=HFS&vehicle_line=light',
        )
        expect(wrapper.get('[role="alert"]').text().split(message)).toHaveLength(2)
        const form = wrapper.get('form[aria-label="Filtros veiculares"]')
        await form.trigger('submit')
        await flushPromises()
        expect(router.currentRoute.value.query).toEqual({
            status: 'closed',
            vehicle_presence: 'without',
            page: '1',
        })
        expect(wrapper.get('tbody').text()).toContain(unlinked.title)
    })

    it('vincula os detalhes ao histórico correto e trata atendimento sem veículo', async () => {
        await render('/atendimentos/11')
        expect(wrapper.find('.vehicle-identity').exists()).toBe(false)
        await router.push('/atendimentos/10')
        await flushPromises()
        expect(wrapper.get('a[href="/veiculos/3/historico"]').text()).toContain(
            'Histórico de atendimentos',
        )
        expect(wrapper.get('.vehicle-identity').text()).toContain('Modelo não informado')
    })
})
