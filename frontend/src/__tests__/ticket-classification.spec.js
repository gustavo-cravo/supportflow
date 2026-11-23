import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { api, ApiError } from '../services/api'
import { useNotifications } from '../composables/useNotifications'
import { routes } from '../router'
import RelationSelect from '../components/RelationSelect.vue'
import TicketFormView from '../views/TicketFormView.vue'
import TicketDetailView from '../views/TicketDetailView.vue'

const systems = [
    { id: 1, name: 'Indicar', is_active: true },
    { id: 2, name: 'iLube', is_active: true },
    { id: 3, name: '.way', is_active: true },
]
const category = {
    id: 5,
    name: 'Falha de acesso',
    is_active: true,
    systems: systems.slice(0, 2),
    system_ids: [1, 2],
}
const otherCategory = {
    ...category,
    id: 6,
    name: 'Busca por placa',
    systems: [systems[0]],
    system_ids: [1],
}
const subcategory = { ...category, id: 8, problem_category_id: 5, name: 'Credenciais inválidas' }
const otherSubcategory = { ...subcategory, id: 9, name: 'Sessão expirada' }
const contact = { id: 4, name: 'Ana', phone: '(11) 99999-9999' }
const ticket = {
    id: 10,
    title: 'Erro ao entrar',
    description: 'Descrição original',
    status: 'open',
    system_id: 1,
    system: systems[0],
    contact_id: 4,
    contact,
    customer_id: 7,
    customer: { id: 7, name: 'Loja', document: '01234567000189' },
    previous_ticket_id: null,
    problem_category_id: 5,
    problem_subcategory_id: 8,
    problem_category: category,
    problem_subcategory: subcategory,
    pending_tasks: [],
    reminders: [],
    follow_up_tickets: [],
}
let wrapper, get, post, patch, loaded, categories, subcategories
const result = (data, meta = {}) => ({
    data,
    meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 1, ...meta },
})

beforeEach(() => {
    loaded = { ...ticket }
    categories = [category, otherCategory]
    subcategories = [subcategory, otherSubcategory]
    get = vi.spyOn(api, 'get').mockImplementation(async (endpoint, params = {}) => {
        if (endpoint === 'support-tickets/10') return result(loaded)
        if (endpoint === 'systems') return result(systems)
        if (endpoint.startsWith('systems/'))
            return result(systems.find((system) => system.id === Number(endpoint.split('/')[1])))
        if (endpoint === 'contacts') return result([contact])
        if (endpoint === 'contacts/4') return result(contact)
        if (endpoint === 'customers/7') return result(ticket.customer)
        if (endpoint === 'problem-categories')
            return result(
                categories.filter(
                    (record) =>
                        record.is_active && record.system_ids.includes(Number(params.system_id)),
                ),
            )
        if (endpoint === 'problem-subcategories')
            return result(
                subcategories.filter(
                    (record) =>
                        record.is_active &&
                        record.system_ids.includes(Number(params.system_id)) &&
                        record.problem_category_id === Number(params.problem_category_id),
                ),
            )
        throw new Error('Unexpected API call: ' + endpoint)
    })
    post = vi.spyOn(api, 'post').mockResolvedValue(result({ id: 20 }))
    patch = vi.spyOn(api, 'patch').mockResolvedValue(result({ id: 10 }))
})
afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    const { notices, dismiss } = useNotifications()
    notices.value.forEach((notice) => dismiss(notice.id))
    vi.restoreAllMocks()
})
async function render(editing = false, component = TicketFormView) {
    const router = createRouter({ history: createMemoryHistory(), routes })
    await router.push(editing ? '/atendimentos/10/editar' : '/atendimentos/novo')
    wrapper = mount(component, { global: { plugins: [router], stubs: { Teleport: true } } })
    await flushPromises()
}
async function choose(field, name) {
    await wrapper.get('#ticket-' + field).trigger('click')
    await flushPromises()
    const option = wrapper
        .findAll('[role="option"] button')
        .find((button) => button.text() === name)
    expect(option, 'Expected option ' + name).toBeDefined()
    await option.trigger('click')
    await flushPromises()
}
async function fillBase() {
    await wrapper.get('#ticket-title').setValue('Erro ao entrar')
    await wrapper.get('#ticket-description').setValue('Descrição original')
    await choose('system_id', 'Indicar')
    await choose('contact_id', 'Ana')
}
async function submit() {
    await wrapper.get('form.ticket-form').trigger('submit')
    await flushPromises()
}
const textButton = (text) => wrapper.findAll('button').find((button) => button.text() === text)

