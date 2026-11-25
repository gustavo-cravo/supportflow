import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { api, ApiError } from '../services/api'
import DashboardView from '../views/DashboardView.vue'
import MetricsDashboard from '../components/metrics/MetricsDashboard.vue'
import MetricsFilters from '../components/metrics/MetricsFilters.vue'
import MetricsMonthlyChart from '../components/metrics/MetricsMonthlyChart.vue'
import MetricsSystemsChart from '../components/metrics/MetricsSystemsChart.vue'
import MetricsTicketsModal from '../components/metrics/MetricsTicketsModal.vue'
import MetricsExportModal from '../components/metrics/MetricsExportModal.vue'
import * as downloads from '../utils/download'
import { useNotifications } from '../composables/useNotifications'

const base = { period: 'last_6_months', status: 'all', classification: 'all' }
const systems = [
    { id: 1, name: 'Indicar', is_active: true },
    { id: 2, name: 'Legado', is_active: false },
    { id: 3, name: '.way', is_active: true },
]
const association = (id, association_active = true) => ({ id, association_active })
const categories = [
    {
        id: 5,
        name: 'Falha de acesso',
        is_active: true,
        system_associations: [association(1), association(2)],
    },
    { id: 6, name: 'Histórica', is_active: false, system_associations: [association(2, false)] },
    { id: 7, name: 'Placa', is_active: true, system_associations: [association(3)] },
]
const subcategories = [
    {
        id: 8,
        name: 'Credenciais',
        problem_category_id: 5,
        is_active: true,
        system_associations: [association(1)],
    },
    {
        id: 9,
        name: 'Sessão expirada',
        problem_category_id: 5,
        is_active: false,
        system_associations: [association(2, false)],
    },
    {
        id: 10,
        name: 'Histórica filha',
        problem_category_id: 6,
        is_active: false,
        system_associations: [association(2, false)],
    },
]
const response = (data, extra = {}) => ({
    data,
    meta: {
        current_page: 1,
        last_page: 1,
        total: Array.isArray(data) ? data.length : 1,
        filters: { ...base, date_from: '2026-06-01', date_to: '2026-11-23' },
        ...extra,
    },
})
function metricData() {
    return {
        summary: { total: 4, closed: 1, not_closed: 3, systems_count: 2, unclassified: 1 },
        systems: [
            {
                system_id: 1,
                system_name: 'Indicar',
                is_active: true,
                total: 3,
                closed: 1,
                not_closed: 2,
            },
            {
                system_id: 2,
                system_name: 'Legado',
                is_active: false,
                total: 1,
                closed: 0,
                not_closed: 1,
            },
        ],
        categories: [
            {
                problem_category_id: 5,
                category_name: 'Falha de acesso',
                is_active: false,
                total: 3,
                percentage: 75,
            },
            {
                problem_category_id: null,
                category_name: 'Sem classificação',
                is_active: null,
                total: 1,
                percentage: 25,
            },
        ],
        subcategories: [
            {
                problem_category_id: 5,
                problem_subcategory_id: 8,
                subcategory_name: 'Credenciais',
                is_active: true,
                total: 2,
                category_total: 3,
                percentage: 66.67,
            },
            {
                problem_category_id: 5,
                problem_subcategory_id: null,
                subcategory_name: 'Sem subcategoria',
                is_active: null,
                total: 1,
                category_total: 3,
                percentage: 33.33,
            },
            {
                problem_category_id: null,
                problem_subcategory_id: null,
                subcategory_name: 'Sem classificação',
                is_active: null,
                total: 1,
                category_total: 1,
                percentage: 100,
            },
        ],
        monthly: [
            { month: '2026-07', total: 2 },
            { month: '2026-08', total: 0 },
            { month: '2026-09', total: 2 },
        ],
    }
}
let wrapper, get, data, intercept, router, post, patch
const metricsCalls = (endpoint = 'summary') =>
    get.mock.calls.filter((call) => call[0] === 'metrics/' + endpoint)
beforeEach(() => {
    data = metricData()
    intercept = null
    get = vi.spyOn(api, 'get').mockImplementation(async (endpoint, params = {}, signal) => {
        const override = intercept?.(endpoint, params, signal)
        if (override !== undefined) return override
        if (endpoint === 'dashboard/summary')
            return response({
                counts: {
                    open: 2,
                    in_progress: 1,
                    waiting: 1,
                    overdue_tasks: 0,
                    today_reminders: 1,
                },
                recent_tickets: [
                    {
                        id: 17,
                        title: 'Atendimento recente',
                        system: systems[0],
                        contact: { name: 'Ana' },
                    },
                ],
                today_reminders: [{ id: 1, title: 'Lembrete de hoje' }],
                overdue_tasks: [],
                overdue_reminders: [],
                upcoming_reminders: [],
            })
        if (endpoint === 'systems') return response(systems)
        if (endpoint === 'problem-categories') return response(categories)
        if (endpoint === 'problem-subcategories') return response(subcategories)
        if (endpoint === 'metrics/tickets')
            return response(
                [
                    {
                        id: params.page === 2 ? 42 : 17,
                        title: params.page === 2 ? 'Segunda página' : 'Erro de acesso',
                        system: systems[0],
                        status: 'closed',
                        created_at: '2026-11-20T12:00:00-03:00',
                    },
                ],
                {
                    current_page: params.page,
                    last_page: 2,
                    total: 16,
                    from: params.page === 2 ? 16 : 1,
                    to: params.page === 2 ? 16 : 15,
                },
            )
        if (endpoint.startsWith('metrics/')) return response(data[endpoint.split('/')[1]])
        throw new Error('Unexpected endpoint ' + endpoint)
    })
    post = vi.spyOn(api, 'post')
    patch = vi.spyOn(api, 'patch')
})
afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.restoreAllMocks()
    const { notices, dismiss } = useNotifications()
    notices.value.forEach((notice) => dismiss(notice.id))
})
async function render(component = MetricsDashboard) {
    router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', component: DashboardView },
            { path: '/atendimentos/:id', component: { template: '<div>Detalhes</div>' } },
            {
                path: '/veiculos/:id/historico',
                name: 'vehicle-history',
                component: { template: '<div>Histórico</div>' },
            },
            { path: '/:pathMatch(.*)*', component: { template: '<div>Destino</div>' } },
        ],
    })
    await router.push('/')
    wrapper = mount(component, {
        attachTo: document.body,
        global: { plugins: [router], stubs: { Teleport: true } },
    })
    await flushPromises()
    return wrapper
}
const apply = async () => {
    await wrapper.get('form[aria-label="Filtros estatísticos"]').trigger('submit')
    await flushPromises()
}
const click = async (name) => {
    await wrapper.get(`[aria-label="${name}"]`).trigger('click')
    await flushPromises()
}

