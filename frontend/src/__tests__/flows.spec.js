import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { defineComponent, ref } from 'vue'
import { api, ApiError } from '../services/api'
import { resources } from '../config/resources'
import { useList } from '../composables/useList'
import { routes } from '../router'
import CloseTicketModal from '../components/CloseTicketModal.vue'
import ResourceForm from '../components/ResourceForm.vue'
import RelationSelect from '../components/RelationSelect.vue'
import ResourceListView from '../views/ResourceListView.vue'
import TicketFormView from '../views/TicketFormView.vue'
import TicketDetailView from '../views/TicketDetailView.vue'
import RecordDetail from '../components/RecordDetail.vue'

const wrappers = []

it('creates a ticket using active-system search and the actual API field names', async () => {
    const get = vi.spyOn(api, 'get').mockImplementation(async (endpoint) => ({
        data: [
            { id: endpoint === 'systems' ? 2 : 3, name: endpoint === 'systems' ? 'ERP' : 'Ana' },
        ],
        meta: { current_page: 1, last_page: 1 },
    }))
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { id: 40 } })
    const router = createRouter({ history: createMemoryHistory(), routes })
    await router.push('/atendimentos/novo')
    const wrapper = render(TicketFormView, { global: { plugins: [router] } })
    await wrapper.get('#ticket-title').setValue('Falha fiscal')
    await wrapper.get('#ticket-description').setValue('Erro na emissão')
    for (const [field, label] of [
        ['system_id', 'ERP'],
        ['contact_id', 'Ana'],
    ]) {
        await wrapper.get('#ticket-' + field).trigger('click')
        await flushPromises()
        await wrapper
            .findAll('button')
            .find((button) => button.text() === label)
            .trigger('click')
    }
    expect(get.mock.calls.find((call) => call[0] === 'systems')[1]).toMatchObject({ is_active: 1 })
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(post.mock.calls[0]).toEqual([
        'support-tickets',
        expect.objectContaining({
            system_id: 2,
            contact_id: 3,
            customer_id: null,
            previous_ticket_id: null,
            title: 'Falha fiscal',
            description: 'Erro na emissão',
        }),
    ])
    expect(router.currentRoute.value.path).toBe('/atendimentos/40')
})

it('selecting closed in the detail opens the guarded closure dialog instead of changing status', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({
        data: {
            id: 10,
            title: 'Histórico',
            description: 'Problema',
            status: 'open',
            system: { name: 'Legado', is_active: false },
            contact: { name: 'Ana' },
            pending_tasks: [],
            reminders: [],
            follow_up_tickets: [],
        },
    })
    const patch = vi.spyOn(api, 'patch')
    const router = createRouter({ history: createMemoryHistory(), routes })
    await router.push('/atendimentos/10')
    const wrapper = render(TicketDetailView, {
        global: { plugins: [router], stubs: { Teleport: true } },
    })
    await flushPromises()
    expect(wrapper.text()).toContain('Inativo')
    await wrapper.get('.status-control select').setValue('closed')
    expect(patch).not.toHaveBeenCalled()
    expect(wrapper.findComponent(CloseTicketModal).exists()).toBe(true)
})

it('opens a dashboard-linked reminder by its ID without adding unsupported API filters', async () => {
    const get = vi
        .spyOn(api, 'get')
        .mockImplementation(async (endpoint) =>
            endpoint === 'reminders/8'
                ? { data: { id: 8, title: 'Retorno', scheduled_at: '2026-10-22T09:00:00-03:00' } }
                : { data: [], meta: { total: 0 } },
        )
    const router = createRouter({ history: createMemoryHistory(), routes })
    await router.push('/lembretes?ver=8')
    const wrapper = render(ResourceListView, {
        props: { kind: 'reminders' },
        global: { plugins: [router], stubs: { Teleport: true } },
    })
    await flushPromises()
    expect(wrapper.findComponent(RecordDetail).props('record').id).toBe(8)
    expect(get.mock.calls.find((call) => call[0] === 'reminders')[1]).toEqual({})
})
const render = (component, options = {}) => {
    const wrapper = mount(component, { global: { stubs: { Teleport: true } }, ...options })
    wrappers.push(wrapper)
    return wrapper
}
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount())
    vi.restoreAllMocks()
})

