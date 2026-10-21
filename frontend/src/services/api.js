export class ApiError extends Error {
    constructor(status, payload = {}) {
        payload = payload && typeof payload === 'object' ? payload : {}
        const messages = {
            0: 'Não foi possível conectar ao servidor. Verifique a conexão e tente novamente.',
            404: 'Registro não encontrado. Ele pode não estar mais disponível.',
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
export async function request(path, { method = 'GET', data, params, signal } = {}) {
    const query = queryString(params)
    let response
    try {
        response = await fetch('/api/' + path.replace(/^\//, '') + (query ? '?' + query : ''), {
            method,
            signal: signal
                ? AbortSignal.any([signal, AbortSignal.timeout(20000)])
                : AbortSignal.timeout(20000),
            headers: {
                Accept: 'application/json',
                ...(data !== undefined ? { 'Content-Type': 'application/json' } : {}),
            },
            ...(data !== undefined ? { body: JSON.stringify(data) } : {}),
        })
    } catch (error) {
        if (signal?.aborted) throw error
        throw new ApiError(0)
    }
    let payload
    try {
        payload = response.status === 204 ? null : await response.json()
    } catch {
        throw new ApiError(response.ok ? 502 : response.status)
    }
    if (!response.ok) throw new ApiError(response.status, payload)
    return payload
}
export const api = {
    get: (path, params, signal) => request(path, { params, signal }),
    post: (path, data = {}) => request(path, { method: 'POST', data }),
    patch: (path, data) => request(path, { method: 'PATCH', data }),
}