describe('Vehicle statistics and exports', () => {
    const brand = { brand_id: 12, brand_name: 'Marca Alfa', total: 2, vehicles: 1, percentage: 100 }
    const model = {
        ...brand,
        model_key: 'group-key',
        model_description: 'Série LX',
        vehicle_line: 'light',
    }
    const car = { ...model, vehicle_id: 19, plate: 'HFS4456' }
    const subcategory = {
        ...model,
        system_id: 1,
        system_name: 'Indicar',
        problem_category_id: 5,
        category_name: 'Falha de acesso',
        problem_subcategory_id: 8,
        subcategory_name: 'Credenciais',
        level: 'subcategory',
        total: 1,
    }
    const stats = {
        summary: {
            with_vehicle: 2,
            without_vehicle: 1,
            identification_percentage: 66.67,
            distinct_vehicles: 1,
            distinct_models: 1,
            vehicles_without_model: 0,
            light_tickets: 2,
            heavy_tickets: 0,
        },
        recurrence: { repeat_vehicles: 1, repeat_tickets: 2, max_tickets_per_vehicle: 2 },
        lines: [
            { vehicle_line: 'light', total: 2, vehicles: 1, percentage: 100 },
            { vehicle_line: 'heavy', total: 0, vehicles: 0, percentage: 0 },
        ],
        brands: [brand],
        models: [model],
        vehicles: [car],
        problems: { brand: [subcategory], model: [subcategory], line: [subcategory] },
    }
    const toggle = async (selector, open = true) => {
        const details = wrapper.get(selector)
        details.element.open = open
        await details.trigger('toggle')
        await flushPromises()
    }
    const openReport = async () => {
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Exportar Excel')
            .trigger('click')
        await flushPromises()
        return wrapper.getComponent(MetricsExportModal)
    }

    it('combines the draft filters and clears only vehicle criteria while disabling contradictory inputs', async () => {
        data.vehicles = stats
        intercept = (endpoint) =>
            endpoint === 'vehicle-brands' ? response([{ id: 12, name: 'Marca Alfa' }]) : undefined
        await render()
        expect(wrapper.get('.metrics-vehicle-filters').element.open).toBe(false)
        await toggle('.metrics-vehicle-filters')
        await wrapper.get('#metrics-status').setValue('closed')
        await wrapper.get('#filter-vehicle_plate').setValue('hfs-44')
        await wrapper.get('#filter-vehicle_model').setValue('Série')
        await wrapper.get('#filter-vehicle_line').setValue('light')
        await wrapper.get('#filter-vehicle_brand_id').trigger('click')
        await flushPromises()
        await wrapper.get('[role="option"] button').trigger('click')
        await apply()
        expect(metricsCalls().at(-1)[1]).toMatchObject({
            status: 'closed',
            vehicle_plate: 'hfs-44',
            vehicle_model: 'Série',
            vehicle_line: 'light',
            vehicle_brand_id: 12,
        })
        await toggle('.metrics-vehicle-filters', false)
        expect(wrapper.get('.metrics-vehicle-filters summary').text()).toContain('4 ativos')
        await toggle('.metrics-vehicle-filters')
        await wrapper.get('#filter-vehicle_presence').setValue('without')
        expect(wrapper.get('#filter-vehicle_plate').element.disabled).toBe(true)
        expect(wrapper.get('#filter-vehicle_brand_id').element.disabled).toBe(true)
        await apply()
        expect(metricsCalls().at(-1)[1]).toEqual({
            ...base,
            status: 'closed',
            vehicle_presence: 'without',
        })
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Limpar filtros veiculares')
            .trigger('click')
        expect(metricsCalls().at(-1)[1]).toEqual({ ...base, status: 'closed' })
    })

    it('loads vehicle statistics on expansion and uses existing paginated details for exact groups', async () => {
        data.vehicles = stats
        await render()
        expect(metricsCalls('vehicles')).toHaveLength(0)
        await wrapper.get('#metrics-status').setValue('closed')
        await apply()
        const selector = '.metrics-vehicle-section'
        expect(wrapper.get(selector).element.open).toBe(false)
        await toggle(selector)
        expect(metricsCalls('vehicles').at(-1)[1]).toMatchObject({ status: 'closed' })
        expect(wrapper.get(selector).text()).toContain(
            '66,67%',
            'Atendimentos sem veículo',
            'Recorrência por placa',
        )
        expect(wrapper.get('a[href="/veiculos/19/historico"]').text()).toBe('Histórico veicular')
        await toggle(selector, false)
        await toggle(selector)
        expect(metricsCalls('vehicles')).toHaveLength(1)
        await wrapper.get('#metrics-vehicle-dimension').setValue('model')
        const problem = wrapper.get('section[aria-label="Problemas recorrentes"]')
        expect(problem.text()).toContain(
            'Cada vínculo com uma subcategoria conta uma ocorrência',
            'Credenciais',
        )
        await problem.get('tbody button').trigger('click')
        await flushPromises()
        expect(metricsCalls('tickets').at(-1)[1]).toMatchObject({
            status: 'closed',
            system_id: 1,
            vehicle_brand_id: 12,
            group_vehicle_model: 'group-key',
            problem_category_id: 5,
            problem_subcategory_id: 8,
            page: 1,
            per_page: 15,
        })
        expect(wrapper.getComponent(MetricsTicketsModal).text()).toContain('Erro de acesso')
    })

    it('shows empty and retry states without blocking the traditional metrics', async () => {
        data.vehicles = {
            ...stats,
            summary: { ...stats.summary, with_vehicle: 0, without_vehicle: 3 },
        }
        intercept = (endpoint) =>
            endpoint === 'metrics/vehicles' ? Promise.reject(new ApiError(0)) : undefined
        await render()
        await toggle('.metrics-vehicle-section')
        expect(wrapper.get('.metrics-vehicle-section [role="alert"]').text()).toContain(
            'Não foi possível',
        )
        expect(wrapper.text()).toContain('Categorias mais recorrentes')
        intercept = null
        await wrapper.get('.metrics-vehicle-section .state-panel button').trigger('click')
        await flushPromises()
        expect(wrapper.get('.metrics-vehicle-section').text()).toContain(
            'Nenhum atendimento com veículo',
        )
    })

    it('exports Excel with the optional vehicle flag and masks the plate in the filter summary', async () => {
        const download = vi
            .spyOn(api, 'download')
            .mockResolvedValue({ filename: 'relatorio.xlsx', blob: new Blob(['bytes']) })
        vi.spyOn(downloads, 'saveDownload').mockImplementation(() => {})
        await render()
        await toggle('.metrics-vehicle-filters')
        await wrapper.get('#filter-vehicle_plate').setValue('HFS4456')
        await apply()
        let modal = await openReport()
        expect(modal.text()).toContain('Filtro de placa aplicado')
        expect(modal.text()).not.toContain('HFS4456')
        expect(modal.get('[type="checkbox"]').element.checked).toBe(false)
        await modal.get('form').trigger('submit')
        await flushPromises()
        expect(download.mock.calls[0][1]).toEqual({
            ...base,
            format: 'xlsx',
            vehicle_plate: 'HFS4456',
        })
        modal = await openReport()
        await modal.get('[type="checkbox"]').setValue(true)
        await modal.get('form').trigger('submit')
        expect(download.mock.calls[1][1]).toEqual({
            ...base,
            format: 'xlsx',
            vehicle_plate: 'HFS4456',
            include_vehicle_statistics: 1,
        })
        expect(metricsCalls('vehicles')).toHaveLength(0)
    })
})

