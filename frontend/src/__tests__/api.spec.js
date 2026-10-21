import { afterEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError, queryString } from '../services/api'
import { formPayload, initialValues, validateFields } from '../utils/forms'
import { toApiDate, toDateInput, formatDate } from '../utils/format'
import { validateResolution } from '../utils/resolution'

afterEach(() => vi.unstubAllGlobals())
const response = (status, body) => ({
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
})

describe('HTTP API contract', () => {
    it('preserves pagination and keeps zero and false query parameters', async () => {
        const fetcher = vi
            .fn()
            .mockResolvedValue(
                response(200, { data: [{ id: 1 }], meta: { current_page: 2, total: 20 } }),
            )
        vi.stubGlobal('fetch', fetcher)
        const result = await api.get('systems', {
            page: 2,
            is_active: 0,
            empty: '',
            optional: null,
        })
        expect(result.meta.total).toBe(20)
        expect(fetcher.mock.calls[0][0]).toBe('/api/systems?page=2&is_active=0')
        expect(queryString({ active: false })).toBe('active=false')
    })
    it.each([404, 409, 422, 500])(
        'retains HTTP %i errors without leaking server traces',
        async (status) => {
            const payload = {
                message: 'Backend internal message',
                errors: { title: ['Título obrigatório'] },
                code: 'open_pending_tasks',
            }
            vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response(status, payload)))
            const error = await api.post('support-tickets', {}).catch((value) => value)
            expect(error).toBeInstanceOf(ApiError)
            expect(error.status).toBe(status)
            expect(error.errors.title).toEqual(['Título obrigatório'])
            if (status === 409) expect(error.payload.code).toBe('open_pending_tasks')
            if (status === 500) expect(error.message).not.toContain('Backend internal')
        },
    )
    it('serializes JSON writes and accepts a bodyless 204', async () => {
        const fetcher = vi.fn().mockResolvedValue(response(204, null))
        vi.stubGlobal('fetch', fetcher)
        expect(await api.patch('systems/1', { is_active: false })).toBeNull()
        expect(fetcher.mock.calls[0][1].body).toBe('{"is_active":false}')
    })
    it('reports connection failures', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('network')))
        await expect(api.get('contacts')).rejects.toMatchObject({ status: 0 })
    })
    it('times out list/detail requests even when the caller supplies a cancellation signal', async () => {
        vi.useFakeTimers()
        const timeoutController = new AbortController()
        const timeout = vi.spyOn(AbortSignal, 'timeout').mockImplementation(() => {
            setTimeout(() => timeoutController.abort(), 20000)
            return timeoutController.signal
        })
        vi.stubGlobal(
            'fetch',
            vi.fn(
                (_url, { signal }) =>
                    new Promise((_resolve, reject) => {
                        signal.addEventListener('abort', () => reject(signal.reason), {
                            once: true,
                        })
                    }),
            ),
        )
        const caller = new AbortController()
        const result = api.get('systems', {}, caller.signal).catch((error) => error)
        try {
            await vi.advanceTimersByTimeAsync(20000)
            expect(await result).toMatchObject({ status: 0 })
            expect(caller.signal.aborted).toBe(false)
        } finally {
            timeout.mockRestore()
            vi.useRealTimers()
        }
    })
    it('preserves intentional cancellation instead of reporting a connection failure', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(
                (_url, { signal }) =>
                    new Promise((_resolve, reject) => {
                        signal.addEventListener('abort', () => reject(signal.reason), {
                            once: true,
                        })
                    }),
            ),
        )
        const caller = new AbortController()
        const result = api.get('systems', {}, caller.signal).catch((error) => error)
        caller.abort()
        expect(await result).toBe(caller.signal.reason)
    })
    it('reports invalid proxy responses as errors', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                status: 200,
                json: async () => {
                    throw new Error('HTML')
                },
            }),
        )
        await expect(api.get('contacts')).rejects.toMatchObject({ status: 502 })
    })
})

describe('dates and forms', () => {
    it('uses Sao Paulo wall time regardless of the computer timezone and preserves seconds', () => {
        expect(toDateInput('2026-10-22T01:15:32Z')).toBe('2026-10-21T22:15:32')
        expect(toApiDate('2026-10-21T22:15:32')).toBe('2026-10-21 22:15:32')
        expect(toApiDate('2026-10-21T22:15')).toBe('2026-10-21 22:15:00')
        expect(formatDate('2026-10-22T01:15:32Z')).toContain('21/10/2026')
        expect(toApiDate('')).toBeNull()
    })
    it('validates required fields, email and paired verification data', () => {
        expect(
            validateFields([{ name: 'name', label: 'Nome', required: true }], { name: ' ' }),
        ).toHaveProperty('name')
        expect(
            validateFields([{ name: 'email', label: 'E-mail', type: 'email' }], {
                email: 'invalid',
            }),
        ).toHaveProperty('email')
        expect(
            validateResolution({ verification_source: 'customer', verified_at: '' }),
        ).toHaveProperty('verified_at')
        expect(validateResolution({ verification_source: '', verified_at: '' })).toEqual({})
    })
    it('keeps booleans and optional associations in the payload and excludes computed fields', () => {
        const fields = [
            { name: 'is_active', type: 'checkbox', default: true },
            { name: 'system_id', type: 'relation' },
        ]
        expect(initialValues(fields)).toEqual({ is_active: true, system_id: null })
        expect(
            formPayload(fields, { is_active: false, system_id: '', created_at: 'ignored' }),
        ).toEqual({ is_active: false, system_id: null })
    })
})