describe('ticket classification form integration', () => {
    it.each(['full', 'category', 'none'])(
        'creates a ticket with %s classification and preserves other fields',
        async (kind) => {
            await render()
            expect(wrapper.get('#ticket-problem_category_id').element.disabled).toBe(true)
            expect(wrapper.get('#ticket-problem_subcategory_id').element.disabled).toBe(true)
            await fillBase()
            if (kind !== 'none') await choose('problem_category_id', category.name)
            if (kind === 'full') await choose('problem_subcategory_id', subcategory.name)
            await submit()
            expect(post).toHaveBeenCalledExactlyOnceWith(
                'support-tickets',
                expect.objectContaining({
                    title: 'Erro ao entrar',
                    description: 'Descrição original',
                    system_id: 1,
                    contact_id: 4,
                    problem_category_id: kind === 'none' ? null : 5,
                    problem_subcategory_id: kind === 'full' ? 8 : null,
                }),
            )
            if (kind !== 'none')
                expect(
                    get.mock.calls.find((call) => call[0] === 'problem-categories')[1],
                ).toMatchObject({ selectable: 1, system_id: 1 })
            if (kind === 'full')
                expect(
                    get.mock.calls.find((call) => call[0] === 'problem-subcategories')[1],
                ).toMatchObject({ selectable: 1, system_id: 1, problem_category_id: 5 })
        },
    )

    it('classifies an old ticket and uses the same edit endpoint', async () => {
        loaded = {
            ...ticket,
            problem_category_id: null,
            problem_subcategory_id: null,
            problem_category: null,
            problem_subcategory: null,
        }
        await render(true)
        await choose('problem_category_id', category.name)
        await choose('problem_subcategory_id', subcategory.name)
        await submit()
        expect(patch).toHaveBeenCalledWith(
            'support-tickets/10',
            expect.objectContaining({
                problem_category_id: 5,
                problem_subcategory_id: 8,
                description: ticket.description,
            }),
        )
    })

    it.each(['inactive', 'unavailable'])(
        'preserves %s historical classification without extra detail requests',
        async (condition) => {
            loaded = {
                ...ticket,
                problem_category: {
                    ...category,
                    is_active: condition !== 'inactive',
                    systems: condition === 'unavailable' ? [] : category.systems,
                },
                problem_subcategory: { ...subcategory, is_active: false },
            }
            await render(true)
            expect(wrapper.get('#ticket-problem_category_id').text()).toContain(category.name)
            expect(wrapper.get('#ticket-problem_subcategory_id').text()).toContain(subcategory.name)
            expect(wrapper.text()).toContain('Classificação histórica preservada')
            await wrapper.get('#ticket-title').setValue('Título revisado')
            await submit()
            expect(patch).toHaveBeenCalledWith(
                'support-tickets/10',
                expect.objectContaining({
                    problem_category_id: 5,
                    problem_subcategory_id: 8,
                    title: 'Título revisado',
                }),
            )
            expect(get.mock.calls.filter((call) => call[0].startsWith('problem-'))).toHaveLength(0)
        },
    )

    it('does not clear an old subcategory silently when category changes and blocks saving until resolved', async () => {
        await render(true)
        await choose('problem_category_id', otherCategory.name)
        expect(wrapper.get('#ticket-problem_subcategory_id').text()).toContain(subcategory.name)
        expect(wrapper.get('#ticket-problem_subcategory_id-error').text()).toContain('compatível')
        await submit()
        expect(patch).not.toHaveBeenCalled()
        expect(wrapper.get('#ticket-description').element.value).toBe(ticket.description)
        await textButton('Remover subcategoria').trigger('click')
        await submit()
        expect(patch).toHaveBeenCalledWith(
            'support-tickets/10',
            expect.objectContaining({ problem_category_id: 6, problem_subcategory_id: null }),
        )
    })

    it('keeps an orphaned draft visible and offers an explicit removal even when its selector is disabled', async () => {
        await render(true)
        await wrapper.get('[aria-label="Remover categoria"]').trigger('click')
        expect(wrapper.get('#ticket-problem_subcategory_id').element.disabled).toBe(true)
        expect(wrapper.get('#ticket-problem_subcategory_id').text()).toContain(subcategory.name)
        await submit()
        expect(patch).not.toHaveBeenCalled()
        await textButton('Remover subcategoria').trigger('click')
        await submit()
        expect(patch).toHaveBeenCalledWith(
            'support-tickets/10',
            expect.objectContaining({ problem_category_id: null, problem_subcategory_id: null }),
        )
    })

    it('keeps a compatible shared classification when the system changes', async () => {
        await render(true)
        await choose('system_id', 'iLube')
        await submit()
        expect(patch).toHaveBeenCalledWith(
            'support-tickets/10',
            expect.objectContaining({
                system_id: 2,
                problem_category_id: 5,
                problem_subcategory_id: 8,
            }),
        )
    })

    it('requires an explicit replacement of a subcategory unavailable in the new system', async () => {
        loaded = {
            ...ticket,
            problem_subcategory: { ...subcategory, systems: [systems[0]], system_ids: [1] },
        }
        subcategories = [
            { ...subcategory, systems: [systems[0]], system_ids: [1] },
            otherSubcategory,
        ]
        await render(true)
        await choose('system_id', 'iLube')
        await submit()
        expect(patch).not.toHaveBeenCalled()
        expect(wrapper.get('#ticket-problem_subcategory_id').text()).toContain(subcategory.name)
        await choose('problem_subcategory_id', otherSubcategory.name)
        await submit()
        expect(patch).toHaveBeenCalledWith(
            'support-tickets/10',
            expect.objectContaining({ system_id: 2, problem_subcategory_id: 9 }),
        )
    })

    it('reports an incompatible system and clears both fields only on explicit user action', async () => {
        await render(true)
        await choose('system_id', '.way')
        expect(wrapper.get('#ticket-problem_category_id').text()).toContain(category.name)
        expect(wrapper.text()).toContain('mantida para revisão')
        await submit()
        expect(patch).not.toHaveBeenCalled()
        await textButton('remova a classificação').trigger('click')
        await submit()
        expect(patch).toHaveBeenCalledWith(
            'support-tickets/10',
            expect.objectContaining({
                system_id: 3,
                problem_category_id: null,
                problem_subcategory_id: null,
            }),
        )
    })

    it('allows removing a historical subcategory while keeping an inactive category', async () => {
        loaded = { ...ticket, problem_category: { ...category, is_active: false } }
        await render(true)
        await wrapper.get('[aria-label="Remover subcategoria"]').trigger('click')
        await submit()
        expect(patch).toHaveBeenCalledWith(
            'support-tickets/10',
            expect.objectContaining({ problem_category_id: 5, problem_subcategory_id: null }),
        )
    })

    it('loads options only on demand, explains empty category choices and leaves classification optional', async () => {
        await render()
        await fillBase()
        await choose('system_id', '.way')
        expect(get.mock.calls.some((call) => call[0].startsWith('problem-'))).toBe(false)
        await wrapper.get('#ticket-problem_category_id').trigger('click')
        await flushPromises()
        expect(wrapper.text()).toContain('Nenhuma categoria disponível para este sistema.')
        await submit()
        expect(post).toHaveBeenCalledWith(
            'support-tickets',
            expect.objectContaining({ problem_category_id: null, problem_subcategory_id: null }),
        )
    })

    it('handles a failed category lookup with retry and retains the entered description', async () => {
        await render()
        await fillBase()
        get.mockRejectedValueOnce(new ApiError(500))
        await wrapper.get('#ticket-problem_category_id').trigger('click')
        await flushPromises()
        expect(wrapper.text()).toContain(new ApiError(500).message)
        await textButton('Tentar novamente').trigger('click')
        await flushPromises()
        expect(wrapper.findAll('[role="option"] button').map((button) => button.text())).toContain(
            category.name,
        )
        expect(wrapper.get('#ticket-description').element.value).toBe('Descrição original')
    })

    it('shows backend classification errors and preserves selections for correction and retry', async () => {
        patch.mockRejectedValueOnce(
            new ApiError(422, {
                errors: { problem_category_id: ['Categoria desativada durante a edição.'] },
            }),
        )
        await render(true)
        await submit()
        expect(wrapper.get('#ticket-problem_category_id-error').text()).toContain(
            'Categoria desativada',
        )
        expect(wrapper.get('#ticket-problem_subcategory_id').text()).toContain(subcategory.name)
        await submit()
        expect(patch).toHaveBeenCalledTimes(2)
    })
})

