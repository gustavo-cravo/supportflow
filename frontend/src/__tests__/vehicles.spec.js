import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import App from '../App.vue'
import { useNotifications } from '../composables/useNotifications'
import { routes } from '../router'
import { api, ApiError } from '../services/api'

const page = new DOMWrapper(document.body)
const dialog = () => page.get('.modal-backdrop:not([inert]) [role="dialog"]')
const longModel = 'SPACEFOX 1.6/ 1.6 Trend Total Flex 8V 5p 2007 '.repeat(35) + 'Fim da descrição'
const button = (scope, label) => scope.findAll('button').find((item) => item.text() === label)
const submit = (id) => page.get('#' + id).trigger('submit')
let wrapper, router, get, post, patch, brands, fleet

function filterRows(resource, params) {
    const q = String(params?.q ?? '').toLowerCase()
    if (resource === 'vehicle-brands')
        return brands
            .filter((brand) => brand.name.toLowerCase().includes(q))
            .map((brand) => ({
                ...brand,
                vehicles_count: fleet.filter((vehicle) => vehicle.brand_id === brand.id).length,
            }))
    if (resource !== 'vehicles') return []
    return fleet
        .filter((vehicle) => !params?.brand_id || vehicle.brand_id === Number(params.brand_id))
        .filter((vehicle) => !params?.vehicle_line || vehicle.vehicle_line === params.vehicle_line)
        .map((vehicle) => ({ ...vehicle, brand: brands.find((b) => b.id === vehicle.brand_id) }))
        .filter(
            (vehicle) =>
                !q ||
                vehicle.plate.toLowerCase().includes(q.replace(/[-\s]+/g, '')) ||
                vehicle.brand?.name.toLowerCase().includes(q) ||
                vehicle.model_description?.toLowerCase().includes(q),
        )
}

beforeEach(() => {
    brands = [
        { id: 1, name: 'Alfa Veículos' },
        { id: 2, name: 'Beta Motors' },
    ]
    fleet = [
        {
            id: 1,
            brand_id: 1,
            vehicle_line: 'light',
            plate: 'ABC1D23',
            model_description: longModel,
            support_tickets_count: 3,
        },
        {
            id: 2,
            brand_id: 2,
            vehicle_line: 'heavy',
            plate: 'HFS4456',
            model_description: null,
            support_tickets_count: 0,
        },
        {
            id: 3,
            brand_id: 1,
            vehicle_line: 'heavy',
            plate: 'ZZZ3400',
            model_description: 'Carga 2020',
            support_tickets_count: 1,
        },
    ]
    get = vi.spyOn(api, 'get').mockImplementation(async (endpoint, params) => {
        const [resource, id] = endpoint.split('/')
        const rows = filterRows(resource, params)
        if (id) return { data: rows.find((row) => row.id === Number(id)) }
        const current = params?.page ?? 1
        const size = params?.per_page ?? 2
        const start = (current - 1) * size
        return {
            data: rows.slice(start, start + size),
            meta: {
                current_page: current,
                last_page: Math.max(1, Math.ceil(rows.length / size)),
                from: rows.length ? start + 1 : null,
                to: Math.min(start + size, rows.length),
                total: rows.length,
                ...(resource === 'vehicles' && {
                    counts: {
                        total: fleet.length,
                        light: fleet.filter((row) => row.vehicle_line === 'light').length,
                        heavy: fleet.filter((row) => row.vehicle_line === 'heavy').length,
                    },
                }),
            },
        }
    })
    post = vi.spyOn(api, 'post').mockImplementation(async (endpoint, payload) => {
        const records = endpoint === 'vehicles' ? fleet : brands
        const record = { ...payload, id: records.length + 1 }
        records.push(record)
        return { data: record }
    })
    patch = vi.spyOn(api, 'patch').mockImplementation(async (endpoint, payload) => {
        const [resource, id] = endpoint.split('/')
        const records = resource === 'vehicles' ? fleet : brands
        const record = records.find((item) => item.id === Number(id))
        Object.assign(record, payload)
        return { data: record }
    })
})

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    const { notices, dismiss } = useNotifications()
    notices.value.forEach(({ id }) => dismiss(id))
    vi.restoreAllMocks()
})

async function render(path = '/veiculos') {
    router = createRouter({ history: createMemoryHistory(), routes })
    await router.push(path)
    wrapper = mount(App, { attachTo: document.body, global: { plugins: [router] } })
    await flushPromises()
}

async function openNewVehicle() {
    await button(wrapper, 'Novo veículo').trigger('click')
    await flushPromises()
}

async function chooseBrand(id, name) {
    await page.get('#' + id).trigger('click')
    await flushPromises()
    await button(page.get('#' + id + '-options'), name).trigger('click')
}

