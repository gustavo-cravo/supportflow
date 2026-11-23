import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { api, ApiError } from '../services/api'
import { resources } from '../config/resources'
import { useNotifications } from '../composables/useNotifications'
import { routes } from '../router'
import RecordDetail from '../components/RecordDetail.vue'
import TicketDetailView from '../views/TicketDetailView.vue'

let wrapper, writeText, get, post, patch
const registration = {
    id: 12,
    name: 'Ana do contato',
    phone: '+55 (11) 98765-4321',
    email: 'ana@example.com',
    notes: 'Primeira linha\nSegunda linha',
    created_at: '2026-11-20T12:00:00-03:00',
    updated_at: '2026-11-21T12:00:00-03:00',
}
const customer = {
    ...registration,
    id: 25,
    name: 'Cliente associado',
    document: '01.234.567/0001-89',
    phone: '(21) 2222-3333',
    email: 'cliente@example.com',
}
const ticket = {
    id: 10,
    title: 'Atendimento de teste',
    description: 'Problema',
    status: 'open',
    contact_id: registration.id,
    customer_id: customer.id,
    contact: registration,
    customer,
    system: { name: 'ERP', is_active: true },
    pending_tasks: [{ id: 7, description: 'Aguardar retorno', status: 'pending' }],
    reminders: [{ id: 8, title: 'Lembrar retorno' }],
    previous_ticket: { id: 9, title: 'Anterior', status: 'closed' },
    follow_up_tickets: [{ id: 11, title: 'Retorno', status: 'open' }],
}

async function render(record = ticket) {
    get.mockResolvedValue({ data: record })
    const router = createRouter({ history: createMemoryHistory(), routes })
    await router.push('/atendimentos/10')
    wrapper = mount(TicketDetailView, {
        attachTo: document.body,
        global: { plugins: [router], stubs: { Teleport: true } },
    })
    await flushPromises()
    return router
}

async function open(kind = 'contato') {
    const button = wrapper.get(`[aria-label^="Visualizar ${kind} "]`)
    button.element.focus()
    await button.trigger('click')
    await flushPromises()
    return wrapper.get('[role="dialog"]')
}

beforeEach(() => {
    writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    get = vi.spyOn(api, 'get')
    post = vi.spyOn(api, 'post')
    patch = vi.spyOn(api, 'patch')
})

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    const { notices, dismiss } = useNotifications()
    notices.value.forEach((notice) => dismiss(notice.id))
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
})