describe('Dashboard tabs and preservation', () => {
    it('starts in operational overview without requesting any metrics or catalog', async () => {
        await render(DashboardView)
        expect(wrapper.get('#dashboard-tab-overview').attributes('aria-selected')).toBe('true')
        expect(wrapper.text()).toContain('Seu dia, sob controle.')
        for (const title of [
            'Atendimentos recentes',
            'Lembretes de hoje',
            'Pendências em atenção',
            'Lembretes atrasados',
            'Próximos retornos',
            'Atendimento recente',
            'Nova pendência',
            'Novo lembrete',
        ])
            expect(wrapper.text()).toContain(title)
        expect(wrapper.findAll('.metric-card')).toHaveLength(5)
        expect(wrapper.get('a[href="/atendimentos?status=open"]').exists()).toBe(true)
        expect(get).toHaveBeenCalledTimes(1)
    })
    it('loads metrics lazily and preserves filters, overview and responses across tab switches', async () => {
        await render(DashboardView)
        await wrapper.get('#dashboard-tab-metrics').trigger('click')
        await vi.waitFor(() => expect(wrapper.findComponent(MetricsDashboard).exists()).toBe(true))
        await flushPromises()
        await wrapper.get('#metrics-status').setValue('waiting')
        await apply()
        const calls = get.mock.calls.length
        await wrapper.get('#dashboard-tab-overview').trigger('click')
        expect(wrapper.get('#dashboard-panel-overview').isVisible()).toBe(true)
        await wrapper.get('#dashboard-tab-metrics').trigger('click')
        expect(wrapper.get('#metrics-status').element.value).toBe('waiting')
        expect(get).toHaveBeenCalledTimes(calls)
        expect(get.mock.calls.filter((call) => call[0] === 'dashboard/summary')).toHaveLength(1)
    })
    it('supports arrow, Home and End keys with tab focus and selected state', async () => {
        await render(DashboardView)
        await wrapper.get('#dashboard-tab-overview').trigger('keydown', { key: 'ArrowRight' })
        expect(document.activeElement.id).toBe('dashboard-tab-metrics')
        expect(wrapper.get('#dashboard-tab-metrics').attributes('tabindex')).toBe('0')
        await wrapper.get('#dashboard-tab-metrics').trigger('keydown', { key: 'Home' })
        expect(document.activeElement.id).toBe('dashboard-tab-overview')
        await wrapper.get('#dashboard-tab-overview').trigger('keydown', { key: 'End' })
        expect(wrapper.get('#dashboard-tab-metrics').attributes('aria-selected')).toBe('true')
        await flushPromises()
    })
})