describe('scoped relation lookup races', () => {
    it('removes previous-context choices while reloading and ignores stale responses', async () => {
        let resolveFirst, resolveSecond
        get.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    resolveFirst = resolve
                }),
        ).mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    resolveSecond = resolve
                }),
        )
        wrapper = mount(RelationSelect, {
            props: { id: 'lookup', endpoint: 'problem-categories', params: { system_id: 1 } },
        })
        await wrapper.get('#lookup').trigger('click')
        await flushPromises()
        const firstSignal = get.mock.calls[0][2]
        await wrapper.setProps({ params: { system_id: 2 } })
        await flushPromises()
        expect(firstSignal.aborted).toBe(true)
        resolveSecond(result([otherCategory]))
        await flushPromises()
        resolveFirst(result([category]))
        await flushPromises()
        expect(wrapper.text()).toContain(otherCategory.name)
        expect(wrapper.text()).not.toContain(category.name)
        let resolveThird
        get.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    resolveThird = resolve
                }),
        )
        await wrapper.setProps({ params: { system_id: 3 } })
        expect(wrapper.text()).not.toContain(otherCategory.name)
        expect(wrapper.text()).toContain('Buscando opções')
        resolveThird(result([]))
        await flushPromises()
    })

    it('closes and aborts a lookup when its dependent field becomes disabled', async () => {
        get.mockReturnValue(new Promise(() => {}))
        wrapper = mount(RelationSelect, {
            props: { id: 'lookup', endpoint: 'problem-subcategories' },
        })
        await wrapper.get('#lookup').trigger('click')
        await flushPromises()
        const signal = get.mock.calls[0][2]
        await wrapper.setProps({ disabled: true })
        expect(signal.aborted).toBe(true)
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false)
        expect(wrapper.get('#lookup').element.disabled).toBe(true)
    })
})