describe('ticket registration quick view', () => {
    it.each([
        ['contato', registration, 'Dados do contato · #12'],
        ['cliente', customer, 'Dados do cliente · #25'],
    ])(
        'opens the associated %s with all existing registration fields, read only',
        async (kind, record, title) => {
            await render()
            const dialog = await open(kind)
            expect(dialog.get('h2').text()).toBe(title)
            for (const name of ['name', 'phone', 'email', 'notes']) {
                expect(dialog.text()).toContain(record[name])
            }
            if (kind === 'cliente') expect(dialog.text()).toContain(customer.document)
            expect(dialog.text()).toContain('Criado em')
            expect(dialog.text()).toContain('Última atualização')
            expect(dialog.findAll('input, select, textarea, a')).toHaveLength(0)
            expect(dialog.text()).not.toContain('Editar')
            expect(dialog.text()).not.toContain('WhatsApp')
            expect(get).toHaveBeenCalledTimes(1)
        },
    )

    it.each([
        ['contato', 'Nome', registration.name],
        ['contato', 'Telefone', registration.phone],
        ['contato', 'E-mail', registration.email],
        ['contato', 'Observações', registration.notes],
        ['cliente', 'CPF/CNPJ', customer.document],
        ['cliente', 'Telefone', customer.phone],
    ])('copies only the original %s %s value and confirms success', async (kind, label, value) => {
        await render()
        const dialog = await open(kind)
        await dialog.get(`[aria-label="Copiar ${label}"]`).trigger('click')
        await flushPromises()
        expect(writeText).toHaveBeenCalledExactlyOnceWith(value)
        expect(useNotifications().notices.value.at(-1)).toMatchObject({ tone: 'success' })
        expect(post).not.toHaveBeenCalled()
        expect(patch).not.toHaveBeenCalled()
    })

    it('displays empty fields without copy buttons, including a legacy customer document', async () => {
        await render({
            ...ticket,
            customer: { ...customer, document: null, phone: '', email: null, notes: null },
        })
        const dialog = await open('cliente')
        expect(dialog.text()).toContain('CPF/CNPJ')
        expect(
            dialog.findAll('dd').filter((field) => field.text() === 'Não informado'),
        ).toHaveLength(4)
        expect(dialog.findAll('[aria-label^="Copiar "]')).toHaveLength(1)
    })

    it('leaves an unassociated customer as plain text', async () => {
        await render({ ...ticket, customer_id: null, customer: null })
        const field = wrapper
            .findAll('.detail-list > div')
            .find((item) => item.get('dt').text() === 'Cliente')
        expect(field.get('dd').text()).toBe('Não associado')
        expect(field.findAll('button, a')).toHaveLength(0)
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    })

    it.each(['escape', 'backdrop', 'header', 'footer'])(
        'closes through %s and restores focus and scrolling',
        async (method) => {
            await render()
            const overflow = document.body.style.overflow
            const dialog = await open()
            expect(document.body.style.overflow).toBe('hidden')
            if (method === 'escape') await dialog.trigger('keydown', { key: 'Escape' })
            if (method === 'backdrop') await wrapper.get('.modal-backdrop').trigger('click')
            if (method === 'header')
                await dialog.get('[aria-label="Fechar janela"]').trigger('click')
            if (method === 'footer') await dialog.get('.modal-footer button').trigger('click')
            expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
            expect(document.activeElement).toBe(
                wrapper.get('[aria-label^="Visualizar contato "]').element,
            )
            expect(document.body.style.overflow).toBe(overflow)
        },
    )

    it('keeps focus inside the dialog and does not close on an inside click', async () => {
        await render()
        const dialog = await open()
        await dialog.trigger('click')
        expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
        const buttons = dialog.findAll('button')
        buttons.at(-1).element.focus()
        await buttons.at(-1).trigger('keydown', { key: 'Tab' })
        expect(document.activeElement).toBe(buttons[0].element)
        await buttons[0].trigger('keydown', { key: 'Tab', shiftKey: true })
        expect(document.activeElement).toBe(buttons.at(-1).element)
    })

    it.each(['denied', 'unavailable'])(
        'reports a clipboard error when access is %s',
        async (reason) => {
            if (reason === 'denied') writeText.mockRejectedValue(new Error('Permission denied'))
            else vi.stubGlobal('navigator', {})
            await render()
            const dialog = await open()
            await dialog.get('[aria-label="Copiar Telefone"]').trigger('click')
            await flushPromises()
            expect(useNotifications().notices.value.at(-1)).toMatchObject({
                tone: 'error',
                message: 'Não foi possível copiar. Selecione e copie o valor manualmente.',
            })
            expect(dialog.text()).toContain(registration.phone)
        },
    )

    it('reopens without duplicate requests, navigation, mutations or changes to related sections', async () => {
        const original = JSON.stringify(ticket)
        const router = await render()
        for (const kind of ['contato', 'cliente', 'contato']) {
            const dialog = await open(kind)
            await dialog.trigger('keydown', { key: 'Escape' })
        }
        expect(get).toHaveBeenCalledExactlyOnceWith(
            'support-tickets/10',
            undefined,
            expect.any(AbortSignal),
        )
        expect(post).not.toHaveBeenCalled()
        expect(patch).not.toHaveBeenCalled()
        expect(JSON.stringify(ticket)).toBe(original)
        expect(wrapper.get('.status-control select').element.value).toBe('open')
        for (const text of ['Aguardar retorno', 'Lembrar retorno', 'Anterior', 'Retorno']) {
            expect(wrapper.text()).toContain(text)
        }
        expect(router.currentRoute.value.path).toBe('/atendimentos/10')
    })

    it.each([404, 500])(
        'handles a failed ticket lookup (%s) without opening a modal and allows retry',
        async (status) => {
            get.mockRejectedValueOnce(
                new ApiError(status, { message: 'Falha ao carregar atendimento.' }),
            )
            await render()
            expect(wrapper.text()).toContain(new ApiError(status).message)
            expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
            await wrapper.get('.state-panel button').trigger('click')
            await flushPromises()
            expect((await open()).text()).toContain(registration.name)
        },
    )

    it('preserves the existing edit action in registration detail screens', async () => {
        wrapper = mount(RecordDetail, {
            props: { config: resources.contacts, record: registration },
            global: { stubs: { Teleport: true } },
        })
        expect(wrapper.get('h2').text()).toBe('Detalhes · #12')
        expect(wrapper.findAll('[aria-label^="Copiar "]')).toHaveLength(0)
        await wrapper.get('.btn-primary').trigger('click')
        expect(wrapper.emitted('edit')[0][0]).toEqual(registration)
    })
})