describe('metrics results and filters', () => {
    it('requests each aggregation once with identical default parameters and displays API indicators', async () => {
        await render()
        for (const endpoint of ['summary', 'systems', 'categories', 'subcategories', 'monthly'])
            expect(metricsCalls(endpoint)).toHaveLength(1)
        for (const call of metricsCalls()) expect(call[1]).toEqual(base)
        expect(wrapper.findAll('.metrics-indicators strong').map((node) => node.text())).toEqual([
            '4',
            '3',
            '1',
            '2',
        ])
        expect(wrapper.text()).toContain('01/06/2026 a 23/11/2026')
        expect(wrapper.text()).toContain('75%')
        expect(wrapper.text()).toContain('66,67%')
        expect(wrapper.text()).toContain('Inativa · Histórico preservado')
    })
    it.each(['this_month', 'last_30_days', 'last_3_months', 'last_6_months', 'this_year', 'all'])(
        'applies period %s without sending custom dates',
        async (period) => {
            await render()
            await wrapper.get('#metrics-period').setValue(period)
            await apply()
            expect(metricsCalls().at(-1)[1]).toEqual({ ...base, period })
        },
    )
    it('combines actual period, system, status, category and subcategory parameters for all aggregations', async () => {
        await render()
        await wrapper.get('#metrics-system').setValue('1')
        await wrapper.get('#metrics-category').setValue('5')
        await wrapper.get('#metrics-subcategory').setValue('8')
        await wrapper.get('#metrics-status').setValue('not_closed')
        await wrapper.get('#metrics-period').setValue('custom')
        await wrapper.get('#metrics-date-from').setValue('2026-11-01')
        await wrapper.get('#metrics-date-to').setValue('2026-11-30')
        await apply()
        for (const endpoint of ['summary', 'systems', 'categories', 'subcategories', 'monthly'])
            expect(metricsCalls(endpoint).at(-1)[1]).toEqual({
                period: 'custom',
                date_from: '2026-11-01',
                date_to: '2026-11-30',
                status: 'not_closed',
                classification: 'all',
                system_id: 1,
                problem_category_id: 5,
                problem_subcategory_id: 8,
            })
    })
    it('does not query while changing drafts and removes date parameters when leaving custom period', async () => {
        await render()
        await wrapper.get('#metrics-period').setValue('custom')
        await wrapper.get('#metrics-date-from').setValue('2026-11-01')
        await wrapper.get('#metrics-date-to').setValue('2026-11-30')
        expect(metricsCalls()).toHaveLength(1)
        await apply()
        await wrapper.get('#metrics-period').setValue('all')
        await apply()
        expect(metricsCalls().at(-1)[1]).toEqual({ ...base, period: 'all' })
    })
    it.each([
        ['', '2026-11-10'],
        ['2026-11-30', '2026-11-01'],
    ])('rejects missing or reversed custom dates (%s, %s)', async (from, to) => {
        await render()
        await wrapper.get('#metrics-period').setValue('custom')
        await wrapper.get('#metrics-date-from').setValue(from)
        await wrapper.get('#metrics-date-to').setValue(to)
        await apply()
        expect(metricsCalls()).toHaveLength(1)
        expect(wrapper.get('#metrics-date-error').text()).toContain('Informe as duas datas')
    })
    it('resets all filters and immediately applies the six month default', async () => {
        await render()
        await wrapper.get('#metrics-system').setValue('1')
        await wrapper.get('#metrics-category').setValue('5')
        await wrapper.get('#metrics-subcategory').setValue('8')
        await wrapper.get('#metrics-status').setValue('closed')
        await apply()
        await wrapper
            .findAll('button')
            .find((node) => node.text() === 'Redefinir filtros')
            .trigger('click')
        await flushPromises()
        expect(metricsCalls().at(-1)[1]).toEqual(base)
        expect(wrapper.get('#metrics-category').element.value).toBe('')
        expect(wrapper.get('#metrics-subcategory').element.disabled).toBe(true)
    })
    it('keeps inactive systems and disabled historical associations available while excluding unrelated options', async () => {
        await render()
        await wrapper.get('#metrics-system').setValue('2')
        const options = wrapper.get('#metrics-category').text()
        expect(options).toContain('Histórica (inativa)')
        expect(options).not.toContain('Placa')
        expect(wrapper.get('#metrics-system').text()).toContain('Legado (inativo)')
        await wrapper.get('#metrics-category').setValue('5')
        expect(wrapper.get('#metrics-subcategory').text()).toContain('Sessão expirada (inativa)')
        expect(wrapper.get('#metrics-subcategory').text()).not.toContain('Credenciais')
        await wrapper.get('#metrics-subcategory').setValue('9')
        await apply()
        expect(metricsCalls().at(-1)[1]).toMatchObject({
            system_id: 2,
            problem_category_id: 5,
            problem_subcategory_id: 9,
        })
    })
    it('clears an incompatible category after changing system and announces the reset', async () => {
        await render()
        await wrapper.get('#metrics-category').setValue('7')
        await wrapper.get('#metrics-system').setValue('1')
        expect(wrapper.get('#metrics-category').element.value).toBe('')
        expect(wrapper.text()).toContain('não possui vínculo com o sistema')
        await apply()
        expect(metricsCalls().at(-1)[1]).not.toHaveProperty('problem_category_id')
    })
    it('clears only an incompatible subcategory and clears a previous subcategory when changing category', async () => {
        await render()
        await wrapper.get('#metrics-category').setValue('5')
        await wrapper.get('#metrics-subcategory').setValue('8')
        await wrapper.get('#metrics-system').setValue('2')
        expect(wrapper.get('#metrics-category').element.value).toBe('5')
        expect(wrapper.get('#metrics-subcategory').element.value).toBe('')
        await wrapper.get('#metrics-subcategory').setValue('9')
        await wrapper.get('#metrics-category').setValue('6')
        expect(wrapper.get('#metrics-subcategory').element.value).toBe('')
    })
    it('supports unclassified and missing-subcategory buckets without invalid parameter combinations', async () => {
        await render()
        await wrapper.get('#metrics-category').setValue('unclassified')
        expect(wrapper.get('#metrics-subcategory').element.disabled).toBe(true)
        await apply()
        expect(metricsCalls().at(-1)[1]).toEqual({ ...base, classification: 'unclassified' })
        await wrapper.get('#metrics-category').setValue('5')
        await wrapper.get('#metrics-subcategory').setValue('without_subcategory')
        await apply()
        expect(metricsCalls().at(-1)[1]).toEqual({
            ...base,
            problem_category_id: 5,
            classification: 'without_subcategory',
        })
    })
    it('loads all catalog pages once, including options beyond the first hundred records', async () => {
        intercept = (endpoint, params) =>
            endpoint === 'problem-categories'
                ? response(
                      params.page === 1
                          ? categories
                          : [
                                {
                                    id: 99,
                                    name: 'Última página',
                                    is_active: true,
                                    system_associations: [],
                                },
                            ],
                      { current_page: params.page, last_page: 2 },
                  )
                : undefined
        await render()
        expect(wrapper.get('#metrics-category').text()).toContain('Última página')
        expect(
            get.mock.calls
                .filter((call) => call[0] === 'problem-categories')
                .map((call) => call[1]),
        ).toEqual([
            { page: 1, per_page: 100 },
            { page: 2, per_page: 100 },
        ])
        await apply()
        expect(get.mock.calls.filter((call) => call[0] === 'problem-categories')).toHaveLength(2)
    })
})

