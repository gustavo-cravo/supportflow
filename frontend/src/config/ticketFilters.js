import { vehicleLines } from '@/config/vehicleCatalog'
import { ticketStatuses } from '@/utils/format'

const system = { name: 'system_id', label: 'Sistema', type: 'relation', endpoint: 'systems' }
const status = { name: 'status', label: 'Status', type: 'select', options: ticketStatuses }
const subcategory = {
    name: 'problem_subcategory_id',
    label: 'Subcategoria',
    type: 'relation',
    endpoint: 'problem-subcategories',
}
const period = [
    { name: 'created_from', label: 'Abertura · de', type: 'date' },
    { name: 'created_to', label: 'Abertura · até', type: 'date' },
]

export const ticketFilters = [
    {
        name: 'q',
        label: 'Pesquisar',
        type: 'search',
        placeholder: 'Buscar por título ou descrição…',
    },
    status,
    system,
    { name: 'contact_id', label: 'Contato', type: 'relation', endpoint: 'contacts' },
    { name: 'customer_id', label: 'Cliente', type: 'relation', endpoint: 'customers' },
    subcategory,
    ...period,
]

export const historyFilters = [
    system,
    {
        name: 'problem_category_id',
        label: 'Categoria',
        type: 'relation',
        endpoint: 'problem-categories',
    },
    subcategory,
    status,
    ...period,
]

const withoutVehicle = (values) => values.vehicle_presence === 'without'

export const vehicleFilters = [
    {
        name: 'vehicle_presence',
        label: 'Identificação veicular',
        type: 'select',
        emptyLabel: 'Todos os atendimentos',
        options: [
            { value: 'with', label: 'Somente atendimentos com veículo' },
            { value: 'without', label: 'Somente atendimentos sem veículo' },
        ],
    },
    {
        name: 'vehicle_plate',
        label: 'Placa',
        type: 'text',
        placeholder: 'Placa completa ou parcial',
        disabledWhen: withoutVehicle,
    },
    {
        name: 'vehicle_brand_id',
        label: 'Marca',
        type: 'relation',
        endpoint: 'vehicle-brands',
        placeholder: 'Todas as marcas',
        disabledWhen: withoutVehicle,
    },
    {
        name: 'vehicle_model',
        label: 'Descrição do modelo',
        type: 'text',
        placeholder: 'Nome ou trecho do modelo',
        disabledWhen: withoutVehicle,
    },
    {
        name: 'vehicle_line',
        label: 'Linha veicular',
        type: 'select',
        emptyLabel: 'Todas as linhas',
        options: vehicleLines,
        disabledWhen: withoutVehicle,
    },
]

export function queryFilters(query, fields) {
    return Object.fromEntries(
        fields.filter(({ name }) => query[name]).map(({ name }) => [name, query[name]]),
    )
}
