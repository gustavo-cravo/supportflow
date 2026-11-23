import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { api, ApiError } from '../services/api'
import { useNotifications } from '../composables/useNotifications'
import { routes } from '../router'
import ProblemClassificationForm from '../components/ProblemClassificationForm.vue'
import ProblemClassificationList from '../components/ProblemClassificationList.vue'
import ProblemCategoriesView from '../views/ProblemCategoriesView.vue'

const systems = [
    { id: 1, name: 'Indicar', is_active: true },
    { id: 2, name: 'iLube', is_active: true },
    { id: 3, name: '.way', is_active: true },
    { id: 4, name: 'Legado', is_active: false },
]
const category = {
    id: 5,
    name: 'Falha de acesso',
    description: 'Problemas de autenticação',
    is_active: true,
    systems: systems.slice(0, 2),
    system_ids: [1, 2],
    system_associations: [],
    subcategories_count: 1,
    created_at: '2026-11-23T10:00:00-03:00',
    updated_at: '2026-11-23T10:00:00-03:00',
}
const subcategory = {
    ...category,
    id: 8,
    problem_category_id: category.id,
    name: 'Credenciais inválidas',
    system_ids: [2],
    systems: [systems[1]],
    system_associations: [{ ...systems[0], association_active: false }],
}
const result = (data, meta = {}) => ({
    data,
    meta: { current_page: 1, last_page: 1, total: Array.isArray(data) ? data.length : 1, ...meta },
})
let wrapper, get, post, patch

beforeEach(() => {
    get = vi.spyOn(api, 'get').mockImplementation(async (endpoint) => {
        if (endpoint === 'systems') return result(systems)
        if (endpoint.startsWith('systems/'))
            return result(systems.find((system) => system.id === Number(endpoint.split('/')[1])))
        if (endpoint === 'problem-categories/5') return result(category)
        if (endpoint === 'problem-subcategories') return result([subcategory])
        return result([category])
    })
    post = vi.spyOn(api, 'post').mockResolvedValue(result(category))
    patch = vi.spyOn(api, 'patch').mockResolvedValue(result(category))
})
afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    const { notices, dismiss } = useNotifications()
    notices.value.forEach((notice) => dismiss(notice.id))
    vi.restoreAllMocks()
})

async function renderForm(props = {}) {
    wrapper = mount(ProblemClassificationForm, { props, global: { stubs: { Teleport: true } } })
    await flushPromises()
}
async function renderList(
    props = {},
    path = '/categorias-problemas',
    component = ProblemClassificationList,
) {
    const router = createRouter({ history: createMemoryHistory(), routes })
    await router.push(path)
    wrapper = mount(component, { props, global: { plugins: [router], stubs: { Teleport: true } } })
    await flushPromises()
    return router
}
const button = (text) => wrapper.findAll('button').find((item) => item.text() === text)
const systemCheckbox = (name) =>
    wrapper
        .findAll('.system-options-list label')
        .find((label) => label.text().startsWith(name))
        .get('input')

