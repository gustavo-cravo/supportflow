import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import App from '../App.vue'
import { useNotifications } from '../composables/useNotifications'
import { routes } from '../router'
import { api, ApiError } from '../services/api'

const newTicket = '/atendimentos/novo'
const system = { id: 1, name: 'Sistema atual', is_active: true }
const category = { id: 4, name: 'Acesso', is_active: true, systems: [system] }
const subcategory = { ...category, id: 5, name: 'Login', problem_category_id: category.id }
const resources = [
    ['systems', 'sistema', 'system_id', '/sistemas', system.name],
    ['contacts', 'contato', 'contact_id', '/contatos', 'Contato atual'],
    ['customers', 'cliente', 'customer_id', '/clientes', 'Cliente atual'],
]

let wrapper, router, get, post, records
const page = new DOMWrapper(document.body)
const dialog = () => page.get('[role="dialog"]')
const result = (data) => ({
    data,
    meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 1 },
})

beforeEach(() => {
    records = {
        systems: [system],
        contacts: [{ id: 2, name: 'Contato atual' }],
        customers: [{ id: 3, name: 'Cliente atual' }],
    }
    get = vi.spyOn(api, 'get').mockImplementation(async (endpoint, params = {}) => {
        if (endpoint === 'problem-categories')
            return result(params.system_id === system.id ? [category] : [])
        if (endpoint === 'problem-subcategories')
            return result(
                params.system_id === system.id && params.problem_category_id === category.id
                    ? [subcategory]
                    : [],
            )
        const [kind, id] = endpoint.split('/')
        const rows = records[kind]
        if (id) return result(rows.find((record) => record.id === Number(id)))
        return result(rows.filter((record) => !params.is_active || record.is_active))
    })
    post = vi.spyOn(api, 'post').mockImplementation(async (endpoint, payload) => {
        const record = { ...payload, id: 10 }
        records[endpoint].push(record)
        return result(record)
    })
})

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    const { notices, dismiss } = useNotifications()
    notices.value.forEach(({ id }) => dismiss(id))
    vi.restoreAllMocks()
})

async function render(path = newTicket) {
    router = createRouter({ history: createMemoryHistory(), routes })
    await router.push(path)
    wrapper = mount(App, { attachTo: document.body, global: { plugins: [router] } })
    await flushPromises()
}

async function choose(field, name) {
    await wrapper.get('#ticket-' + field).trigger('click')
    await flushPromises()
    await wrapper
        .get('#ticket-' + field + '-options')
        .findAll('button')
        .find((button) => button.text() === name)
        .trigger('click')
}

async function fillDraft() {
    await wrapper.get('#ticket-title').setValue('Falha ao emitir nota')
    await wrapper.get('#ticket-description').setValue('A falha começou hoje.')
    await wrapper.get('#resolution-resolution').setValue('Tentativa em outra estação.')
    for (const [, , field, , name] of resources) await choose(field, name)
    await choose('problem_category_id', category.name)
    await choose('problem_subcategory_ids', subcategory.name)
}

function expectDraft() {
    expect(router.currentRoute.value.fullPath).toBe(newTicket)
    expect(wrapper.get('#ticket-title').element.value).toBe('Falha ao emitir nota')
    expect(wrapper.get('#ticket-description').element.value).toBe('A falha começou hoje.')
    expect(wrapper.get('#resolution-resolution').element.value).toBe('Tentativa em outra estação.')
}

function expectSelections(except) {
    for (const [, , field, , name] of resources) {
        if (field !== except) expect(wrapper.get('#ticket-' + field).text()).toBe(name)
    }
    if (except !== 'system_id') {
        expect(wrapper.get('#ticket-problem_category_id').text()).toBe(category.name)
        expect(wrapper.get('.subcategory-tags').text()).toContain(subcategory.name)
    }
}

async function open(singular) {
    const trigger = wrapper
        .findAll('.form-links button')
        .find((button) => button.text() === 'Novo ' + singular)
    trigger.element.focus()
    await trigger.trigger('click')
    expect(dialog().get('h2').text()).toBe('Novo ' + singular)
    return trigger
}

async function fillResource(kind, name) {
    await dialog().get('#resource-name').setValue(name)
    if (kind === 'customers') {
        const document = dialog().get('#resource-document')
        await document.setValue('01234567000189')
        expect(document.element.value).toBe('01.234.567/0001-89')
    }
}

async function saveResource() {
    dialog().get('button[type="submit"]').element.click()
    await flushPromises()
}

