import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import TicketFormView from '../views/TicketFormView.vue'
import TicketDetailView from '../views/TicketDetailView.vue'
import { useNotifications } from '../composables/useNotifications'
import { routes } from '../router'
import { api, ApiError } from '../services/api'

const body = new DOMWrapper(document.body)
const dialog = () => body.get('.modal-backdrop:not([inert]) [role="dialog"]')
const button = (scope, label) => scope.findAll('button').find((item) => item.text() === label)
const systems = [
    { id: 1, name: 'Sistema de consulta', is_active: true },
    { id: 2, name: 'Sistema de suporte', is_active: true },
]
const contact = { id: 3, name: 'Ana' }
const customer = { id: 4, name: 'Oficina Alfa' }
const category = { id: 5, name: 'Cadastro', is_active: true, systems }
const subcategories = [
    { id: 6, name: 'Identificação', problem_category_id: 5, is_active: true, systems },
    { id: 7, name: 'Descrição', problem_category_id: 5, is_active: true, systems },
]
const volkswagen = { id: 8, name: 'Volkswagen' }
const ford = { id: 9, name: 'Ford' }
const fox = {
    id: 10,
    plate: 'HFS4456',
    brand_id: 8,
    brand: volkswagen,
    vehicle_line: 'light',
    model_description: 'SPACEFOX 1.6/ 1.6 Trend Total Flex 8V 5p 2007',
}
const truck = {
    id: 11,
    plate: 'ABC1D23',
    brand_id: 9,
    brand: ford,
    vehicle_line: 'heavy',
    model_description: null,
}
const extra = { ...truck, id: 12, plate: 'ZZZ1234' }
const ticket = {
    id: 20,
    title: 'Descrição divergente',
    description: 'O aplicativo retornou outro modelo.',
    status: 'open',
    system_id: 1,
    system: systems[0],
    contact_id: 3,
    contact,
    customer_id: 4,
    customer,
    problem_category_id: 5,
    problem_category: category,
    problem_subcategory_ids: [6, 7],
    problem_subcategories: subcategories,
    resolution: 'Conferido no cadastro.',
    vehicle_id: null,
    vehicle: null,
    pending_tasks: [],
    reminders: [],
    follow_up_tickets: [],
}

let wrapper, router, get, post, patch, fleet, brands, loaded
const result = (data, current_page = 1, last_page = 1) => ({
    data,
    meta: { current_page, last_page },
})

beforeEach(() => {
    loaded = { ...ticket }
    fleet = [fox, truck, extra]
    brands = [volkswagen, ford]
    get = vi.spyOn(api, 'get').mockImplementation(async (endpoint, params = {}) => {
        const { q = '', page = 1 } = params
        if (endpoint === 'support-tickets/20') return result(loaded)
        const resources = {
            systems,
            contacts: [contact],
            customers: [customer],
            'problem-categories': [category],
            'problem-subcategories': subcategories,
            'vehicle-brands': brands,
            vehicles: fleet,
        }
        const [resource, id] = endpoint.split('/')
        if (id) return result(resources[resource]?.find((row) => row.id === Number(id)))
        if (resource === 'vehicles') {
            const search = q.toLowerCase()
            if (search)
                return result(
                    fleet.filter(
                        (row) =>
                            row.plate.toLowerCase().includes(search.replace(/[-\s]+/g, '')) ||
                            row.brand.name.toLowerCase().includes(search) ||
                            row.model_description?.toLowerCase().includes(search),
                    ),
                )
            return result(page === 1 ? fleet.filter((row) => row !== extra) : [extra], page, 2)
        }
        return result(resources[resource] ?? [])
    })
    post = vi.spyOn(api, 'post').mockImplementation(async (endpoint, payload) => {
        if (endpoint === 'vehicle-brands') {
            const brand = { id: 31, ...payload }
            brands.push(brand)
            return result(brand)
        }
        if (endpoint === 'vehicles') {
            const vehicle = {
                id: 32,
                ...payload,
                brand: brands.find((row) => row.id === payload.brand_id),
            }
            fleet.push(vehicle)
            return result(vehicle)
        }
        return result({ id: 20 })
    })
    patch = vi.spyOn(api, 'patch').mockResolvedValue(result({ id: 20 }))
})

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    const { notices, dismiss } = useNotifications()
    notices.value.forEach(({ id }) => dismiss(id))
    vi.restoreAllMocks()
})