describe('charts, ranking and drilldown', () => {
    it('presents bars in API ranking order and real chronological points including zero months', async () => {
        await render()
        expect(wrapper.findAll('.metrics-bars li').map((node) => node.text())).toEqual([
            'Indicar 3',
            'Legado Inativo1',
        ])
        expect(wrapper.findAll('.metrics-chart-point')).toHaveLength(3)
        expect(wrapper.findAll('.metrics-point-label').map((node) => node.text())).toEqual([
            '2',
            '0',
            '2',
        ])
        expect(wrapper.get('svg[role="img"]').attributes('aria-labelledby')).toContain(
            'metrics-monthly-svg-title',
        )
        const table = wrapper.get('.metrics-chart-data')
        expect(table.text()).toContain('ago. de 2026')
        expect(
            wrapper.findAll('.metrics-ranking tbody tr').map((node) => node.find('th').text()),
        ).toEqual(['Falha de acessoInativa · Histórico preservado', 'Sem classificação'])
    })
    it('displays a single system and month without inventing comparisons or extra points', async () => {
        data.systems = data.systems.slice(0, 1)
        data.monthly = [{ month: '2026-11', total: 4 }]
        await render()
        expect(wrapper.findAll('.metrics-bars li')).toHaveLength(1)
        expect(wrapper.findAll('.metrics-chart-point')).toHaveLength(1)
        expect(wrapper.get('.metrics-point-label').text()).toBe('4')
    })
    it('selects a category without extra API queries and displays its API percentages', async () => {
        await render()
        const calls = get.mock.calls.length
        await click('Detalhar Sem classificação')
        expect(wrapper.get('#metrics-subcategories').text()).toContain('100%')
        expect(wrapper.get('#metrics-subcategories').text()).not.toContain('Credenciais')
        await click('Detalhar Falha de acesso')
        expect(wrapper.get('#metrics-subcategories').text()).toContain('66,67%')
        expect(wrapper.get('#metrics-subcategories').text()).toContain('Sem subcategoria')
        expect(get).toHaveBeenCalledTimes(calls)
    })
    it('opens category tickets with applied combined filters and paginates with the same selection', async () => {
        await render()
        await wrapper.get('#metrics-system').setValue('1')
        await wrapper.get('#metrics-status').setValue('closed')
        await apply()
        await click('Ver atendimentos de Falha de acesso')
        expect(metricsCalls('tickets').at(-1)[1]).toEqual({
            ...base,
            system_id: 1,
            status: 'closed',
            problem_category_id: 5,
            page: 1,
            per_page: 15,
        })
        const modal = wrapper.getComponent(MetricsTicketsModal)
        expect(modal.text()).toContain('Erro de acesso')
        expect(modal.findAll('button').some((node) => node.text().includes('Editar'))).toBe(false)
        const next = modal.findAll('button').find((node) => node.text() === 'Próxima')
        next.element.focus()
        await next.trigger('click')
        await flushPromises()
        expect(metricsCalls('tickets').at(-1)[1]).toMatchObject({
            system_id: 1,
            status: 'closed',
            problem_category_id: 5,
            page: 2,
        })
        expect(modal.text()).toContain('Segunda página')
        expect(document.activeElement).toBe(modal.get('.metrics-modal-context').element)
        await modal.get('a[href="/atendimentos/42"]').trigger('click')
        await flushPromises()
        expect(router.currentRoute.value.path).toBe('/atendimentos/42')
        expect(post).not.toHaveBeenCalled()
        expect(patch).not.toHaveBeenCalled()
    })
    it.each([
        [
            'Credenciais',
            { problem_category_id: 5, problem_subcategory_id: 8, classification: 'all' },
        ],
        ['Sem subcategoria', { problem_category_id: 5, classification: 'without_subcategory' }],
        ['Sem classificação', { classification: 'unclassified' }],
    ])(
        'opens exact %s bucket, then restores analysis and filter values after closing',
        async (name, expected) => {
            await render()
            await wrapper.get('#metrics-status').setValue('waiting')
            await apply()
            if (name === 'Sem classificação') await click('Detalhar Sem classificação')
            // The unclassified bucket also has a category action; select its subcategory action explicitly.
            const action = wrapper
                .get('#metrics-subcategories')
                .get(`[aria-label="Ver atendimentos de ${name}"]`)
            await action.trigger('click')
            await flushPromises()
            expect(metricsCalls('tickets').at(-1)[1]).toEqual({
                ...base,
                status: 'waiting',
                ...expected,
                page: 1,
                per_page: 15,
            })
            const calls = metricsCalls().length
            await wrapper.get('[aria-label="Fechar janela"]').trigger('click')
            expect(wrapper.findComponent(MetricsTicketsModal).exists()).toBe(false)
            expect(wrapper.get('#metrics-status').element.value).toBe('waiting')
            expect(metricsCalls()).toHaveLength(calls)
        },
    )
    it('preserves a selected subcategory filter when opening its category tickets', async () => {
        await render()
        await wrapper.get('#metrics-category').setValue('5')
        await wrapper.get('#metrics-subcategory').setValue('8')
        await apply()
        await click('Ver atendimentos de Falha de acesso')
        expect(metricsCalls('tickets').at(-1)[1]).toMatchObject({
            problem_category_id: 5,
            problem_subcategory_id: 8,
        })
    })
    it('closes through Escape and backdrop and aborts an in-flight ticket request', async () => {
        await render()
        intercept = (endpoint) =>
            endpoint === 'metrics/tickets' ? new Promise(() => {}) : undefined
        await click('Ver atendimentos de Falha de acesso')
        const signal = metricsCalls('tickets').at(-1)[2]
        await wrapper.get('[role="dialog"]').trigger('keydown', { key: 'Escape' })
        expect(signal.aborted).toBe(true)
        await click('Ver atendimentos de Falha de acesso')
        await wrapper.get('.modal-backdrop').trigger('click')
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    })
})

