import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ResourceForm from '../components/ResourceForm.vue'
import RecordDetail from '../components/RecordDetail.vue'
import { resources } from '../config/resources'
import { api, ApiError } from '../services/api'

let wrapper
const render = (record) => {
    wrapper = mount(ResourceForm, {
        props: { config: resources.customers, record },
        global: { stubs: { Teleport: true } },
    })
    return wrapper
}

afterEach(() => {
    wrapper?.unmount()
    vi.restoreAllMocks()
})

describe('customer CPF/CNPJ form', () => {
    it('marks the document required and prevents empty submissions with an accessible field error', async () => {
        const post = vi.spyOn(api, 'post')
        render()
        await wrapper.get('#resource-name').setValue('Cliente')
        expect(wrapper.get('label[for="resource-document"] .required-mark').text()).toBe('*')
        expect(wrapper.get('#resource-document').attributes('required')).toBeDefined()
        await wrapper.get('form').trigger('submit')
        expect(post).not.toHaveBeenCalled()
        expect(wrapper.get('#resource-document-error').text()).toBe('O CPF ou CNPJ é obrigatório.')
        expect(wrapper.get('#resource-document').attributes('aria-invalid')).toBe('true')
        expect(wrapper.get('#resource-document').attributes('aria-describedby')).toBe(
            'resource-document-error',
        )
    })

    it.each(['1234567890', '123456789012', '1234567890123', '123456789012345'])(
        'rejects document with invalid digit count: %s',
        async (document) => {
            const post = vi.spyOn(api, 'post')
            render()
            await wrapper.get('#resource-name').setValue('Cliente')
            await wrapper.get('#resource-document').setValue(document)
            await wrapper.get('form').trigger('submit')
            expect(post).not.toHaveBeenCalled()
            expect(wrapper.get('#resource-document-error').text()).toBe(
                'Informe um CPF com 11 dígitos ou um CNPJ com 14 dígitos.',
            )
        },
    )

    it.each([
        ['01234567890', '012.345.678-90'],
        ['01.234.567/0001-89', '01.234.567/0001-89'],
    ])('submits valid CPF/CNPJ: %s', async (document, masked) => {
        const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { id: 10 } })
        render()
        await wrapper.get('#resource-name').setValue('Cliente')
        await wrapper.get('#resource-document').setValue(document)
        expect(wrapper.get('#resource-document').element.value).toBe(masked)
        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(post).toHaveBeenCalledWith(
            'customers',
            expect.objectContaining({ document: masked }),
        )
        expect(wrapper.emitted('saved')[0][0].id).toBe(10)
    })

    it('switches dynamically between CPF and CNPJ masks when typing and deleting', async () => {
        render()
        const input = wrapper.get('#resource-document')
        await input.setValue('01234567890')
        expect(input.element.value).toBe('012.345.678-90')
        await input.setValue(input.element.value + '1')
        expect(input.element.value).toBe('01.234.567/8901')
        await input.setValue(input.element.value + '23')
        expect(input.element.value).toBe('01.234.567/8901-23')
        await input.setValue('01.234.567/890')
        expect(input.element.value).toBe('012.345.678-90')
        await input.setValue('')
        expect(input.element.value).toBe('')
    })

    it('shows API validation errors next to the document and allows correction and retry', async () => {
        const message = 'Informe um CPF com 11 dígitos ou um CNPJ com 14 dígitos.'
        const post = vi
            .spyOn(api, 'post')
            .mockRejectedValueOnce(
                new ApiError(422, {
                    message: 'Revise os campos.',
                    errors: { document: [message] },
                }),
            )
            .mockResolvedValueOnce({ data: { id: 11 } })
        render()
        await wrapper.get('#resource-name').setValue('Cliente')
        await wrapper.get('#resource-document').setValue('01234567890')
        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(wrapper.get('#resource-document-error').text()).toBe(message)
        expect(wrapper.emitted('saved')).toBeUndefined()
        await wrapper.get('#resource-document').setValue('01234567000189')
        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(post).toHaveBeenCalledTimes(2)
        expect(wrapper.find('#resource-document-error').exists()).toBe(false)
        expect(wrapper.emitted('saved')[0][0].id).toBe(11)
    })

    it('allows editing legacy customers without a document', async () => {
        const patch = vi.spyOn(api, 'patch').mockResolvedValue({ data: { id: 7, document: null } })
        render({ id: 7, name: 'Cliente antigo', document: null })
        expect(wrapper.get('#resource-document').attributes('required')).toBeUndefined()
        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(patch).toHaveBeenCalledWith(
            'customers/7',
            expect.objectContaining({ document: null }),
        )
        expect(wrapper.emitted('saved')).toBeDefined()
    })

    it('formats an existing stored document in the edit form', () => {
        render({ id: 7, name: 'Cliente', document: '01234567000189' })
        expect(wrapper.get('#resource-document').element.value).toBe('01.234.567/0001-89')
    })

    it('displays legacy customer details without a document', () => {
        wrapper = mount(RecordDetail, {
            props: {
                config: resources.customers,
                record: { id: 7, name: 'Cliente antigo', document: null },
            },
            global: { stubs: { Teleport: true } },
        })
        const field = wrapper
            .findAll('.detail-grid > div')
            .find((item) => item.get('dt').text() === 'CPF/CNPJ')
        expect(field.get('dd').text()).toBe('Não informado')
    })
})