describe('ticket closure confirmation', () => {
    const conflict = () =>
        new ApiError(409, {
            code: 'open_pending_tasks',
            open_tasks_count: 1,
            pending_tasks: [{ id: 7, description: 'Aguardar log fiscal' }],
        })
    it('sends explicit confirmation only after the user chooses to continue', async () => {
        const post = vi
            .spyOn(api, 'post')
            .mockRejectedValueOnce(conflict())
            .mockResolvedValueOnce({ data: { id: 3, status: 'closed' } })
        const wrapper = render(CloseTicketModal, { props: { ticket: { id: 3, title: 'Falha' } } })
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Encerrar atendimento')
            .trigger('click')
        await flushPromises()
        expect(post.mock.calls[0][1]).not.toHaveProperty('confirm_open_tasks')
        expect(wrapper.text()).toContain('Aguardar log fiscal')
        expect(wrapper.emitted('closed')).toBeUndefined()
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Encerrar mesmo assim')
            .trigger('click')
        await flushPromises()
        expect(post.mock.calls[1][1].confirm_open_tasks).toBe(true)
        expect(wrapper.emitted('closed')[0][0].status).toBe('closed')
    })
    it('canceling a conflict does not submit a confirmed closure', async () => {
        const post = vi.spyOn(api, 'post').mockRejectedValueOnce(conflict())
        const wrapper = render(CloseTicketModal, { props: { ticket: { id: 3, title: 'Falha' } } })
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Encerrar atendimento')
            .trigger('click')
        await flushPromises()
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Cancelar')
            .trigger('click')
        expect(post).toHaveBeenCalledTimes(1)
        expect(wrapper.emitted('close')).toHaveLength(1)
    })
    it('prevents duplicate submissions while closing', async () => {
        const post = vi.spyOn(api, 'post').mockReturnValue(new Promise(() => {}))
        const wrapper = render(CloseTicketModal, { props: { ticket: { id: 3, title: 'Falha' } } })
        const button = wrapper
            .findAll('button')
            .find((item) => item.text() === 'Encerrar atendimento')
        await button.trigger('click')
        await button.trigger('click')
        expect(post).toHaveBeenCalledTimes(1)
    })
})

describe('forms', () => {
    it('shows API field errors without discarding entered values', async () => {
        vi.spyOn(api, 'post').mockRejectedValue(
            new ApiError(422, { errors: { email: ['O campo email está inválido.'] } }),
        )
        const wrapper = render(ResourceForm, { props: { config: resources.contacts } })
        await wrapper.get('#resource-name').setValue('Ana')
        await wrapper.get('#resource-email').setValue('ana@example.com')
        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(wrapper.text()).toContain('está inválido')
        expect(wrapper.get('#resource-name').element.value).toBe('Ana')
        expect(wrapper.get('#resource-email').attributes('aria-invalid')).toBe('true')
    })
    it('allows a knowledge article with no system', async () => {
        const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { id: 4 } })
        const wrapper = render(ResourceForm, { props: { config: resources['knowledge-articles'] } })
        await wrapper.get('#resource-problem').setValue('Sem rede')
        await wrapper.get('#resource-resolution_procedure').setValue('Verificar cabo')
        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(post).toHaveBeenCalledWith('knowledge-articles', {
            system_id: null,
            problem: 'Sem rede',
            resolution_procedure: 'Verificar cabo',
        })
        expect(wrapper.emitted('saved')).toHaveLength(1)
    })
    it('allows an independent reminder and serializes its local date', async () => {
        const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { id: 5 } })
        const wrapper = render(ResourceForm, { props: { config: resources.reminders } })
        await wrapper.get('#resource-title').setValue('Retorno')
        await wrapper.get('#resource-scheduled_at').setValue('2026-10-22T09:00')
        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(post.mock.calls[0][1]).toMatchObject({
            support_ticket_id: null,
            scheduled_at: '2026-10-22 09:00:00',
        })
    })
    it('requires the ticket creation fields before calling the API', async () => {
        const post = vi.spyOn(api, 'post')
        const router = createRouter({ history: createMemoryHistory(), routes })
        await router.push('/atendimentos/novo')
        const wrapper = render(TicketFormView, { global: { plugins: [router] } })
        await wrapper.get('form').trigger('submit')
        expect(post).not.toHaveBeenCalled()
        expect(wrapper.text()).toContain('Informe título.')
        expect(wrapper.text()).toContain('Informe contato.')
    })
})