async function fillVehicle(plate, model) {
    await chooseBrand('vehicle-brand_id', 'Alfa Veículos')
    await dialog().get('#vehicle-vehicle_line').setValue('light')
    await dialog().get('#vehicle-plate').setValue(plate)
    if (model !== undefined) await dialog().get('#vehicle-model_description').setValue(model)
}

async function applyFilters() {
    await wrapper.get('form.filter-bar').trigger('submit')
    await flushPromises()
}

describe('página de veículos', () => {
    it('abre pelo menu, exibe totais globais e alterna as abas pelo teclado', async () => {
        await render('/sistemas')
        const link = wrapper.get('nav[aria-label="Navegação principal"] a[href="/veiculos"]')
        expect(link.text()).toBe('Veículos')
        await link.trigger('click')
        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('vehicles'))
        await flushPromises()
        expect(wrapper.get('#catalog-tab-vehicles').attributes('aria-selected')).toBe('true')
        expect(wrapper.text()).toContain('Modelo não informado')
        expect(wrapper.text()).toContain('Linha pesada')
        expect(wrapper.findAll('.catalog-indicators strong').map((card) => card.text())).toEqual([
            '3',
            '1',
            '2',
        ])
        expect(wrapper.findAll('tbody tr')).toHaveLength(2)

        await wrapper.get('#catalog-tab-vehicles').trigger('keydown', { key: 'ArrowRight' })
        await flushPromises()
        expect(wrapper.get('#catalog-tab-brands').attributes('aria-selected')).toBe('true')
        expect(document.activeElement.id).toBe('catalog-tab-brands')
        expect(wrapper.get('tbody tr').text()).toContain('Alfa Veículos')
        expect(wrapper.get('tbody tr').text()).toMatch(/2$/)
        expect(wrapper.find('.catalog-indicators').exists()).toBe(false)
        await wrapper.get('#catalog-tab-vehicles').trigger('click')
        await flushPromises()
        expect(wrapper.get('tbody tr').text()).toContain('ABC1D23')
    })

    it('exibe a descrição completa em detalhes e permite cancelar a edição', async () => {
        await render()
        await wrapper.get('[aria-label="Visualizar ABC1D23"]').trigger('click')
        expect(dialog().text()).toContain('Fim da descrição')
        expect(
            dialog()
                .findAll('dd')
                .map((field) => field.text()),
        ).toContain(longModel)
        await button(dialog(), 'Fechar').trigger('click')
        await wrapper.get('[aria-label="Editar ABC1D23"]').trigger('click')
        expect(dialog().get('#vehicle-model_description').element.value).toBe(longModel)
        await dialog().get('#vehicle-model_description').setValue('Rascunho')
        await button(dialog(), 'Cancelar').trigger('click')
        expect(patch).not.toHaveBeenCalled()
        await wrapper.get('[aria-label="Editar ABC1D23"]').trigger('click')
        expect(dialog().get('#vehicle-model_description').element.value).toBe(longModel)
    })

    it.each([
        [' aBc - 2d34 ', 'ABC2D34', longModel],
        ['xyz-4567', 'XYZ4567', undefined],
    ])(
        'cadastra a placa %s, normaliza a entrada e atualiza após confirmação',
        async (input, plate, model) => {
            await render()
            await openNewVehicle()
            expect(dialog().get('#vehicle-vehicle_line').element.value).toBe('')
            await fillVehicle(input, model)
            expect(dialog().get('#vehicle-plate').element.value).toBe(plate)
            await submit('vehicle-form')
            await flushPromises()
            expect(post).toHaveBeenCalledWith('vehicles', {
                brand_id: 1,
                vehicle_line: 'light',
                model_description: model ?? null,
                plate,
            })
            expect(page.find('[role="dialog"]').exists()).toBe(false)
            expect(page.text()).toContain('Veículo salvo com sucesso.')
            expect(
                wrapper.findAll('.catalog-indicators strong').map((card) => card.text()),
            ).toEqual(['4', '2', '2'])
        },
    )

    it('valida os campos obrigatórios e preserva o formulário após uma placa duplicada', async () => {
        await render()
        await openNewVehicle()
        await submit('vehicle-form')
        for (const field of ['brand_id', 'vehicle_line', 'plate']) {
            expect(
                dialog()
                    .get('#vehicle-' + field + '-error')
                    .exists(),
            ).toBe(true)
        }
        expect(post).not.toHaveBeenCalled()

        post.mockRejectedValueOnce(
            new ApiError(422, { errors: { plate: ['Placa já cadastrada.'] } }),
        )
        await fillVehicle(' abc-1d23 ', 'Modelo em edição')
        await submit('vehicle-form')
        await flushPromises()
        expect(dialog().get('#vehicle-plate-error').text()).toContain('já cadastrada')
        expect(dialog().get('#vehicle-model_description').element.value).toBe('Modelo em edição')
        expect(page.text()).not.toContain('Veículo salvo com sucesso.')
    })

    it('bloqueia envio simultâneo e mantém os dados após uma falha de comunicação', async () => {
        await render()
        await openNewVehicle()
        await fillVehicle('XYZ-2334', 'Modelo')
        let reject
        post.mockReturnValueOnce(
            new Promise((resolve, fail) => {
                reject = fail
            }),
        )
        await submit('vehicle-form')
        await submit('vehicle-form')
        expect(post).toHaveBeenCalledTimes(1)
        expect(dialog().get('button[type="submit"]').attributes()).toHaveProperty('disabled')
        await dialog().trigger('keydown', { key: 'Escape' })
        expect(page.find('[role="dialog"]').exists()).toBe(true)
        reject(new ApiError(0))
        await flushPromises()
        expect(dialog().get('#vehicle-plate').element.value).toBe('XYZ2334')
        expect(dialog().text()).toContain('Não foi possível conectar')
        expect(page.text()).not.toContain('Veículo salvo com sucesso.')
    })

    it('envia a pesquisa e os filtros combinados à API e preserva os filtros ao editar', async () => {
        await render()
        await wrapper.get('#filter-q').setValue('hfs-44')
        await applyFilters()
        expect(get).toHaveBeenLastCalledWith(
            'vehicles',
            expect.objectContaining({ q: 'hfs-44', page: 1 }),
            expect.any(AbortSignal),
        )
        expect(wrapper.get('tbody').text()).toContain('HFS4456')
        expect(wrapper.get('tbody').text()).not.toContain('ABC1D23')

        await wrapper.get('#filter-q').setValue('trend')
        await wrapper.get('#filter-vehicle_line').setValue('light')
        await applyFilters()
        expect(wrapper.get('tbody').text()).toContain('ABC1D23')

        await wrapper.get('#filter-q').setValue('beta motors')
        await chooseBrand('filter-brand_id', 'Beta Motors')
        await wrapper.get('#filter-vehicle_line').setValue('heavy')
        await applyFilters()
        expect(get).toHaveBeenLastCalledWith(
            'vehicles',
            {
                q: 'beta motors',
                brand_id: 2,
                vehicle_line: 'heavy',
                page: 1,
            },
            expect.any(AbortSignal),
        )
        expect(wrapper.get('tbody').text()).toContain('HFS4456')
        expect(wrapper.findAll('.catalog-indicators strong').map((card) => card.text())).toEqual([
            '3',
            '1',
            '2',
        ])

        await wrapper.get('[aria-label="Editar HFS4456"]').trigger('click')
        expect(dialog().get('#vehicle-brand_id').text()).toBe('Beta Motors')
        expect(dialog().get('#vehicle-vehicle_line').element.value).toBe('heavy')
        await dialog().get('#vehicle-model_description').setValue('Descrição completada')
        await submit('vehicle-form')
        await flushPromises()
        expect(patch).toHaveBeenCalledWith(
            'vehicles/2',
            expect.objectContaining({ model_description: 'Descrição completada' }),
        )
        expect(wrapper.get('#filter-vehicle_line').element.value).toBe('heavy')
        expect(wrapper.get('tbody').text()).toContain('Descrição completada')

        await button(wrapper, 'Limpar').trigger('click')
        await flushPromises()
        expect(get).toHaveBeenLastCalledWith('vehicles', { page: 1 }, expect.any(AbortSignal))
        await button(wrapper, 'Próxima').trigger('click')
        await flushPromises()
        expect(get).toHaveBeenLastCalledWith('vehicles', { page: 2 }, expect.any(AbortSignal))
        expect(wrapper.get('tbody').text()).toContain('ZZZ3400')
    })

    it('distingue lista vazia, busca sem resultado e erro recuperável', async () => {
        get.mockRejectedValueOnce(new ApiError(0))
        await render()
        expect(wrapper.text()).toContain('Não foi possível carregar')
        await button(wrapper, 'Tentar novamente').trigger('click')
        await flushPromises()
        expect(wrapper.get('tbody').text()).toContain('ABC1D23')
        await wrapper.get('#filter-q').setValue('inexistente')
        await applyFilters()
        expect(wrapper.text()).toContain('Nenhum resultado encontrado')
        fleet.length = 0
        await button(wrapper, 'Limpar').trigger('click')
        await flushPromises()
        expect(wrapper.text()).toContain('Nenhum veículo cadastrado')
    })
})