describe('problem classification forms', () => {
    it('creates a shared category using multiple systems and the actual API fields', async () => {
        await renderForm({ record: null })
        await wrapper.get('#problem-name').setValue('Falha de acesso')
        await wrapper.get('#problem-description').setValue('Detalhes')
        await systemCheckbox('Indicar').setValue(true)
        await systemCheckbox('iLube').setValue(true)
        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(post).toHaveBeenCalledExactlyOnceWith('problem-categories', {
            name: 'Falha de acesso',
            description: 'Detalhes',
            is_active: true,
            system_ids: [1, 2],
        })
        expect(wrapper.emitted('saved')[0][0].id).toBe(5)
        expect(wrapper.text()).not.toContain('Legado')
    })

    it('loads every page of system choices rather than truncating associations', async () => {
        get.mockResolvedValueOnce(result([systems[0]], { last_page: 2 })).mockResolvedValueOnce(
            result([systems[2]], { current_page: 2, last_page: 2 }),
        )
        await renderForm()
        expect(systemCheckbox('.way').exists()).toBe(true)
        expect(get.mock.calls.map((call) => call[1])).toEqual([
            { per_page: 100, page: 1 },
            { per_page: 100, page: 2 },
        ])
    })

    it('requires a name and at least one system before creating', async () => {
        await renderForm()
        await wrapper.get('form').trigger('submit')
        expect(wrapper.text()).toContain('Informe nome.')
        expect(wrapper.text()).toContain('Selecione pelo menos um sistema.')
        expect(post).not.toHaveBeenCalled()
    })

    it('restricts subcategory choices to the parent systems and uses its category ID', async () => {
        await renderForm({ category })
        expect(get).not.toHaveBeenCalled()
        expect(wrapper.findAll('.system-options-list label').map((label) => label.text())).toEqual([
            'Indicar',
            'iLube',
        ])
        await wrapper.get('#problem-name').setValue('Sessão expirada')
        await systemCheckbox('iLube').setValue(true)
        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(post).toHaveBeenCalledWith('problem-subcategories', {
            name: 'Sessão expirada',
            description: null,
            is_active: true,
            system_ids: [2],
            problem_category_id: 5,
        })
    })

    it('retains existing inactive system associations and omits unchanged availability on edit', async () => {
        await renderForm({ record: { ...category, system_ids: [4], systems: [systems[3]] } })
        expect(systemCheckbox('Legado').element.checked).toBe(true)
        expect(wrapper.text()).toContain('vínculo preservado')
        await wrapper.get('#problem-name').setValue('Nome revisado')
        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(patch).toHaveBeenCalledWith('problem-categories/5', {
            name: 'Nome revisado',
            description: category.description,
            is_active: true,
        })
    })

    it('does not offer an inactive parent system for a new child association', async () => {
        await renderForm({ category: { ...category, systems: [...category.systems, systems[3]] } })
        expect(wrapper.text()).not.toContain('Legado')
    })

    it('edits a historical subcategory with no enabled systems without recreating associations', async () => {
        await renderForm({ category, record: { ...subcategory, system_ids: [], systems: [] } })
        await wrapper.get('#problem-description').setValue('Histórico revisado')
        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(patch).toHaveBeenCalledWith('problem-subcategories/8', {
            name: subcategory.name,
            description: 'Histórico revisado',
            is_active: true,
        })
    })

    it('sends changed availability and warns about cascading disablement', async () => {
        await renderForm({ record: category })
        expect(wrapper.text()).toContain('disponibilidade das subcategorias')
        await systemCheckbox('Indicar').setValue(false)
        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(patch).toHaveBeenCalledWith(
            'problem-categories/5',
            expect.objectContaining({ system_ids: [2] }),
        )
    })

    it('displays duplicate-name and indexed system errors while preserving values for retry', async () => {
        post.mockRejectedValueOnce(
            new ApiError(422, {
                errors: {
                    name: ['Já existe uma categoria com esse nome.'],
                    'system_ids.0': ['Sistema não disponível.'],
                },
            }),
        )
        await renderForm()
        await wrapper.get('#problem-name').setValue('Falha de acesso')
        await systemCheckbox('Indicar').setValue(true)
        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(wrapper.get('#problem-name-error').text()).toContain('Já existe')
        expect(wrapper.get('#problem-systems-error').text()).toContain('Sistema não disponível.')
        expect(wrapper.get('#problem-name').element.value).toBe('Falha de acesso')
        expect(systemCheckbox('Indicar').element.checked).toBe(true)
        await wrapper.get('#problem-name').setValue('Outra categoria')
        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(post).toHaveBeenCalledTimes(2)
        expect(wrapper.emitted('saved')).toHaveLength(1)
    })

    it('handles a system lookup failure and retries before enabling submission', async () => {
        get.mockRejectedValueOnce(new ApiError(500))
        await renderForm()
        expect(wrapper.get('button[type="submit"]').element.disabled).toBe(true)
        await button('Tentar novamente').trigger('click')
        await flushPromises()
        expect(systemCheckbox('Indicar').exists()).toBe(true)
        expect(wrapper.get('button[type="submit"]').element.disabled).toBe(false)
    })

    it('aborts pending system lookups when the dialog closes', async () => {
        get.mockReturnValue(new Promise(() => {}))
        await renderForm()
        const signal = get.mock.calls[0][2]
        wrapper.unmount()
        wrapper = null
        expect(signal.aborted).toBe(true)
    })

    it('prevents duplicate saves and closure while submitting', async () => {
        post.mockReturnValue(new Promise(() => {}))
        await renderForm()
        await wrapper.get('#problem-name').setValue('Categoria')
        await systemCheckbox('Indicar').setValue(true)
        await wrapper.get('form').trigger('submit')
        await wrapper.get('form').trigger('submit')
        await wrapper.get('[role="dialog"]').trigger('keydown', { key: 'Escape' })
        expect(post).toHaveBeenCalledTimes(1)
        expect(wrapper.emitted('close')).toBeUndefined()
    })
})

