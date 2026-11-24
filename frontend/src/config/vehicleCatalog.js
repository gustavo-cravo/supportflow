export const vehicleLines = [
    { value: 'light', label: 'Linha leve' },
    { value: 'heavy', label: 'Linha pesada' },
]

export const vehicleLineLabel = (line) =>
    vehicleLines.find((option) => option.value === line)?.label ?? 'Linha não informada'

export const vehicleBrands = {
    endpoint: 'vehicle-brands',
    singular: 'marca',
    newLabel: 'Nova marca',
    icon: 'layers',
    wide: false,
    showInstructions: false,
    fields: [{ name: 'name', label: 'Nome da marca', required: true, full: true }],
    filters: [{ name: 'q', label: 'Pesquisar', type: 'search', placeholder: 'Nome da marca' }],
    columns: [
        { key: 'name', label: 'Marca', primary: true },
        { key: 'vehicles_count', label: 'Veículos vinculados' },
    ],
}

export const vehicles = {
    endpoint: 'vehicles',
    singular: 'veículo',
    newLabel: 'Novo veículo',
    icon: 'car',
    showInstructions: false,
    fields: [
        {
            name: 'brand_id',
            label: 'Marca do veículo',
            type: 'relation',
            endpoint: vehicleBrands.endpoint,
            required: true,
        },
        {
            name: 'vehicle_line',
            label: 'Linha veicular',
            type: 'select',
            options: vehicleLines,
            emptyLabel: 'Selecione a linha',
            required: true,
        },
        {
            name: 'model_description',
            label: 'Descrição do modelo',
            type: 'textarea',
            full: true,
        },
        { name: 'plate', label: 'Placa', mask: 'plate', placeholder: 'ABC1D23', required: true },
    ],
    filters: [
        { name: 'q', label: 'Pesquisar', type: 'search', placeholder: 'Placa, marca ou modelo' },
        {
            name: 'brand_id',
            label: 'Marca',
            type: 'relation',
            endpoint: vehicleBrands.endpoint,
            placeholder: 'Todas as marcas',
        },
        {
            name: 'vehicle_line',
            label: 'Linha veicular',
            type: 'select',
            options: vehicleLines,
            emptyLabel: 'Todas as linhas',
        },
    ],
    columns: [
        { key: 'plate', label: 'Placa', primary: true },
        { key: 'brand', label: 'Marca', type: 'entity' },
        { key: 'model_description', label: 'Modelo' },
        { key: 'vehicle_line', label: 'Linha veicular' },
        { key: 'support_tickets_count', label: 'Atendimentos vinculados' },
    ],
}
