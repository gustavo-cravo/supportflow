import { toApiDate, toDateInput } from './format'
export function resolutionValues(record = {}) {
    return {
        resolution: record.resolution || '',
        verification_source: record.verification_source || '',
        verified_at: toDateInput(record.verified_at),
        verified_by: record.verified_by || '',
        verification_notes: record.verification_notes || '',
    }
}
export function resolutionPayload(values) {
    return {
        resolution: values.resolution || null,
        verification_source: values.verification_source || null,
        verified_at: toApiDate(values.verified_at),
        verified_by: values.verified_by || null,
        verification_notes: values.verification_notes || null,
    }
}
export function validateResolution(values) {
    if (Boolean(values.verification_source) === Boolean(values.verified_at)) return {}
    return {
        verification_source: ['Informe a fonte e a data juntas, ou deixe ambas vazias.'],
        verified_at: ['Informe a fonte e a data juntas, ou deixe ambas vazias.'],
    }
}