describe('loading, empty and failure states', () => {
    it('clears old charts while filtering, aborts obsolete requests and ignores late responses', async () => {
        await render()
        const pending = []
        intercept = (endpoint, params) =>
            endpoint.startsWith('metrics/') && params.period === 'this_month'
                ? new Promise((resolve) =>
                      pending.push(() =>
                          resolve(
                              response(
                                  endpoint.endsWith('summary')
                                      ? { ...data.summary, total: 999 }
                                      : [],
                              ),
                          ),
                      ),
                  )
                : undefined
        await wrapper.get('#metrics-period').setValue('this_month')
        await apply()
        expect(wrapper.findComponent(MetricsSystemsChart).exists()).toBe(false)
        expect(wrapper.text()).toContain('Carregando informações')
        const obsolete = metricsCalls().at(-1)[2]
        await wrapper.get('#metrics-period').setValue('all')
        await apply()
        expect(obsolete.aborted).toBe(true)
        pending.forEach((resolve) => resolve())
        await flushPromises()
        expect(wrapper.findAll('.metrics-indicators strong')[0].text()).toBe('4')
        expect(wrapper.text()).not.toContain('999')
    })
    it('avoids duplicate identical requests while one is pending and cancels everything on unmount', async () => {
        intercept = (endpoint) =>
            endpoint.startsWith('metrics/') ? new Promise(() => {}) : undefined
        await render()
        await apply()
        await apply()
        expect(metricsCalls()).toHaveLength(1)
        const signals = get.mock.calls.map((call) => call[2])
        wrapper.unmount()
        wrapper = null
        expect(signals.every((signal) => signal.aborted)).toBe(true)
    })
    it('shows API failure with retry and validation details instead of partial or stale aggregates', async () => {
        intercept = (endpoint) =>
            endpoint === 'metrics/categories'
                ? Promise.reject(
                      new ApiError(422, {
                          errors: { problem_category_id: ['A categoria informada não existe.'] },
                      }),
                  )
                : undefined
        await render()
        expect(wrapper.text()).toContain('A categoria informada não existe.')
        expect(wrapper.findComponent(MetricsSystemsChart).exists()).toBe(false)
        intercept = null
        await wrapper
            .findAll('button')
            .find((node) => node.text().includes('Tentar novamente'))
            .trigger('click')
        await flushPromises()
        expect(wrapper.findComponent(MetricsSystemsChart).exists()).toBe(true)
    })
    it('retries a failed catalog without duplicating aggregation queries', async () => {
        intercept = (endpoint) =>
            endpoint === 'problem-categories' ? Promise.reject(new ApiError(0)) : undefined
        await render()
        expect(wrapper.get('#metrics-system').element.disabled).toBe(true)
        intercept = null
        await wrapper
            .getComponent(MetricsFilters)
            .findAll('button')
            .find((node) => node.text().includes('Tentar novamente'))
            .trigger('click')
        await flushPromises()
        expect(wrapper.get('#metrics-system').element.disabled).toBe(false)
        expect(wrapper.get('#metrics-category').text()).toContain('Falha de acesso')
        expect(metricsCalls()).toHaveLength(1)
    })
    it('displays real zero indicators, empty ranking and an API-provided zero month', async () => {
        data = {
            summary: { total: 0, closed: 0, not_closed: 0, systems_count: 0, unclassified: 0 },
            systems: [],
            categories: [],
            subcategories: [],
            monthly: [{ month: '2026-11', total: 0 }],
        }
        await render()
        expect(wrapper.findAll('.metrics-indicators strong').map((node) => node.text())).toEqual([
            '0',
            '0',
            '0',
            '0',
        ])
        expect(wrapper.text()).toContain('Nenhum atendimento no período')
        expect(wrapper.text()).toContain('Nenhuma categoria com ocorrências')
        expect(wrapper.text()).toContain('Nenhuma subcategoria com ocorrências')
        expect(wrapper.get('.metrics-point-label').text()).toBe('0')
        expect(wrapper.find('.metrics-chart-line').attributes('points')).not.toContain('NaN')
    })
    it('does not invent monthly points when the API returns no months', async () => {
        data.monthly = []
        await render()
        expect(wrapper.getComponent(MetricsMonthlyChart).text()).toContain(
            'Sem evolução no período',
        )
        expect(wrapper.find('svg[role="img"]').exists()).toBe(false)
    })
    it('handles empty related tickets and a list API failure with retry on the same filters', async () => {
        await render()
        intercept = (endpoint) =>
            endpoint === 'metrics/tickets' ? Promise.reject(new ApiError(0)) : undefined
        await click('Ver atendimentos de Falha de acesso')
        const modal = wrapper.getComponent(MetricsTicketsModal)
        expect(modal.text()).toContain('Não foi possível conectar')
        intercept = (endpoint) =>
            endpoint === 'metrics/tickets' ? response([], { total: 0 }) : undefined
        await modal
            .findAll('button')
            .find((node) => node.text().includes('Tentar novamente'))
            .trigger('click')
        await flushPromises()
        expect(modal.text()).toContain('Nenhum atendimento encontrado')
        expect(metricsCalls('tickets').at(-1)[1]).toEqual({
            ...base,
            problem_category_id: 5,
            page: 1,
            per_page: 15,
        })
    })
})

