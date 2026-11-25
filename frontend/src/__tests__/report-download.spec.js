import { afterEach, describe, expect, it, vi } from 'vitest'
import { Blob as BinaryBlob } from 'node:buffer'
import { api } from '../services/api'
import { saveDownload } from '../utils/download'
afterEach(() => {
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
    vi.useRealTimers()
})
const fileResponse = (type, extension) => ({
    ok: true,
    status: 200,
    headers: new Headers({
        'Content-Type': type,
        'Content-Disposition': `attachment; filename="supportflow_relatorio_2026-11-23.${extension}"`,
    }),
    blob: async () => new Blob(['actual-report'], { type }),
})

describe('binary API downloads', () => {
    it.each([
        ['application/pdf', 'pdf'],
        ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'],
    ])('accepts %s and preserves safe server filenames', async (type, extension) => {
        const fetcher = vi.fn().mockResolvedValue(fileResponse(type, extension))
        vi.stubGlobal('fetch', fetcher)
        const timeout = vi.spyOn(AbortSignal, 'timeout')
        const file = await api.download('metrics/export', { period: 'all', format: extension })
        expect(file.filename).toBe(`supportflow_relatorio_2026-11-23.${extension}`)
        expect(file.blob.size).toBeGreaterThan(0)
        expect(fetcher.mock.calls[0][0]).toBe(`/api/metrics/export?period=all&format=${extension}`)
        expect(fetcher.mock.calls[0][1].method).toBe('GET')
        expect(timeout).toHaveBeenCalledWith(120000)
    })
    it('rejects successful HTML responses and empty files instead of saving them as reports', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fileResponse('text/html', 'pdf')))
        await expect(api.download('metrics/export')).rejects.toMatchObject({ status: 502 })
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ...fileResponse('application/pdf', 'pdf'),
                blob: async () => new Blob([]),
            }),
        )
        await expect(api.download('metrics/export')).rejects.toMatchObject({ status: 502 })
    })
    it('retains API JSON validation errors on failed binary responses', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: false,
                status: 422,
                json: async () => ({ errors: { period: ['Período inválido.'] } }),
            }),
        )
        await expect(api.download('metrics/export')).rejects.toMatchObject({
            status: 422,
            errors: { period: ['Período inválido.'] },
        })
    })
    it('does not accept path-like or mismatched download filenames', async () => {
        for (const name of ['../../secret.pdf', 'supportflow_relatorio_2026-11-23.xlsx']) {
            const response = fileResponse('application/pdf', 'pdf')
            response.headers.set('Content-Disposition', `attachment; filename="${name}"`)
            vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response))
            expect((await api.download('metrics/export')).filename).toBe(
                'supportflow_relatorio.pdf',
            )
        }
    })
    it('preserves intentional request cancellation', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(
                (_url, { signal }) =>
                    new Promise((_resolve, reject) =>
                        signal.addEventListener('abort', () => reject(signal.reason), {
                            once: true,
                        }),
                    ),
            ),
        )
        const caller = new AbortController()
        const result = api.download('metrics/export', {}, caller.signal).catch((error) => error)
        caller.abort()
        expect(await result).toBe(caller.signal.reason)
    })

    it('posts section flags for an individual PDF and preserves its safe filename', async () => {
        const response = fileResponse('application/pdf', 'pdf')
        response.headers.set(
            'Content-Disposition',
            'attachment; filename="supportflow-atendimento-0003-2026-11-24.pdf"',
        )
        const fetcher = vi.fn().mockResolvedValue(response)
        vi.stubGlobal('fetch', fetcher)
        const sections = { include_vehicle: false, include_resolution: true }
        const file = await api.downloadTicketPdf(3, sections)
        expect(file.filename).toBe('supportflow-atendimento-0003-2026-11-24.pdf')
        expect(fetcher).toHaveBeenCalledWith(
            '/api/support-tickets/3/export/pdf',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify(sections),
                headers: {
                    Accept: 'application/pdf, application/json',
                    'Content-Type': 'application/json',
                },
            }),
        )
    })

    it('rejects an Excel response for a ticket and uses a safe São Paulo date for an invalid attachment name', async () => {
        vi.useFakeTimers()
        vi.setSystemTime(new Date('2026-11-25T02:30:00Z'))
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue(
                    fileResponse(
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'xlsx',
                    ),
                ),
        )
        await expect(api.downloadTicketPdf(3, {})).rejects.toMatchObject({ status: 502 })
        const pdf = fileResponse('application/pdf', 'pdf')
        pdf.headers.set('Content-Disposition', 'attachment; filename="../../privado.pdf"')
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(pdf))
        expect((await api.downloadTicketPdf(3, {})).filename).toBe(
            'supportflow-atendimento-0003-2026-11-24.pdf',
        )
    })

    it('posts the filters and configuration and accepts only a PDF with the safe report name', async () => {
        const payload = {
            period: 'all',
            configuration: { sections: { summary: { enabled: true } } },
        }
        const response = fileResponse('application/pdf', 'pdf')
        const pdf = new BinaryBlob(['%PDF-1.7\ncontent\n%%EOF\n'], { type: 'application/pdf' })
        response.blob = async () => pdf
        response.headers.set(
            'Content-Disposition',
            'inline; filename="supportflow_relatorio_2026-11-25.pdf"',
        )
        const fetcher = vi.fn().mockResolvedValue(response)
        vi.stubGlobal('fetch', fetcher)
        expect(await api.exportMetricsPdf(payload, 'inline')).toEqual({
            blob: pdf,
            filename: 'supportflow_relatorio_2026-11-25.pdf',
        })
        expect(fetcher).toHaveBeenCalledWith(
            '/api/metrics/export/pdf?disposition=inline',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify(payload),
                headers: {
                    Accept: 'application/pdf, application/json',
                    'Content-Type': 'application/json',
                },
            }),
        )
        response.headers.set('Content-Disposition', 'attachment; filename="../../HFS4456.pdf"')
        expect((await api.exportMetricsPdf(payload, 'attachment')).filename).toBe(
            'supportflow_relatorio.pdf',
        )
    })

    it.each(['', '<html>error</html>', '%PDF-1.7\nincomplete'])(
        'rejects empty or invalid configured PDF bodies even with a PDF MIME type',
        async (body) => {
            const response = fileResponse('application/pdf', 'pdf')
            response.blob = async () => new BinaryBlob([body], { type: 'application/pdf' })
            vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response))
            await expect(api.exportMetricsPdf({}, 'inline')).rejects.toMatchObject({ status: 502 })
        },
    )

    it('reports the configured PDF timeout separately from a transport failure', async () => {
        const deadline = new AbortController()
        vi.spyOn(AbortSignal, 'timeout').mockReturnValue(deadline.signal)
        vi.stubGlobal(
            'fetch',
            vi.fn(
                (_url, { signal }) =>
                    new Promise((_resolve, reject) =>
                        signal.addEventListener('abort', () => reject(signal.reason), {
                            once: true,
                        }),
                    ),
            ),
        )
        const result = api.exportMetricsPdf({}, 'inline')
        deadline.abort()
        await expect(result).rejects.toMatchObject({
            status: 408,
            message: expect.stringContaining('tempo limite'),
        })
    })

    it('posts the analytical configuration and returns the original binary with the actual selection count', async () => {
        const payload = {
            period: 'all',
            configuration: { selection: { mode: 'only', ids: [42] } },
        }
        const response = fileResponse('application/pdf', 'pdf')
        const blob = new BinaryBlob(['%PDF-1.7\nconteúdo analítico\n%%EOF\n'], {
            type: 'application/pdf',
        })
        response.blob = async () => blob
        response.headers.set('X-Report-Selected-Count', '1')
        const filename = 'supportflow-relatorio-analitico-2026-11-25.pdf'
        response.headers.set('Content-Disposition', `inline; filename="${filename}"`)
        const fetcher = vi.fn().mockResolvedValue(response)
        vi.stubGlobal('fetch', fetcher)

        expect(await api.exportAnalyticalPdf(payload, 'inline')).toEqual({
            blob,
            filename,
            selectedCount: 1,
        })
        expect(fetcher).toHaveBeenCalledExactlyOnceWith(
            '/api/metrics/analytical-report/pdf?disposition=inline',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify(payload),
            }),
        )
        response.headers.set('Content-Disposition', 'attachment; filename="../../individual.pdf"')
        expect((await api.exportAnalyticalPdf(payload, 'attachment')).filename).toBe(
            'supportflow-relatorio-analitico.pdf',
        )
    })

    it('rejects missing count, wrong MIME and corrupt analytical content and decodes JSON field errors', async () => {
        const response = fileResponse('application/pdf', 'pdf')
        response.blob = async () => new BinaryBlob(['%PDF-1.7\nvalid\n%%EOF'])
        const fetcher = vi.fn().mockResolvedValue(response)
        vi.stubGlobal('fetch', fetcher)
        await expect(api.exportAnalyticalPdf({}, 'inline')).rejects.toMatchObject({ status: 502 })
        response.headers.set('X-Report-Selected-Count', '2')
        response.headers.set('Content-Type', 'text/html')
        await expect(api.exportAnalyticalPdf({}, 'inline')).rejects.toMatchObject({ status: 502 })
        response.headers.set('Content-Type', 'application/pdf')
        response.blob = async () => new BinaryBlob(['%PDF-1.7\nincomplete'])
        await expect(api.exportAnalyticalPdf({}, 'inline')).rejects.toMatchObject({ status: 502 })
        fetcher.mockResolvedValueOnce({
            ok: false,
            status: 422,
            json: async () => ({
                errors: { 'configuration.grouping': ['Agrupamento inválido.'] },
            }),
        })
        await expect(api.exportAnalyticalPdf({}, 'attachment')).rejects.toMatchObject({
            status: 422,
            errors: { 'configuration.grouping': ['Agrupamento inválido.'] },
        })
    })
})

it('initiates a browser download and then removes the link and releases the object URL', async () => {
    vi.useFakeTimers()
    const createObjectURL = vi.fn().mockReturnValue('blob:report-test'),
        revokeObjectURL = vi.fn()
    vi.stubGlobal('URL', { createObjectURL, revokeObjectURL })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
        expect(this.download).toBe('supportflow_relatorio_2026-11-23.xlsx')
        expect(this.href).toBe('blob:report-test')
        expect(document.body.contains(this)).toBe(true)
    })
    saveDownload({ blob: new Blob(['file']), filename: 'supportflow_relatorio_2026-11-23.xlsx' })
    expect(click).toHaveBeenCalledOnce()
    expect(document.querySelector('a[download]')).toBeNull()
    expect(revokeObjectURL).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1000)
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:report-test')
})
