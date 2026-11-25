<?php

namespace App\Support;

/**
 * @phpstan-type Field array{label: string, type: string, column: string, path?: string, with?: string, vehicle_column?: string, empty: string, smart?: string}
 * @phpstan-type Section array{label: string, fields: array<string, Field>}
 */
final class AnalyticalTicketReportCatalog
{
    public const TITLE_LIMIT = 120;

    public const SUBTITLE_LIMIT = 180;

    public const VEHICLE = 'vehicle';

    public const SELECTION_MODES = ['all' => 'Todos no recorte', 'all_except' => 'Todos, exceto os excluídos', 'only' => 'Somente os selecionados'];

    public const ORDERS = ['newest' => 'Mais recentes primeiro', 'oldest' => 'Mais antigos primeiro', 'title' => 'Ordem alfabética do título'];

    public const EMPTY_MODES = ['smart' => 'Tratamento inteligente', 'show' => 'Exibir todos os campos selecionados', 'hide' => 'Ocultar campos vazios'];

    public const PAGINATION = ['automatic' => 'Automática', 'compact' => 'Compacta', 'individual' => 'Individual'];

    public const COLUMNS = ['automatic' => 'Automáticas', 'one' => 'Uma coluna', 'two' => 'Duas colunas'];

    public const SPACING = ['compact' => 'Compacto', 'normal' => 'Normal', 'expanded' => 'Ampliado'];

    public const TITLE_MODES = ['automatic' => 'Automático', 'custom' => 'Personalizado'];

    public const GROUPINGS = [
        'none' => ['label' => 'Sem agrupamento', 'required_fields' => []],
        'system' => ['label' => 'Por sistema', 'required_fields' => ['system']],
        'category' => ['label' => 'Por categoria', 'required_fields' => ['problem_category']],
        'brand' => ['label' => 'Por marca veicular', 'required_fields' => ['vehicle_brand']],
        'model' => ['label' => 'Por modelo veicular', 'required_fields' => ['vehicle_brand', 'vehicle_model']],
    ];

    public const NO_VEHICLE = 'Sem veículo vinculado';

    /** @return array<string, Section> */
    public static function sections(): array
    {
        return [
            'ticket' => ['label' => 'Informações do atendimento', 'fields' => [
                'title' => ['label' => 'Título', 'type' => 'short', 'column' => 'title', 'empty' => 'Título não informado'],
                'system' => ['label' => 'Sistema', 'type' => 'short', 'column' => 'system_id', 'path' => 'system.name', 'with' => 'system:id,name', 'empty' => 'Sistema não informado'],
                'contact' => ['label' => 'Contato', 'type' => 'short', 'column' => 'contact_id', 'path' => 'contact.name', 'with' => 'contact:id,name', 'empty' => 'Contato não informado'],
                'customer' => ['label' => 'Cliente', 'type' => 'short', 'column' => 'customer_id', 'path' => 'customer.name', 'with' => 'customer:id,name', 'empty' => 'Cliente não informado'],
                'previous_ticket' => ['label' => 'Atendimento anterior', 'type' => 'reference', 'column' => 'previous_ticket_id', 'with' => 'previousTicket:id,title', 'empty' => 'Sem atendimento anterior vinculado'],
                'description' => ['label' => 'Descrição do problema', 'type' => 'long_text', 'column' => 'description', 'empty' => 'Descrição não informada'],
                'problem_category' => ['label' => 'Categoria do problema', 'type' => 'short', 'column' => 'problem_category_id', 'path' => 'problemCategory.name', 'with' => 'problemCategory:id,name', 'empty' => 'Sem classificação', 'smart' => 'Sem classificação'],
                'problem_subcategories' => ['label' => 'Subcategorias do problema', 'type' => 'list', 'column' => 'id', 'with' => 'problemSubcategories:id,name', 'empty' => 'Sem subcategorias registradas'],
            ]],
            self::VEHICLE => ['label' => 'Identificação veicular', 'fields' => [
                'vehicle_plate' => ['label' => 'Placa', 'type' => 'short', 'column' => 'vehicle_id', 'path' => 'vehicle.plate', 'vehicle_column' => 'plate', 'empty' => 'Placa não informada'],
                'vehicle_brand' => ['label' => 'Marca', 'type' => 'short', 'column' => 'vehicle_id', 'path' => 'vehicle.brand.name', 'vehicle_column' => 'brand_id', 'with' => 'vehicle.brand:id,name', 'empty' => 'Marca não informada'],
                'vehicle_model' => ['label' => 'Modelo completo', 'type' => 'long_text', 'column' => 'vehicle_id', 'path' => 'vehicle.model_description', 'vehicle_column' => 'model_description', 'empty' => 'Modelo não informado', 'smart' => 'Modelo não informado'],
                'vehicle_line' => ['label' => 'Linha veicular', 'type' => 'option', 'column' => 'vehicle_id', 'vehicle_column' => 'vehicle_line', 'empty' => 'Linha não informada'],
            ]],
            'resolution' => ['label' => 'Resolução e confirmação', 'fields' => [
                'resolution' => ['label' => 'Solução aplicada', 'type' => 'long_text', 'column' => 'resolution', 'empty' => 'Nenhuma solução registrada', 'smart' => 'Nenhuma solução registrada'],
                'verification_source' => ['label' => 'Fonte da confirmação', 'type' => 'option', 'column' => 'verification_source', 'empty' => 'Fonte da confirmação não informada'],
                'verified_at' => ['label' => 'Data e horário da confirmação', 'type' => 'datetime', 'column' => 'verified_at', 'empty' => 'Data e horário da confirmação não informados'],
                'verified_by' => ['label' => 'Confirmado por', 'type' => 'short', 'column' => 'verified_by', 'empty' => 'Responsável pela confirmação não informado'],
                'verification_notes' => ['label' => 'Como a resolução foi verificada', 'type' => 'long_text', 'column' => 'verification_notes', 'empty' => 'Nenhuma verificação registrada', 'smart' => 'Nenhuma verificação registrada'],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    public static function options(): array
    {
        $sections = [];
        foreach (self::sections() as $id => $section) {
            $fields = [];
            foreach ($section['fields'] as $key => $field) {
                $fields[$key] = [
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'width' => self::width($field['type']),
                    'default_selected' => true,
                    'empty' => ['show' => $field['empty'], 'smart' => $field['smart'] ?? null],
                ];
            }
            $sections[$id] = ['label' => $section['label'], 'fields' => $fields];
        }

        return [
            'sections' => $sections,
            'selection_modes' => self::SELECTION_MODES,
            'groupings' => self::GROUPINGS,
            'orders' => self::ORDERS,
            'empty_modes' => self::EMPTY_MODES,
            'layout' => ['pagination' => self::PAGINATION, 'columns' => self::COLUMNS, 'spacing' => self::SPACING],
            'title' => ['modes' => self::TITLE_MODES, 'max_length' => self::TITLE_LIMIT],
            'subtitle' => ['max_length' => self::SUBTITLE_LIMIT],
            'empty_vehicle_message' => self::NO_VEHICLE,
            'defaults' => AnalyticalTicketReportConfiguration::fromValidated([])->toArray(),
        ];
    }

    public static function width(string $type): string
    {
        return $type === 'long_text' ? 'full' : 'columns';
    }
}
