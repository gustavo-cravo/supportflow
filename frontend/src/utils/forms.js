import { toApiDate, toDateInput } from './format'
export function initialValues(fields, record = {}) {
    return Object.fromEntries(
        fields.map((field) => [
            field.name,
            field.type === 'datetime-local'
                ? toDateInput(record[field.name])
                : (record[field.name] ?? field.default ?? (field.type === 'relation' ? null : '')),
        ]),
    )
}
export function formPayload(fields, values) {
    return Object.fromEntries(
        fields.map((field) => [
            field.name,
            field.type === 'datetime-local'
                ? toApiDate(values[field.name])
                : values[field.name] === ''
                  ? null
                  : values[field.name],
        ]),
    )
}
export function validateFields(fields, values) {
    const errors = {}
    for (const field of fields) {
        const value = values[field.name]
        if (
            field.required &&
            (value === null || value === undefined || String(value).trim() === '')
        )
            errors[field.name] = ['Informe ' + field.label.toLowerCase() + '.']
        else if (field.max && String(value || '').length > field.max)
            errors[field.name] = ['Use no máximo ' + field.max + ' caracteres.']
        else if (field.type === 'email' && value && !/^[^\s@]+@[^\s@]+$/.test(value))
            errors[field.name] = ['Informe um e-mail válido.']
    }
    return errors
}