async function render(path = '/atendimentos/novo', component = TicketFormView) {
    router = createRouter({ history: createMemoryHistory(), routes })
    await router.push(path)
    wrapper = mount(component, { attachTo: document.body, global: { plugins: [router] } })
    await flushPromises()
}

async function choose(field, label, scope = wrapper) {
    await scope.get('#' + field).trigger('click')
    await flushPromises()
    await button(scope.get('#' + field + '-options'), label).trigger('click')
}

async function chooseVehicle(vehicle) {
    await wrapper.get('#ticket-vehicle_id').trigger('click')
    await flushPromises()
    const option = wrapper
        .findAll('#ticket-vehicle_id-options button')
        .find((item) => item.get('strong').text() === vehicle.plate)
    await option.trigger('click')
}

const toggle = (value) => wrapper.get('[role="switch"]').setValue(value)
const submitTicket = () => wrapper.get('.ticket-form').trigger('submit')
const submitModal = (id) => body.get('#' + id).trigger('submit')

async function fillTicket(includeClassification = false) {
    await wrapper.get('#ticket-title').setValue(ticket.title)
    await wrapper.get('#ticket-description').setValue(ticket.description)
    await choose('ticket-system_id', systems[0].name)
    await choose('ticket-contact_id', contact.name)
    await choose('ticket-customer_id', customer.name)
    await wrapper.get('#resolution-resolution').setValue(ticket.resolution)
    if (includeClassification) {
        await choose('ticket-problem_category_id', category.name)
        for (const row of subcategories) await choose('ticket-problem_subcategory_ids', row.name)
    }
}

function expectDraft() {
    expect(wrapper.get('#ticket-title').element.value).toBe(ticket.title)
    expect(wrapper.get('#ticket-description').element.value).toBe(ticket.description)
    expect(wrapper.get('#resolution-resolution').element.value).toBe(ticket.resolution)
    expect(wrapper.get('#ticket-contact_id').text()).toBe(contact.name)
    expect(wrapper.get('#ticket-customer_id').text()).toBe(customer.name)
    expect(wrapper.get('#ticket-problem_category_id').text()).toBe(category.name)
    expect(wrapper.findAll('.subcategory-tag')).toHaveLength(2)
}