describe('ticket classification detail', () => {
    it.each(['none', 'category', 'inactive'])(
        'displays %s classification in the existing information panel and preserves quick view',
        async (kind) => {
            loaded = {
                ...ticket,
                problem_category:
                    kind === 'none' ? null : { ...category, is_active: kind !== 'inactive' },
                problem_subcategory:
                    kind === 'inactive' ? { ...subcategory, is_active: false } : null,
            }
            await render(true, TicketDetailView)
            const information = (label) =>
                wrapper
                    .findAll('.detail-list > div')
                    .find((field) => field.get('dt').text() === label)
                    .get('dd')
            expect(information('Categoria do problema').text()).toContain(
                kind === 'none' ? 'Não classificado' : category.name,
            )
            expect(information('Subcategoria do problema').text()).toContain(
                kind === 'none'
                    ? 'Não classificado'
                    : kind === 'category'
                      ? 'Não informada'
                      : subcategory.name,
            )
            if (kind === 'inactive')
                expect(information('Categoria do problema').text()).toContain('Inativa')
            expect(information('Categoria do problema').findAll('button,input')).toHaveLength(0)
            await wrapper.get('[aria-label="Visualizar contato Ana"]').trigger('click')
            expect(wrapper.get('[role="dialog"]').text()).toContain(contact.phone)
            expect(patch).not.toHaveBeenCalled()
        },
    )
})