describe('marcas de veículos', () => {
    it('pesquisa por nome, cria, edita e apresenta duplicidades sem perder a entrada', async () => {
        await render()
        await wrapper.get('#catalog-tab-brands').trigger('click')
        await flushPromises()
        await wrapper.get('#filter-q').setValue('beta')
        await applyFilters()
        expect(get).toHaveBeenLastCalledWith(
            'vehicle-brands',
            { q: 'beta', page: 1 },
            expect.any(AbortSignal),
        )
        expect(wrapper.get('tbody').text()).toContain('Beta Motors')
        expect(wrapper.get('tbody').text()).not.toContain('Alfa Veículos')
        await wrapper.get('[aria-label="Editar Beta Motors"]').trigger('click')
        await dialog().get('#vehicle-brand-name').setValue('Beta Atualizada')
        await submit('vehicle-brand-form')
        await flushPromises()
        expect(patch).toHaveBeenCalledWith('vehicle-brands/2', { name: 'Beta Atualizada' })
        expect(wrapper.get('#filter-q').element.value).toBe('beta')
        expect(wrapper.get('tbody').text()).toContain('Beta Atualizada')

        await button(wrapper, 'Nova marca').trigger('click')
        await submit('vehicle-brand-form')
        expect(dialog().get('#vehicle-brand-name-error').exists()).toBe(true)
        post.mockRejectedValueOnce(
            new ApiError(422, { errors: { name: ['Já existe uma marca com este nome.'] } }),
        )
        await dialog().get('#vehicle-brand-name').setValue('    BETA ATUALIZADA')
        await submit('vehicle-brand-form')
        await flushPromises()
        expect(dialog().get('#vehicle-brand-name-error').text()).toContain('Já existe uma marca')
        expect(dialog().get('#vehicle-brand-name').element.value).toBe('    BETA ATUALIZADA')
        await dialog().get('#vehicle-brand-name').setValue('Beta Nova')
        await submit('vehicle-brand-form')
        await flushPromises()
        expect(post).toHaveBeenLastCalledWith('vehicle-brands', { name: 'Beta Nova' })
        expect(page.find('[role="dialog"]').exists()).toBe(false)
        expect(wrapper.get('tbody').text()).toContain('Beta Nova')
    })

    it('pesquisa opções, abre uma segunda janela e seleciona a marca criada no rascunho', async () => {
        await render()
        await openNewVehicle()
        await dialog().get('#vehicle-brand_id').trigger('click')
        await flushPromises()
        await dialog().get('[role="combobox"]').setValue('beta')
        await vi.waitFor(() =>
            expect(get).toHaveBeenCalledWith(
                'vehicle-brands',
                expect.objectContaining({ q: 'beta', per_page: 15 }),
                expect.any(AbortSignal),
            ),
        )
        await button(dialog().get('#vehicle-brand_id-options'), 'Beta Motors').trigger('click')
        await dialog().get('#vehicle-plate').setValue('abc-2d34')
        await dialog().get('#vehicle-vehicle_line').setValue('heavy')
        await dialog().get('#vehicle-model_description').setValue('Modelo preservado')
        const trigger = button(dialog(), 'Nova marca')
        trigger.element.focus()
        await trigger.trigger('click')
        await flushPromises()
        expect(page.findAll('[role="dialog"]')).toHaveLength(2)
        expect(page.find('[inert] #vehicle-form').exists()).toBe(true)
        expect(dialog().get('h2').text()).toBe('Nova marca')
        expect(dialog().element).toBe(document.activeElement)
        await dialog().trigger('keydown', { key: 'Escape' })
        await flushPromises()
        expect(document.activeElement).toBe(trigger.element)
        expect(dialog().get('#vehicle-brand_id').text()).toBe('Beta Motors')
        expect(dialog().get('#vehicle-model_description').element.value).toBe('Modelo preservado')

        await trigger.trigger('click')
        await dialog().get('#vehicle-brand-name').setValue('Gama Caminhões')
        await submit('vehicle-brand-form')
        await flushPromises()
        expect(post).toHaveBeenCalledWith('vehicle-brands', { name: 'Gama Caminhões' })
        expect(page.findAll('[role="dialog"]')).toHaveLength(1)
        expect(dialog().get('#vehicle-brand_id').text()).toBe('Gama Caminhões')
        expect(dialog().get('#vehicle-plate').element.value).toBe('ABC2D34')
        expect(dialog().get('#vehicle-vehicle_line').element.value).toBe('heavy')
        await submit('vehicle-form')
        await flushPromises()
        expect(post).toHaveBeenLastCalledWith('vehicles', {
            plate: 'ABC2D34',
            brand_id: 3,
            vehicle_line: 'heavy',
            model_description: 'Modelo preservado',
        })
        expect(document.body.style.overflow).toBe('')
        expect(router.currentRoute.value.path).toBe('/veiculos')
    })
})