describe('real pagination and async selections', () => {
    it('requests the next server page while preserving filters in the URL', async () => {
        const get = vi.spyOn(api, 'get').mockResolvedValue({
            data: [{ id: 1, name: 'Ana' }],
            meta: { current_page: 1, last_page: 2, total: 16, from: 1, to: 15 },
        })
        const router = createRouter({ history: createMemoryHistory(), routes })
        await router.push('/contatos?q=Ana')
        const wrapper = render(ResourceListView, {
            props: { kind: 'contacts' },
            global: { plugins: [router] },
        })
        await flushPromises()
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Próxima')
            .trigger('click')
        await flushPromises()
        expect(router.currentRoute.value.query).toMatchObject({ page: '2', q: 'Ana' })
        expect(get.mock.calls.at(-1)[1]).toMatchObject({ page: '2', q: 'Ana' })
    })
    it('ignores stale list responses after filters change', async () => {
        let resolveFirst
        vi.spyOn(api, 'get')
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        resolveFirst = resolve
                    }),
            )
            .mockResolvedValueOnce({ data: [{ id: 2 }], meta: { total: 1 } })
        const params = ref({ q: 'old' })
        const Test = defineComponent({
            setup() {
                return useList('contacts', params)
            },
            template: '<div>{{ rows }}</div>',
        })
        const wrapper = render(Test)
        params.value = { q: 'new' }
        await flushPromises()
        resolveFirst({ data: [{ id: 1 }], meta: { total: 1 } })
        await flushPromises()
        expect(wrapper.vm.rows).toEqual([{ id: 2 }])
    })
    it('loads additional lookup pages instead of truncating associations to the first page', async () => {
        const get = vi
            .spyOn(api, 'get')
            .mockResolvedValueOnce({
                data: [{ id: 1, name: 'Ana' }],
                meta: { current_page: 1, last_page: 2 },
            })
            .mockResolvedValueOnce({
                data: [{ id: 2, name: 'Bruno' }],
                meta: { current_page: 2, last_page: 2 },
            })
        const wrapper = render(RelationSelect, {
            attachTo: document.body,
            props: { endpoint: 'contacts', id: 'contact' },
        })
        await wrapper.get('#contact').trigger('click')
        await flushPromises()
        const more = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Carregar mais opções')
        more.element.focus()
        await more.trigger('click')
        await flushPromises()
        expect(get.mock.calls[1][1].page).toBe(2)
        expect(document.activeElement).toBe(wrapper.get('[role="combobox"]').element)
        expect(wrapper.get('#contact').attributes('aria-expanded')).toBe('true')
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Bruno')
            .trigger('click')
        expect(wrapper.emitted('update:modelValue')[0]).toEqual([2])
    })
    it('resolves every main route and the numeric ticket detail route', () => {
        const router = createRouter({ history: createMemoryHistory(), routes })
        for (const path of [
            '/',
            '/atendimentos',
            '/pendencias',
            '/lembretes',
            '/conhecimento',
            '/contatos',
            '/clientes',
            '/sistemas',
            '/atendimentos/42',
            '/atendimentos/42/editar',
        ]) {
            expect(router.resolve(path).name).toBeTruthy()
        }
    })
})