describe('problem classification management', () => {
    it.each([false, true])(
        'opens the new-record dialog from the list (subcategory=%s)',
        async (isSubcategory) => {
            await renderList(
                isSubcategory ? { category } : {},
                isSubcategory ? '/categorias-problemas/5' : '/categorias-problemas',
            )
            await button(isSubcategory ? 'Nova subcategoria' : 'Nova categoria').trigger('click')
            await flushPromises()
            expect(wrapper.get('[role="dialog"] h2').text()).toBe(
                isSubcategory ? 'Nova subcategoria' : 'Nova categoria',
            )
            expect(wrapper.get('#problem-name').element.value).toBe('')
        },
    )
    it('lists systems and state and navigates into category subcategories', async () => {
        const router = await renderList()
        expect(wrapper.text()).toContain('Indicar, iLube')
        await wrapper.get('.record-title').trigger('click')
        await flushPromises()
        expect(router.currentRoute.value.path).toBe('/categorias-problemas/5')
    })

    it('filters and paginates with server parameters and preserves category scope', async () => {
        get.mockImplementation(async (endpoint) =>
            endpoint === 'problem-subcategories'
                ? result([subcategory], { last_page: 2, total: 16 })
                : result(systems[1]),
        )
        const router = await renderList(
            { category },
            '/categorias-problemas/5?is_active=0&system_id=2',
        )
        await button('Próxima').trigger('click')
        await flushPromises()
        expect(
            get.mock.calls.filter((call) => call[0] === 'problem-subcategories').at(-1)[1],
        ).toEqual({ problem_category_id: 5, is_active: '0', system_id: '2', page: '2' })
        await wrapper.get('#filter-q').setValue('Credenciais')
        await wrapper.get('.filter-bar').trigger('submit')
        await flushPromises()
        expect(router.currentRoute.value.query).toMatchObject({ q: 'Credenciais', page: '1' })
        expect(
            get.mock.calls.filter((call) => call[0] === 'problem-subcategories').at(-1)[1],
        ).toMatchObject({ q: 'Credenciais', problem_category_id: 5 })
    })

    it.each([true, false])(
        'confirms state changes from is_active=%s without touching associations',
        async (isActive) => {
            get.mockResolvedValue(result([{ ...category, is_active: isActive }]))
            await renderList()
            await wrapper
                .get(
                    `[aria-label="${isActive ? 'Desativar' : 'Ativar'} categoria Falha de acesso"]`,
                )
                .trigger('click')
            expect(patch).not.toHaveBeenCalled()
            await button('Cancelar').trigger('click')
            expect(patch).not.toHaveBeenCalled()
            await wrapper
                .get(
                    `[aria-label="${isActive ? 'Desativar' : 'Ativar'} categoria Falha de acesso"]`,
                )
                .trigger('click')
            await button('Confirmar').trigger('click')
            await flushPromises()
            expect(patch).toHaveBeenCalledExactlyOnceWith('problem-categories/5', {
                is_active: !isActive,
            })
            expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
        },
    )

    it('keeps the confirmation open on failure and allows retry', async () => {
        patch.mockRejectedValueOnce(new ApiError(500))
        await renderList({ category }, '/categorias-problemas/5')
        await wrapper
            .get('[aria-label="Desativar subcategoria Credenciais inválidas"]')
            .trigger('click')
        await button('Confirmar').trigger('click')
        await flushPromises()
        expect(wrapper.get('[role="dialog"]').text()).toContain(new ApiError(500).message)
        await button('Confirmar').trigger('click')
        await flushPromises()
        expect(patch).toHaveBeenLastCalledWith('problem-subcategories/8', { is_active: false })
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    })

    it('shows subcategory registration and disabled associations without mutating it', async () => {
        await renderList({ category }, '/categorias-problemas/5')
        await wrapper.get('.record-title').trigger('click')
        const dialog = wrapper.get('[role="dialog"]')
        expect(dialog.text()).toContain('Dados da subcategoria')
        expect(dialog.text()).toContain('Associações desabilitadas (histórico)')
        expect(dialog.text()).toContain('Indicar')
        expect(dialog.findAll('input,select,textarea')).toHaveLength(0)
        expect(patch).not.toHaveBeenCalled()
        await button('Editar subcategoria').trigger('click')
        expect(wrapper.get('#problem-name').element.value).toBe(subcategory.name)
    })

    it('shows parent detail and reloads child availability once after parent editing', async () => {
        const updated = {
            ...category,
            system_ids: [1],
            systems: [systems[0]],
            name: 'Acesso revisado',
        }
        patch.mockResolvedValue(result(updated))
        await renderList({}, '/categorias-problemas/5', ProblemCategoriesView)
        await button('Editar categoria').trigger('click')
        await flushPromises()
        await systemCheckbox('iLube').setValue(false)
        await wrapper.get('form#problem-classification-form').trigger('submit')
        await flushPromises()
        expect(wrapper.get('h1').text()).toBe('Acesso revisado')
        expect(get.mock.calls.filter((call) => call[0] === 'problem-subcategories')).toHaveLength(2)
    })

    it('handles a missing parent without listing unrelated subcategories and supports retry', async () => {
        get.mockRejectedValueOnce(new ApiError(404))
        await renderList({}, '/categorias-problemas/5', ProblemCategoriesView)
        expect(wrapper.text()).toContain(new ApiError(404).message)
        expect(get.mock.calls.some((call) => call[0] === 'problem-subcategories')).toBe(false)
        await button('Tentar novamente').trigger('click')
        await flushPromises()
        expect(wrapper.get('h1').text()).toBe(category.name)
        expect(wrapper.text()).toContain(subcategory.name)
    })

    it('resolves the new management route without changing existing ticket routes', () => {
        const router = createRouter({ history: createMemoryHistory(), routes })
        expect(router.resolve('/categorias-problemas').name).toBe('problem-categories')
        expect(router.resolve('/categorias-problemas/5').name).toBe('problem-categories')
        expect(router.resolve('/atendimentos/5').name).toBe('ticket-detail')
        expect(router.resolve('/atendimentos/5/editar').name).toBe('ticket-edit')
    })
})
