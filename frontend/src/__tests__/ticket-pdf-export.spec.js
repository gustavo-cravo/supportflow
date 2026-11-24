import { afterEach, describe, expect, it, vi } from 'vitest'
import { DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import App from '../App.vue'
import { routes } from '../router'
import { api, ApiError } from '../services/api'
import * as downloads from '../utils/download'

const base = {
    id: 3,
    title: 'Falha na consulta',
    description: 'A consulta retornou outra versão.',
    status: 'open',
    created_at: '2026-11-24T15:20:00-03:00',
    system: { id: 1, name: 'Consultas', is_active: true },
    contact: { id: 2, name: 'João Técnico' },
    pending_tasks: [],
    reminders: [],
    follow_up_tickets: [],
}
const vehicle = { id: 4, plate: 'ABC1234', vehicle_line: 'light', brand: { name: 'IVECO' } }
const page = '/atendimentos/3'
const file = {
    blob: new Blob(['%PDF-1.7']),
    filename: 'supportflow-atendimento-0003-2026-11-24.pdf',
}
const flags = {
    include_classification: true,
    include_vehicle: true,
    include_resolution: true,
    include_pending_tasks: true,
    include_reminders: true,
    include_related_tickets: true,
}
let wrapper

async function render(ticket = base) {
    vi.spyOn(api, 'get').mockResolvedValue({ data: ticket })
    const router = createRouter({ history: createMemoryHistory(), routes })
    await router.push(page)
    wrapper = mount(App, { attachTo: document.body, global: { plugins: [router] } })
    await flushPromises()
    return router
}

const dialog = () => new DOMWrapper(document.querySelector('[role="dialog"]'))
const button = (scope, text) => scope.findAll('button').find((item) => item.text() === text)
const checkbox = (key) => dialog().get(`input[name="${key}"]`)
const openExport = () => button(wrapper.get('.heading-actions'), 'Exportar PDF').trigger('click')
const submit = () => dialog().get('form').trigger('submit')

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.restoreAllMocks()
})

describe('exportação individual de atendimentos', () => {
    it.each(['open', 'closed'])(
        'keeps the export button first and available for status %s',
        async (status) => {
            const router = await render({ ...base, status, vehicle })
            const actions = wrapper.get('.heading-actions')
            expect(actions.findAll('a, button').map((item) => item.text())).toEqual(
                status === 'closed'
                    ? ['Exportar PDF', 'Editar']
                    : ['Exportar PDF', 'Editar', 'Encerrar atendimento'],
            )
            const request = vi.spyOn(api, 'downloadTicketPdf')
            const opener = button(actions, 'Exportar PDF')
            opener.element.focus()
            await openExport()
            expect(dialog().text()).toContain('Exportar atendimento')
            expect(dialog().text()).toContain(
                'Selecione as informações que deseja incluir no relatório PDF.',
            )
            for (const key of ['general', 'description']) {
                expect(checkbox(key).element.checked).toBe(true)
                expect(checkbox(key).element.disabled).toBe(true)
            }
            for (const key of Object.keys(flags)) {
                expect(checkbox(key).element.checked).toBe(true)
                expect(checkbox(key).element.disabled).toBe(false)
            }
            await checkbox('include_classification').setValue(false)
            await checkbox('include_resolution').setValue(false)
            expect(checkbox('include_resolution').element.checked).toBe(false)
            await button(dialog(), 'Selecionar tudo').trigger('click')
            expect(checkbox('include_classification').element.checked).toBe(true)
            expect(checkbox('include_resolution').element.checked).toBe(true)
            await button(dialog(), 'Cancelar').trigger('click')
            await flushPromises()
            expect(document.querySelector('[role="dialog"]')).toBeNull()
            expect(document.activeElement).toBe(opener.element)
            expect(router.currentRoute.value.path).toBe(page)
            expect(request).not.toHaveBeenCalled()
        },
    )

    it('disables the vehicle option and identifies missing linked records while keeping available options selected', async () => {
        await render()
        const request = vi.spyOn(api, 'downloadTicketPdf').mockResolvedValue(file)
        const save = vi.spyOn(downloads, 'saveDownload').mockImplementation(() => {})
        await openExport()
        expect(checkbox('include_vehicle').element.disabled).toBe(true)
        for (const hint of [
            'Nenhum veículo vinculado.',
            'Nenhuma pendência associada.',
            'Nenhum lembrete associado.',
            'Sem vínculos anteriores ou retornos.',
        ]) {
            expect(dialog().text()).toContain(hint)
        }
        await button(dialog(), 'Selecionar tudo').trigger('click')
        expect(checkbox('include_vehicle').element.checked).toBe(false)
        await submit()
        await flushPromises()
        expect(request).toHaveBeenCalledWith(
            3,
            { ...flags, include_vehicle: false },
            expect.any(AbortSignal),
        )
        expect(save).toHaveBeenCalledWith(file)
    })

    it('preserves selection after an API error, blocks duplicate submissions and downloads on retry', async () => {
        const router = await render({ ...base, vehicle })
        const error = new ApiError(422, { errors: { include_pending_tasks: ['Opção inválida.'] } })
        let resolveRetry
        const request = vi
            .spyOn(api, 'downloadTicketPdf')
            .mockRejectedValueOnce(error)
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        resolveRetry = resolve
                    }),
            )
        const save = vi.spyOn(downloads, 'saveDownload').mockImplementation(() => {})
        await openExport()
        for (const key of ['include_vehicle', 'include_pending_tasks']) {
            await checkbox(key).setValue(false)
        }
        await submit()
        await flushPromises()
        expect(dialog().get('[role="alert"]').text()).toContain('Opção inválida.')
        expect(checkbox('include_vehicle').element.checked).toBe(false)
        expect(save).not.toHaveBeenCalled()
        await submit()
        expect(dialog().get('form').attributes('aria-busy')).toBe('true')
        expect(button(dialog(), 'Gerando…').element.disabled).toBe(true)
        await submit()
        expect(request).toHaveBeenCalledTimes(2)
        expect(request.mock.calls.map((call) => call[1])).toEqual(
            Array(2).fill({ ...flags, include_vehicle: false, include_pending_tasks: false }),
        )
        resolveRetry(file)
        await flushPromises()
        expect(save).toHaveBeenCalledOnce()
        expect(document.querySelector('[role="dialog"]')).toBeNull()
        expect(router.currentRoute.value.path).toBe(page)
        expect(wrapper.text()).toContain('PDF gerado. O download foi iniciado.')
    })

    it('aborts an in-progress download on cancellation and ignores its late completion', async () => {
        await render()
        let finish
        const request = vi.spyOn(api, 'downloadTicketPdf').mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve
                }),
        )
        const save = vi.spyOn(downloads, 'saveDownload').mockImplementation(() => {})
        await openExport()
        await submit()
        await button(dialog(), 'Cancelar').trigger('click')
        expect(request.mock.calls[0][2].aborted).toBe(true)
        finish(file)
        await flushPromises()
        expect(save).not.toHaveBeenCalled()
        expect(document.querySelector('[role="dialog"]')).toBeNull()
    })
})