describe('seleção de veículo no atendimento', () => {
    it('abre desativada, valida a seleção e preserva a seleção desativada apenas no rascunho', async () => {
        await render()
        expect(wrapper.get('[role="switch"]').element.checked).toBe(false)
        expect(wrapper.find('#ticket-vehicle_id').exists()).toBe(false)
        expect(button(wrapper, 'Novo veículo')).toBeUndefined()
        await toggle(true)
        expect(wrapper.get('#ticket-vehicle_id').exists()).toBe(true)
        expect(button(wrapper, 'Novo veículo')).toBeDefined()
        await fillTicket(true)
        await submitTicket()
        expect(wrapper.get('#ticket-vehicle_id-error').text()).toContain('Selecione um veículo')
        expect(post).not.toHaveBeenCalled()
        await chooseVehicle(fox)
        await toggle(false)
        expect(wrapper.find('.selected-vehicle').exists()).toBe(false)
        await toggle(true)
        expect(wrapper.get('.selected-vehicle').text()).toContain(fox.plate)
        await toggle(false)
        expectDraft()
        await submitTicket()
        await flushPromises()
        expect(post.mock.calls[0][1]).not.toHaveProperty('vehicle_id')
        expect(router.currentRoute.value.path).toBe('/atendimentos/20')
    })

    it.each(['HFS4456', 'hfs-44', 'hfs 4456', 'spacefox 1.6', 'volkswagen'])(
        'pesquisa por %s com a API e mostra a identidade completa',
        async (term) => {
            await render()
            await toggle(true)
            await wrapper.get('#ticket-vehicle_id').trigger('click')
            await flushPromises()
            expect(wrapper.get('#ticket-vehicle_id-options').text()).toContain(
                'Modelo não informado',
            )
            await wrapper.get('[aria-label="Pesquisar veículos"]').setValue(term)
            await vi.waitFor(() =>
                expect(get).toHaveBeenCalledWith(
                    'vehicles',
                    { q: term, per_page: 15, page: 1 },
                    expect.any(AbortSignal),
                ),
            )
            await flushPromises()
            const options = wrapper.findAll('#ticket-vehicle_id-options [role="option"]')
            expect(options).toHaveLength(1)
            expect(options[0].get('strong').text()).toBe(fox.plate)
            for (const label of [volkswagen.name, fox.model_description, 'Linha leve']) {
                expect(options[0].text()).toContain(label)
            }
        },
    )

    it('pagina, cancela pesquisas antigas e distingue falha de busca sem resultado', async () => {
        await render()
        await toggle(true)
        await wrapper.get('#ticket-vehicle_id').trigger('click')
        await flushPromises()
        await button(wrapper, 'Carregar mais opções').trigger('click')
        await flushPromises()
        expect(get).toHaveBeenLastCalledWith(
            'vehicles',
            { q: '', per_page: 15, page: 2 },
            expect.any(AbortSignal),
        )
        expect(wrapper.get('#ticket-vehicle_id-options').text()).toContain(extra.plate)

        let staleResult
        get.mockReturnValueOnce(
            new Promise((resolve) => {
                staleResult = resolve
            }),
        )
        const search = wrapper.get('[aria-label="Pesquisar veículos"]')
        await search.setValue('volks')
        await vi.waitFor(() => expect(get.mock.lastCall[1].q).toBe('volks'))
        const staleSignal = get.mock.lastCall[2]
        await search.setValue('inexistente')
        expect(staleSignal.aborted).toBe(true)
        staleResult(result([fox]))
        await flushPromises()
        expect(wrapper.get('#ticket-vehicle_id-options').text()).not.toContain(fox.plate)
        await vi.waitFor(() => expect(wrapper.text()).toContain('Nenhum veículo encontrado.'))

        get.mockRejectedValueOnce(new ApiError(0))
        await search.setValue('ford')
        await vi.waitFor(() => expect(wrapper.text()).toContain('Não foi possível conectar'))
        await button(wrapper, 'Tentar novamente').trigger('click')
        await flushPromises()
        expect(wrapper.get('#ticket-vehicle_id-options').text()).toContain(truck.plate)
    })

    it('substitui e remove a seleção única, independentemente do sistema e da classificação', async () => {
        await render()
        await toggle(true)
        await chooseVehicle(fox)
        await fillTicket(true)
        await choose('ticket-system_id', systems[1].name)
        expect(wrapper.get('.selected-vehicle').text()).toContain(fox.plate)
        await chooseVehicle(truck)
        expect(wrapper.findAll('.selected-vehicle')).toHaveLength(1)
        expect(wrapper.get('.selected-vehicle').text()).not.toContain(fox.plate)
        expect(wrapper.get('.selected-vehicle').text()).toContain('Modelo não informado')
        await wrapper.get('[aria-label="Remover veículo"]').trigger('click')
        expect(wrapper.find('.selected-vehicle').exists()).toBe(false)
        await chooseVehicle(truck)
        expectDraft()
        await submitTicket()
        await flushPromises()
        const [path, payload] = post.mock.lastCall
        expect(path).toBe('support-tickets')
        expect(payload).toMatchObject({
            vehicle_id: truck.id,
            system_id: 2,
            contact_id: 3,
            customer_id: 4,
            problem_category_id: 5,
            problem_subcategory_ids: [6, 7],
            description: ticket.description,
            resolution: ticket.resolution,
        })
        for (const field of ['plate', 'brand', 'brand_id', 'vehicle_line', 'model_description']) {
            expect(payload).not.toHaveProperty(field)
        }
    })
})

