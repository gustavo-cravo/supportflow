import { displayStatus } from '@/utils/format'
import { vehicleLineLabel } from './vehicleCatalog'

export const metricPeriods = [
    { value: 'this_month', label: 'Este mês' },
    { value: 'last_30_days', label: 'Últimos 30 dias' },
    { value: 'last_3_months', label: 'Últimos 3 meses' },
    { value: 'last_6_months', label: 'Últimos 6 meses' },
    { value: 'this_year', label: 'Este ano' },
    { value: 'all', label: 'Todo o histórico' },
    { value: 'custom', label: 'Período personalizado' },
]
export const defaultMetricFilters = () => ({
    period: 'last_6_months',
    system_id: '',
    status: 'all',
    category: '',
    subcategory: '',
    date_from: '',
    date_to: '',
})
export function metricParams(filters) {
    const params = { period: filters.period, status: filters.status, classification: 'all' }
    if (filters.system_id) params.system_id = Number(filters.system_id)
    if (filters.category === 'unclassified') params.classification = 'unclassified'
    else if (filters.category) {
        params.problem_category_id = Number(filters.category)
        if (filters.subcategory === 'without_subcategory')
            params.classification = 'without_subcategory'
        else if (filters.subcategory) params.problem_subcategory_id = Number(filters.subcategory)
    }
    if (filters.period === 'custom') {
        params.date_from = filters.date_from
        params.date_to = filters.date_to
    }
    return params
}
export const metricNumber = (value) => new Intl.NumberFormat('pt-BR').format(value)
export const metricPercentage = (value) =>
    new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 }).format(value) + '%'
export function metricMonth(value) {
    const [year, month] = value.split('-').map(Number)
    const date = new Date(0)
    date.setUTCFullYear(year, month - 1, 1)
    return new Intl.DateTimeFormat('pt-BR', {
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(date)
}
export function metricDrilldown(applied, row, subcategory = false) {
    const params = { ...applied }
    if (!row) return params
    if (row.problem_category_id === null) {
        delete params.problem_category_id
        delete params.problem_subcategory_id
        params.classification = 'unclassified'
    } else {
        params.problem_category_id = row.problem_category_id
        if (subcategory) {
            if (row.problem_subcategory_id === null) {
                delete params.problem_subcategory_id
                params.classification = 'without_subcategory'
            } else {
                params.problem_subcategory_id = row.problem_subcategory_id
                params.classification = 'all'
            }
        }
    }
    return params
}

export function metricFilterName(rows, id, fallback = '') {
    return (id && rows?.find((row) => row.id === Number(id))?.name) || fallback
}

export function metricLabelWithoutPlate(label, plate) {
    if (!label || !plate) return label
    const compact = (text) => text.replace(/[^\p{L}\p{N}]/gu, '').toUpperCase()
    const filter = compact(plate)
    return filter && compact(label).includes(filter) ? '' : label
}

export function describeMetricFilters(applied, resolved, catalog, vehicleDetails) {
    const period = metricPeriods.find((row) => row.value === applied.period)?.label
    const dates = resolved.date_from
        ? `${resolved.date_from.split('-').reverse().join('/')} a ${resolved.date_to.split('-').reverse().join('/')}`
        : ''
    const vehicle = [
        applied.vehicle_presence === 'with' && 'Com veículo',
        applied.vehicle_presence === 'without' && 'Sem veículo',
        applied.vehicle_plate && 'Filtro de placa aplicado',
        applied.vehicle_brand_id && 'Filtro de marca aplicado',
        applied.vehicle_model && 'Filtro de descrição do modelo aplicado',
        applied.vehicle_line === 'light' && 'Linha leve',
        applied.vehicle_line === 'heavy' && 'Linha pesada',
    ].filter(Boolean)
    const plate = vehicleDetails && (resolved.vehicle_plate ?? applied.vehicle_plate)
    const withoutPlate = (text) => metricLabelWithoutPlate(text, plate)
    const named = (rows, id, fallback) => withoutPlate(metricFilterName(rows, id)) || fallback
    return [
        { label: 'Período', value: [period, dates].filter(Boolean).join(' · ') },
        {
            label: 'Sistema',
            value: applied.system_id
                ? named(catalog.systems, applied.system_id, 'Sistema selecionado')
                : 'Todos os sistemas',
        },
        {
            label: 'Status',
            value:
                applied.status === 'all'
                    ? 'Todos os status'
                    : applied.status === 'not_closed'
                      ? 'Não encerrados'
                      : displayStatus(applied.status),
        },
        {
            label: 'Categoria',
            value:
                applied.classification === 'unclassified'
                    ? 'Sem classificação'
                    : applied.problem_category_id
                      ? named(
                            catalog.categories,
                            applied.problem_category_id,
                            'Categoria selecionada',
                        )
                      : 'Todas as categorias',
        },
        {
            label: 'Subcategoria',
            value:
                applied.classification === 'without_subcategory'
                    ? 'Sem subcategoria'
                    : applied.problem_subcategory_id
                      ? named(
                            catalog.subcategories,
                            applied.problem_subcategory_id,
                            'Subcategoria selecionada',
                        )
                      : 'Todas as subcategorias aplicáveis',
        },
        ...(vehicleDetails
            ? [
                  applied.vehicle_presence && {
                      label: 'Identificação veicular',
                      value: applied.vehicle_presence === 'with' ? 'Com veículo' : 'Sem veículo',
                  },
                  applied.vehicle_brand_id && {
                      label: 'Marca',
                      value: withoutPlate(vehicleDetails.brandName) || 'Marca selecionada',
                  },
                  applied.vehicle_model && {
                      label: 'Modelo',
                      value:
                          withoutPlate(applied.vehicle_model) ||
                          'Filtro de descrição do modelo aplicado',
                  },
                  applied.vehicle_line && {
                      label: 'Linha veicular',
                      value: vehicleLineLabel(applied.vehicle_line),
                  },
                  applied.vehicle_plate && { label: 'Placa', value: 'Filtro de placa aplicado' },
              ].filter(Boolean)
            : vehicle.length
              ? [{ label: 'Filtros veiculares', value: vehicle.join(' · ') }]
              : []),
    ]
}