describe('statistical Excel export', () => {
    let download, save
    beforeEach(() => {
        download = vi.spyOn(api, 'download').mockResolvedValue({
            blob: new Blob(['test']),
            filename: 'supportflow_relatorio_2026-11-23.xlsx',
        })
        save = vi.spyOn(downloads, 'saveDownload').mockImplementation(() => {})
    })
    const openExport = async () => {
        await wrapper
            .findAll('button')
            .find((node) => node.text() === 'Exportar Excel')
            .trigger('click')
        await flushPromises()
    }
    const generate = async () => {
        await wrapper.get('#metrics-export-form').trigger('submit')
        await flushPromises()
    }

    it('exports the applied filters and their labels, ignoring unapplied drafts without new aggregate requests', async () => {
        await render()
        await wrapper.get('#metrics-system').setValue('1')
        await wrapper.get('#metrics-category').setValue('5')
        await wrapper.get('#metrics-subcategory').setValue('8')
        await wrapper.get('#metrics-status').setValue('closed')
        await wrapper.get('#metrics-period').setValue('custom')
        await wrapper.get('#metrics-date-from').setValue('2026-11-01')
        await wrapper.get('#metrics-date-to').setValue('2026-11-30')
        await apply()
        await wrapper.get('#metrics-status').setValue('open')
        const requests = get.mock.calls.length
        await openExport()
        const modal = wrapper.getComponent(MetricsExportModal)
        expect(modal.text()).toContain('Indicar')
        expect(modal.text()).toContain('Falha de acesso')
        expect(modal.text()).toContain('Credenciais')
        expect(modal.text()).toContain('Encerrado')
        expect(download).not.toHaveBeenCalled()
        await generate()
        expect(download).toHaveBeenCalledWith(
            'metrics/export',
            {
                period: 'custom',
                status: 'closed',
                classification: 'all',
                system_id: 1,
                problem_category_id: 5,
                problem_subcategory_id: 8,
                date_from: '2026-11-01',
                date_to: '2026-11-30',
                format: 'xlsx',
            },
            expect.any(AbortSignal),
        )
        expect(save).toHaveBeenCalledTimes(1)
        expect(get).toHaveBeenCalledTimes(requests)
        expect(wrapper.findComponent(MetricsExportModal).exists()).toBe(false)
        expect(post).not.toHaveBeenCalled()
        expect(patch).not.toHaveBeenCalled()
    })
    it('shows the resolved applied period and permits an empty Excel report', async () => {
        data.summary = { total: 0, closed: 0, not_closed: 0, systems_count: 0, unclassified: 0 }
        data.systems = []
        data.categories = []
        data.subcategories = []
        data.monthly = []
        await render()
        await openExport()
        expect(wrapper.getComponent(MetricsExportModal).text()).toContain('01/06/2026 a 23/11/2026')
        await generate()
        expect(download.mock.calls[0][1]).toEqual({ ...base, format: 'xlsx' })
    })
    it('disables export while refreshing metrics and does not use a stale result', async () => {
        intercept = (endpoint) =>
            endpoint.startsWith('metrics/') ? new Promise(() => {}) : undefined
        await render()
        const button = wrapper.findAll('button').find((node) => node.text() === 'Exportar Excel')
        expect(button.element.disabled).toBe(true)
        expect(download).not.toHaveBeenCalled()
    })
    it('cancels without exporting or losing filters and returns keyboard focus to the export button', async () => {
        await render()
        await wrapper.get('#metrics-status').setValue('waiting')
        await apply()
        const trigger = wrapper.findAll('button').find((node) => node.text() === 'Exportar Excel')
        trigger.element.focus()
        await openExport()
        await wrapper.get('[role="dialog"]').trigger('keydown', { key: 'Escape' })
        await flushPromises()
        expect(document.activeElement).toBe(trigger.element)
        expect(wrapper.get('#metrics-status').element.value).toBe('waiting')
        expect(download).not.toHaveBeenCalled()
    })
    it('prevents duplicate submissions and reports success only after generation and download initiation', async () => {
        let resolve
        download.mockReturnValue(new Promise((done) => (resolve = done)))
        await render()
        await openExport()
        await generate()
        await generate()
        expect(download).toHaveBeenCalledTimes(1)
        expect(wrapper.get('#metrics-export-form input[type="checkbox"]').element.disabled).toBe(
            true,
        )
        expect(save).not.toHaveBeenCalled()
        expect(useNotifications().notices.value).toHaveLength(0)
        resolve({
            blob: new Blob(['generated']),
            filename: 'supportflow_relatorio_2026-11-23.xlsx',
        })
        await flushPromises()
        expect(save).toHaveBeenCalledTimes(1)
        expect(useNotifications().notices.value[0].message).toContain('download foi iniciado')
    })
    it('cancels a pending download and ignores its late response', async () => {
        let resolve
        download.mockReturnValue(new Promise((done) => (resolve = done)))
        await render()
        await openExport()
        await generate()
        const signal = download.mock.calls[0][2]
        await wrapper
            .getComponent(MetricsExportModal)
            .findAll('button')
            .find((node) => node.text() === 'Cancelar')
            .trigger('click')
        expect(signal.aborted).toBe(true)
        resolve({ blob: new Blob(['late']), filename: 'supportflow_relatorio_2026-11-23.xlsx' })
        await flushPromises()
        expect(save).not.toHaveBeenCalled()
        expect(useNotifications().notices.value).toHaveLength(0)
    })
    it('presents generation errors without paths and permits retry with the same filters', async () => {
        download.mockRejectedValueOnce(new ApiError(500, { message: 'PRIVATE_SERVER_PATH' }))
        await render()
        await openExport()
        await generate()
        expect(wrapper.getComponent(MetricsExportModal).text()).toContain(
            'Não foi possível gerar o relatório',
        )
        expect(wrapper.text()).not.toContain('PRIVATE_SERVER_PATH')
        expect(save).not.toHaveBeenCalled()
        await generate()
        expect(download).toHaveBeenCalledTimes(2)
        expect(download.mock.calls[0][1]).toEqual(download.mock.calls[1][1])
        expect(save).toHaveBeenCalledTimes(1)
    })
    it('shows backend validation errors and remains open without starting a download', async () => {
        download.mockRejectedValue(
            new ApiError(422, { errors: { date_from: ['Informe uma data inicial válida.'] } }),
        )
        await render()
        await openExport()
        await generate()
        expect(wrapper.getComponent(MetricsExportModal).text()).toContain(
            'Informe uma data inicial válida.',
        )
        expect(save).not.toHaveBeenCalled()
    })
    it('exports null classification buckets through the same parameters used by metrics', async () => {
        await render()
        await wrapper.get('#metrics-category').setValue('unclassified')
        await apply()
        await openExport()
        await generate()
        expect(download.mock.calls[0][1]).toEqual({
            ...base,
            classification: 'unclassified',
            format: 'xlsx',
        })
        await wrapper.get('#metrics-category').setValue('5')
        await wrapper.get('#metrics-subcategory').setValue('without_subcategory')
        await apply()
        await openExport()
        await generate()
        expect(download.mock.calls[1][1]).toEqual({
            ...base,
            problem_category_id: 5,
            classification: 'without_subcategory',
            format: 'xlsx',
        })
    })
})
