export const problemFields = [
    { name: 'name', label: 'Nome', type: 'text', required: true, max: 255, full: true },
    { name: 'description', label: 'Descrição', type: 'textarea', max: 16000, full: true },
]
export const problemFilters = [
    { name: 'q', label: 'Pesquisar', type: 'search', placeholder: 'Pesquisar nome ou descrição…' },
    {
        name: 'is_active',
        label: 'Situação',
        type: 'select',
        options: [
            { value: '1', label: 'Ativas' },
            { value: '0', label: 'Inativas' },
        ],
    },
    { name: 'system_id', label: 'Sistema', type: 'relation', endpoint: 'systems' },
]
export const systemNames = (systems = []) =>
    systems.map((system) => system.name + (system.is_active ? '' : ' · Inativo')).join(', ') ||
    'Nenhum sistema habilitado'
