import { toDateInput } from '@/utils/format'

export class ApiError extends Error {
    constructor(status, payload = {}) {
        payload = payload && typeof payload === 'object' ? payload : {}
        const messages = {
            0: 'Não foi possível conectar ao servidor. Verifique a conexão e tente novamente.',
            404: 'Registro não encontrado. Ele pode não estar mais disponível.',
            408: 'A geração do PDF excedeu o tempo limite. Tente novamente.',
            409: payload.message || 'Esta operação precisa de confirmação.',
            422: 'Revise os campos destacados e tente novamente.',
        }
        super(messages[status] || 'Não foi possível concluir a operação. Tente novamente.')
        this.status = status
        this.errors = payload.errors || {}
        this.payload = payload
    }
}
export function queryString(params = {}) {
    return new URLSearchParams(
        Object.entries(params).filter(
            ([, value]) => value !== '' && value !== null && value !== undefined,
        ),
    ).toString()
}
export async function request(
    path,
    { method = 'GET', data, params, signal, responseType = 'json', downloadPolicy } = {},
) {
    const query = queryString(params)
    let response
    const timeout = responseType === 'blob' ? 120000 : 20000
    const deadline = AbortSignal.timeout(timeout)
    const pdfTimedOut = () => downloadPolicy?.validatePdf && deadline.aborted
    try {
        response = await fetch('/api/' + path.replace(/^\//, '') + (query ? '?' + query : ''), {
            method,
            signal: signal ? AbortSignal.any([signal, deadline]) : deadline,
            headers: {
                Accept:
                    responseType === 'blob'
                        ? downloadPolicy
                            ? downloadPolicy.contentType + ', application/json'
                            : 'application/pdf, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/json'
                        : 'application/json',
                ...(data !== undefined ? { 'Content-Type': 'application/json' } : {}),
            },
            ...(data !== undefined ? { body: JSON.stringify(data) } : {}),
        })
    } catch (error) {
        if (signal?.aborted) throw error
        throw new ApiError(pdfTimedOut() ? 408 : 0)
    }
    if (response.ok && responseType === 'blob') {
        const contentType = response.headers.get('Content-Type')?.split(';')[0].trim()
        const extension = {
            'application/pdf': 'pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
        }[contentType]
        if (!extension || (downloadPolicy && contentType !== downloadPolicy.contentType))
            throw new ApiError(502)
        try {
            const blob = await response.blob()
            if (!blob.size) throw new Error('Empty report')
            if (downloadPolicy?.validatePdf) {
                const decoder = new TextDecoder()
                const start = decoder.decode(await blob.slice(0, 5).arrayBuffer())
                const end = decoder.decode(await blob.slice(-1024).arrayBuffer())
                if (start !== '%PDF-' || !end.trimEnd().endsWith('%%EOF'))
                    throw new Error('Invalid PDF')
            }
            const suggested = response.headers
                .get('Content-Disposition')
                ?.match(/filename="?([^";]+)"?/i)?.[1]
            const pattern =
                downloadPolicy?.filenamePattern ??
                /^supportflow_relatorio_\d{4}-\d{2}-\d{2}\.(pdf|xlsx)$/
            const filename =
                suggested && pattern.test(suggested) && suggested.endsWith('.' + extension)
                    ? suggested
                    : (downloadPolicy?.fallbackFilename ?? 'supportflow_relatorio.' + extension)
            return { blob, filename }
        } catch (error) {
            if (signal?.aborted) throw error
            throw new ApiError(pdfTimedOut() ? 408 : 502)
        }
    }
    let payload
    try {
        payload = response.status === 204 ? null : await response.json()
    } catch (error) {
        if (signal?.aborted && downloadPolicy?.validatePdf) throw error
        throw new ApiError(pdfTimedOut() ? 408 : response.ok ? 502 : response.status)
    }
    if (!response.ok) throw new ApiError(response.status, payload)
    return payload
}
export const api = {
    get: (path, params, signal) => request(path, { params, signal }),
    post: (path, data = {}, signal) => request(path, { method: 'POST', data, signal }),
    patch: (path, data) => request(path, { method: 'PATCH', data }),
    download: (path, params, signal) => request(path, { params, signal, responseType: 'blob' }),
    exportMetricsPdf: (data, disposition, signal) =>
        request('metrics/export/pdf', {
            method: 'POST',
            data,
            params: { disposition },
            signal,
            responseType: 'blob',
            downloadPolicy: { contentType: 'application/pdf', validatePdf: true },
        }),
    downloadTicketPdf: (id, data, signal) => {
        const number = String(id).padStart(4, '0')
        return request('support-tickets/' + id + '/export/pdf', {
            method: 'POST',
            data,
            signal,
            responseType: 'blob',
            downloadPolicy: {
                contentType: 'application/pdf',
                filenamePattern: /^supportflow-atendimento-\d{4,}-\d{4}-\d{2}-\d{2}\.pdf$/,
                fallbackFilename: `supportflow-atendimento-${number}-${toDateInput(new Date()).slice(0, 10)}.pdf`,
            },
        })
    },
}