describe('cadastro rápido no atendimento', () => {
    it.each(resources)(
        'cadastra e seleciona %s sem perder o rascunho',
        async (kind, singular, field) => {
            await render()
            await fillDraft()
            const trigger = await open(singular)
            expect(wrapper.get('.ticket-form').attributes('inert')).toBeDefined()
            const name = 'Cadastro rápido de ' + singular
            await fillResource(kind, name)
            await saveResource()

            expect(post).toHaveBeenCalledExactlyOnceWith(kind, expect.objectContaining({ name }))
            expect(page.find('[role="dialog"]').exists()).toBe(false)
            expect(wrapper.get('.ticket-form').attributes('inert')).toBeUndefined()
            expect(document.activeElement).toBe(trigger.element)
            expect(wrapper.text()).toContain('Registro salvo com sucesso.')
            expectDraft()
            expectSelections(field)
            expect(wrapper.get('#ticket-' + field).text()).toBe(name)
            expect(get).not.toHaveBeenCalledWith(kind + '/10')

            await wrapper.get('#ticket-' + field).trigger('click')
            await flushPromises()
            expect(wrapper.get('#ticket-' + field + '-option-10').attributes('aria-selected')).toBe(
                'true',
            )
            if (kind === 'systems') {
                expect(wrapper.get('#ticket-problem_category_id').text()).toBe('Selecionar…')
                expect(wrapper.find('.subcategory-tags').exists()).toBe(false)
                expect(wrapper.get('#ticket-problem_subcategory_ids').element.disabled).toBe(true)
                await wrapper.get('#ticket-problem_category_id').trigger('click')
                await flushPromises()
                expect(get).toHaveBeenCalledWith(
                    'problem-categories',
                    expect.objectContaining({ selectable: 1, system_id: 10 }),
                    expect.any(AbortSignal),
                )
                expect(wrapper.get('#ticket-problem_category_id-options').text()).toBe('')
            }
        },
        10000,
    )

    it.each([
        ['sistema', 'button'],
        ['contato', 'close'],
        ['cliente', 'escape'],
    ])('descarta o modal de %s sem alterar seleções ou gravar', async (singular, method) => {
        await render()
        await fillDraft()
        await open(singular)
        await dialog().get('#resource-name').setValue('Cadastro descartado')

        if (method === 'escape') await dialog().trigger('keydown', { key: 'Escape' })
        else if (method === 'close')
            await dialog().get('[aria-label="Fechar janela"]').trigger('click')
        else await dialog().get('.modal-footer .btn-secondary').trigger('click')

        expect(page.find('[role="dialog"]').exists()).toBe(false)
        expectDraft()
        expectSelections()
        expect(post).not.toHaveBeenCalled()
    })

    it('preserva os dois formulários após erros de validação e de conexão e permite corrigir', async () => {
        post.mockRejectedValueOnce(
            new ApiError(422, { errors: { email: ['E-mail indisponível.'] } }),
        ).mockRejectedValueOnce(new ApiError(0))
        await render()
        await fillDraft()
        await open('contato')
        await saveResource()
        expect(dialog().get('#resource-name-error').text()).toBe('Informe nome.')
        expect(post).not.toHaveBeenCalled()

        await fillResource('contacts', 'Contato corrigido')
        await dialog().get('#resource-email').setValue('primeiro@example.com')
        await saveResource()
        expect(dialog().get('#resource-email-error').text()).toBe('E-mail indisponível.')
        expect(dialog().get('#resource-email').element.value).toBe('primeiro@example.com')
        await dialog().get('#resource-email').setValue('corrigido@example.com')
        await saveResource()
        expect(dialog().get('.alert-error').text()).toContain(
            'Não foi possível conectar ao servidor',
        )
        expect(dialog().get('#resource-email').element.value).toBe('corrigido@example.com')
        expect(dialog().get('#resource-name').element.value).toBe('Contato corrigido')
        expectDraft()
        expectSelections()

        await saveResource()
        expect(post).toHaveBeenCalledTimes(3)
        expect(wrapper.get('#ticket-contact_id').text()).toBe('Contato corrigido')
        expect(page.find('[role="dialog"]').exists()).toBe(false)
    })

    it('bloqueia envios e fechamento adicionais enquanto a requisição está em andamento', async () => {
        let resolve
        post.mockReturnValueOnce(new Promise((done) => (resolve = done)))
        await render()
        await open('contato')
        await fillResource('contacts', 'Contato novo')
        const save = dialog().get('button[type="submit"]').element
        save.click()
        save.click()
        await dialog().get('form').trigger('submit')
        await dialog().trigger('keydown', { key: 'Escape' })
        expect(save.disabled).toBe(true)
        expect(dialog().get('[aria-label="Fechar janela"]').element.disabled).toBe(true)
        expect(dialog().get('.modal-footer .btn-secondary').element.disabled).toBe(true)
        expect(post).toHaveBeenCalledTimes(1)

        resolve(result({ id: 10, name: 'Contato novo' }))
        await flushPromises()
        expect(page.find('[role="dialog"]').exists()).toBe(false)
        expect(wrapper.get('#ticket-contact_id').text()).toBe('Contato novo')
    })

    it('pede um sistema ativo antes de gravar e mantém as seleções até a confirmação', async () => {
        await render()
        await fillDraft()
        await open('sistema')
        await fillResource('systems', 'Sistema novo')
        const active = dialog().get('#resource-is_active')
        await active.setValue(false)
        await saveResource()
        expect(post).not.toHaveBeenCalled()
        expect(dialog().get('#resource-is_active-error').text()).toContain(
            'Mantenha o sistema ativo',
        )
        expectSelections()
        await active.setValue(true)
        await saveResource()
        expect(post).toHaveBeenCalledExactlyOnceWith(
            'systems',
            expect.objectContaining({ is_active: true }),
        )
        expect(wrapper.get('#ticket-system_id').text()).toBe('Sistema novo')
    })
})

describe('modal nas páginas de gerenciamento', () => {
    it.each(resources)(
        'mantém a abertura e recarrega a listagem de %s',
        async (kind, singular, _, path) => {
            await render(path + '?novo=1')
            expect(dialog().get('h2').text()).toBe('Novo ' + singular)
            const name = 'Cadastro da listagem de ' + singular
            await fillResource(kind, name)
            if (kind === 'systems') await dialog().get('#resource-is_active').setValue(false)
            await saveResource()

            expect(post).toHaveBeenCalledExactlyOnceWith(
                kind,
                expect.objectContaining(kind === 'systems' ? { name, is_active: false } : { name }),
            )
            expect(page.find('[role="dialog"]').exists()).toBe(false)
            expect(router.currentRoute.value.fullPath).toBe(path)
            expect(wrapper.get('table').text()).toContain(name)
        },
    )
})
