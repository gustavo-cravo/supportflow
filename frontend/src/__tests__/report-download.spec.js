import { afterEach, describe, expect, it, vi } from 'vitest'
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
