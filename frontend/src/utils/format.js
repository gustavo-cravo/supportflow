export const timezone = 'America/Sao_Paulo'
export const ticketStatuses = [
    { value: 'open', label: 'Aberto' },
    { value: 'in_progress', label: 'Em andamento' },
    { value: 'waiting', label: 'Aguardando retorno' },
    { value: 'resolved', label: 'Resolvido' },
    { value: 'closed', label: 'Encerrado' },
]
export const taskStatuses = [
    { value: 'pending', label: 'Pendente' },
    { value: 'completed', label: 'Concluída' },
]
export const verificationSources = [
    { value: 'customer', label: 'Cliente' },
    { value: 'responsible_department', label: 'Setor responsável' },
]
export function formatDate(value, includeTime = true) {
    if (!value) return '—'
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return '—'
    return new Intl.DateTimeFormat('pt-BR', {
        timeZone: timezone,
        dateStyle: 'short',
        ...(includeTime ? { timeStyle: 'short' } : {}),
    }).format(date)
}
export function toDateInput(value) {
    if (!value) return ''
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return ''
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-CA', {
            timeZone: timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hourCycle: 'h23',
        })
            .formatToParts(date)
            .map((part) => [part.type, part.value]),
    )
    return (
        parts.year +
        '-' +
        parts.month +
        '-' +
        parts.day +
        'T' +
        parts.hour +
        ':' +
        parts.minute +
        ':' +
        parts.second
    )
}
export function toApiDate(value) {
    if (!value) return null
    return value.replace('T', ' ') + (value.length === 16 ? ':00' : '')
}
export function displayStatus(value) {
    return (
        [...ticketStatuses, ...taskStatuses, ...verificationSources].find(
            (item) => item.value === value,
        )?.label ||
        value ||
        '—'
    )
}
export function fieldError(errors, name, label) {
    return (errors?.[name]?.[0] || '').replaceAll(name, label.toLowerCase())
}
export const emptyToNull = (value) => (value === '' ? null : value)

export function formatDocument(value) {
    const digits = String(value ?? '').replace(/\D/g, '')
    return digits.length <= 11
        ? digits
              .replace(/^(\d{3})(\d)/, '$1.$2')
              .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
              .replace(/(\d{3})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3-$4')
        : digits
              .replace(/^(\d{2})(\d)/, '$1.$2')
              .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
              .replace(/(\d{3})\.(\d{3})(\d)/, '$1.$2/$3')
              .replace(/(\/\d{4})(\d)/, '$1-$2')
}