describe('cadastro rápido a partir do atendimento', () => {
    it.each([true, false])(
        'grava e seleciona um veículo com descrição preenchida = %s',
        async (withModel) => {
            await render()
            await fillTicket(true)
            await toggle(true)
            const trigger = button(wrapper, 'Novo veículo')
            trigger.element.focus()
            await trigger.trigger('click')
            expect(wrapper.get('.ticket-form').attributes('inert')).toBe('')
            expect(dialog().get('h2').text()).toBe('Novo veículo')
            await dialog().get('#vehicle-plate').setValue(' xyz-2a34 ')
            await dialog().get('#vehicle-vehicle_line').setValue('heavy')
            if (withModel) await dialog().get('#vehicle-model_description').setValue('Novo modelo')
            if (withModel) {
                const brandTrigger = button(dialog(), 'Nova marca')
                brandTrigger.element.focus()
                await brandTrigger.trigger('click')
                await flushPromises()
                expect(body.findAll('[role="dialog"]')).toHaveLength(2)
                expect(dialog().get('h2').text()).toBe('Nova marca')
                await dialog().trigger('keydown', { key: 'Escape' })
                await flushPromises()
                expect(document.activeElement).toBe(brandTrigger.element)
                await brandTrigger.trigger('click')
                await dialog().get('#vehicle-brand-name').setValue('Marca Nova')
                await submitModal('vehicle-brand-form')
                await flushPromises()
                expect(dialog().get('#vehicle-brand_id').text()).toBe('Marca Nova')
                expect(dialog().get('#vehicle-model_description').element.value).toBe('Novo modelo')
                expect(dialog().get('#vehicle-plate').element.value).toBe('XYZ2A34')
                expect(dialog().get('#vehicle-vehicle_line').element.value).toBe('heavy')
            } else {
                await choose('vehicle-brand_id', volkswagen.name, dialog())
            }

            await submitModal('vehicle-form')
            await flushPromises()
            expect(post).toHaveBeenLastCalledWith('vehicles', {
                brand_id: withModel ? 31 : 8,
                vehicle_line: 'heavy',
                plate: 'XYZ2A34',
                model_description: withModel ? 'Novo modelo' : null,
            })
            expect(post.mock.calls.every(([path]) => path !== 'support-tickets')).toBe(true)
            expect(body.find('[role="dialog"]').exists()).toBe(false)
            expect(document.activeElement).toBe(trigger.element)
            expect(wrapper.get('[role="switch"]').element.checked).toBe(true)
            expect(wrapper.get('.selected-vehicle').text()).toContain('XYZ2A34')
            expect(router.currentRoute.value.path).toBe('/atendimentos/novo')
            expectDraft()
            await wrapper.get('#ticket-vehicle_id').trigger('click')
            await flushPromises()
            expect(wrapper.get('#ticket-vehicle_id-options').text()).toContain('XYZ2A34')
            expect(useNotifications().notices.value.at(-1).message).toBe(
                'Veículo cadastrado com sucesso.',
            )
        },
    )

    it('mantém o rascunho e a seleção anterior após erro, envio concorrente ou cancelamento', async () => {
        await render()
        await fillTicket(true)
        await toggle(true)
        await chooseVehicle(fox)
        await button(wrapper, 'Novo veículo').trigger('click')
        await choose('vehicle-brand_id', ford.name, dialog())
        await dialog().get('#vehicle-vehicle_line').setValue('light')
        await dialog().get('#vehicle-plate').setValue('hfs-4456')
        let fail
        post.mockReturnValueOnce(
            new Promise((_, reject) => {
                fail = reject
            }),
        )
        await submitModal('vehicle-form')
        await submitModal('vehicle-form')
        await submitTicket()
        await dialog().trigger('keydown', { key: 'Escape' })
        expect(post).toHaveBeenCalledTimes(1)
        expect(dialog().get('h2').text()).toBe('Novo veículo')
        fail(
            new ApiError(422, {
                errors: { plate: ['Já existe um veículo cadastrado com esta placa.'] },
            }),
        )
        await flushPromises()
        expect(dialog().get('#vehicle-plate-error').text()).toContain('Já existe um veículo')
        expect(dialog().get('#vehicle-plate').element.value).toBe('HFS4456')
        expect(dialog().get('#vehicle-brand_id').text()).toBe(ford.name)
        await button(dialog(), 'Cancelar').trigger('click')
        await flushPromises()
        expect(wrapper.get('.selected-vehicle').text()).toContain(fox.plate)
        expectDraft()
        await chooseVehicle(truck)
        expect(wrapper.get('.selected-vehicle').text()).toContain(truck.plate)
        expect(post).toHaveBeenCalledTimes(1)
    })
})

describe('edição e consulta do atendimento', () => {
    it.each([
        ['adiciona', null, 'choose', truck.id],
        ['substitui', fox, 'choose', truck.id],
        ['remove', fox, 'disable', null],
        ['preserva', fox, 'keep', fox.id],
    ])('%s o vínculo com o payload da edição', async (_label, initial, action, target) => {
        loaded = { ...ticket, vehicle_id: initial?.id ?? null, vehicle: initial }
        await render('/atendimentos/20/editar')
        expect(wrapper.get('[role="switch"]').element.checked).toBe(!!initial)
        if (initial)
            expect(wrapper.get('.selected-vehicle').text()).toContain(fox.model_description)
        if (action === 'choose') {
            if (!initial) await toggle(true)
            await chooseVehicle(truck)
        }
        if (action === 'disable') {
            await toggle(false)
            expect(wrapper.find('#ticket-vehicle_id').exists()).toBe(false)
        }
        await submitTicket()
        await flushPromises()
        expect(patch).toHaveBeenCalledExactlyOnceWith(
            'support-tickets/20',
            expect.objectContaining({
                vehicle_id: target,
                problem_category_id: 5,
                problem_subcategory_ids: [6, 7],
                description: ticket.description,
                resolution: ticket.resolution,
            }),
        )
    })

    it('apresenta o erro de vínculo retornado pelo backend no seletor e mantém a edição', async () => {
        loaded = { ...ticket, vehicle_id: fox.id, vehicle: fox }
        patch.mockRejectedValueOnce(
            new ApiError(422, { errors: { vehicle_id: ['O veículo não existe.'] } }),
        )
        await render('/atendimentos/20/editar')
        await submitTicket()
        await flushPromises()
        expect(wrapper.get('#ticket-vehicle_id-error').text()).toBe('O veículo não existe.')
        expect(wrapper.get('.selected-vehicle').text()).toContain(fox.plate)
        expect(router.currentRoute.value.path).toBe('/atendimentos/20/editar')
    })

    it('cadastra um veículo na edição e só grava o vínculo ao salvar o atendimento', async () => {
        loaded = { ...ticket, vehicle_id: fox.id, vehicle: fox }
        await render('/atendimentos/20/editar')
        await button(wrapper, 'Novo veículo').trigger('click')
        await choose('vehicle-brand_id', ford.name, dialog())
        await dialog().get('#vehicle-vehicle_line').setValue('heavy')
        await dialog().get('#vehicle-plate').setValue('new-2312')
        await submitModal('vehicle-form')
        await flushPromises()
        expect(post).toHaveBeenCalledExactlyOnceWith('vehicles', {
            brand_id: ford.id,
            vehicle_line: 'heavy',
            model_description: null,
            plate: 'NEW2312',
        })
        expect(patch).not.toHaveBeenCalled()
        expect(wrapper.get('.selected-vehicle').text()).toContain('NEW2312')
        await submitTicket()
        await flushPromises()
        expect(patch).toHaveBeenCalledExactlyOnceWith(
            'support-tickets/20',
            expect.objectContaining({ vehicle_id: 32 }),
        )
    })

    it('consulta o cadastro atual do veículo e oculta a seção quando não há vínculo', async () => {
        await render('/atendimentos/20', TicketDetailView)
        expect(wrapper.text()).not.toContain('Identificação veicular')
        wrapper.unmount()
        loaded = {
            ...ticket,
            vehicle_id: fox.id,
            vehicle: {
                ...fox,
                plate: 'HFS4457',
                model_description: 'Modelo corrigido',
                vehicle_line: 'heavy',
            },
        }
        await render('/atendimentos/20', TicketDetailView)
        expect(wrapper.get('.vehicle-identity').text()).toContain('HFS4457')
        expect(wrapper.get('.vehicle-identity').text()).toContain('Modelo corrigido')
        expect(wrapper.get('.vehicle-identity').text()).toContain('Linha pesada')
    })
})
