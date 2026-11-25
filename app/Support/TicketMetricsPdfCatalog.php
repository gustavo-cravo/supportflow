<?php

namespace App\Support;

/**
 * @phpstan-type Indicator array{label: string, formats: list<string>, semantic_fields: list<string>, calculation_inputs: list<string>}
 */
final class TicketMetricsPdfCatalog
{
    public const AUTOMATIC = 'automatic';

    public const CUSTOM_TITLE = 'custom';

    public const TITLE_LIMIT = 120;

    public const SUBTITLE_LIMIT = 180;

    public const VEHICLE_PROBLEMS = 'vehicle_problems';

    private const FORMATS = [
        self::AUTOMATIC => 'Automático',
        'indicator' => 'Indicador numérico ou percentual',
        'horizontal_bar' => 'Barras horizontais',
        'vertical_bar' => 'Barras verticais',
        'doughnut' => 'Rosca',
        'table' => 'Tabela',
        'text' => 'Texto',
        'descriptive_group' => 'Agrupamento descritivo',
    ];

    private const NUMBER = [self::AUTOMATIC, 'indicator', 'table'];

    private const SERIES = [self::AUTOMATIC, 'horizontal_bar', 'vertical_bar', 'table'];

    public const GROUPING_FORMATS = [self::AUTOMATIC, 'table', 'descriptive_group'];

    public const GROUPINGS = [
        'brand' => ['label' => 'Marca', 'semantic_fields' => ['brand_name']],
        'model' => ['label' => 'Marca e modelo', 'semantic_fields' => ['brand_name', 'model_description']],
        'line' => ['label' => 'Linha veicular', 'semantic_fields' => ['vehicle_line']],
    ];

    public const TITLE_MODES = [
        self::AUTOMATIC => 'Automático',
        self::CUSTOM_TITLE => 'Personalizado',
    ];

    /** @return array<string, array{label: string, indicators: array<string, Indicator>}> */
    public static function sections(): array
    {
        $system = ['system_name'];
        $problem = ['category_name', 'subcategory_name'];

        return [
            'summary' => [
                'label' => 'Resumo geral dos atendimentos',
                'indicators' => [
                    'total' => self::indicator('Total de atendimentos', self::NUMBER),
                    'closed' => self::indicator('Atendimentos encerrados', self::NUMBER),
                    'not_closed' => self::indicator('Atendimentos não encerrados', self::NUMBER),
                    'closed_percentage' => self::indicator('Percentual de encerramento', self::NUMBER, calculationInputs: ['closed', 'total']),
                    'unclassified' => self::indicator('Atendimentos sem classificação', self::NUMBER),
                    'text_summary' => self::indicator('Resumo textual automático', [self::AUTOMATIC, 'text'], calculationInputs: ['selected_summary_indicators']),
                ],
            ],
            'systems' => [
                'label' => 'Distribuição por sistema',
                'indicators' => [
                    'tickets_by_system' => self::indicator('Atendimentos por sistema', self::SERIES, $system),
                    'system_percentage' => self::indicator('Participação de cada sistema', self::SERIES, $system, ['tickets_by_system', 'total']),
                    'systems_count' => self::indicator('Sistemas envolvidos', self::NUMBER),
                ],
            ],
            'categories' => [
                'label' => 'Categorias e problemas identificados',
                'indicators' => [
                    'tickets_by_category' => self::indicator('Atendimentos por categoria', self::SERIES, ['category_name']),
                    'subcategory_occurrences' => self::indicator('Ocorrências por subcategoria', self::SERIES, $problem),
                    'subcategory_percentage' => self::indicator('Participação do problema na categoria', self::SERIES, $problem, ['subcategory_occurrences', 'tickets_by_category']),
                    'most_frequent_problems' => self::indicator('Problemas mais frequentes', [self::AUTOMATIC, 'table'], $problem, ['subcategory_occurrences']),
                ],
            ],
            'vehicles' => [
                'label' => 'Estatísticas veiculares',
                'indicators' => [
                    'distinct_vehicles' => self::indicator('Veículos diferentes envolvidos', self::NUMBER),
                    'with_vehicle' => self::indicator('Atendimentos com identificação veicular', self::NUMBER),
                    'without_vehicle' => self::indicator('Atendimentos sem identificação veicular', self::NUMBER),
                    'lines' => self::indicator('Distribuição por linha veicular', [...self::SERIES, 'doughnut'], ['vehicle_line']),
                    'brands' => self::indicator('Ranking de marcas', self::SERIES, ['brand_name']),
                    'models' => self::indicator('Ranking de modelos', self::SERIES, ['brand_name', 'model_description']),
                    'recurrence' => self::indicator('Recorrência de atendimentos por veículo', self::NUMBER, calculationInputs: ['tickets_per_vehicle']),
                ],
            ],
            self::VEHICLE_PROBLEMS => [
                'label' => 'Detalhamento dos problemas por veículo',
                'indicators' => [
                    'systems' => self::indicator('Sistemas envolvidos', [], $system),
                    'categories' => self::indicator('Categorias e subcategorias', [], $problem),
                    'counts' => self::indicator('Quantidade de atendimentos e ocorrências', []),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function options(): array
    {
        return [
            'sections' => array_map(
                static fn (array $section): array => $section + ['activation_indicators' => array_keys($section['indicators'])],
                self::sections(),
            ),
            'formats' => self::FORMATS,
            'vehicle_grouping' => [
                'section' => self::VEHICLE_PROBLEMS,
                'dimensions' => self::GROUPINGS,
                'formats' => self::GROUPING_FORMATS,
                'activation_primary' => 'brand',
            ],
            'title' => ['modes' => self::TITLE_MODES, 'max_length' => self::TITLE_LIMIT],
            'subtitle' => ['max_length' => self::SUBTITLE_LIMIT],
            'defaults' => [
                'enabled' => false,
                'format' => self::AUTOMATIC,
                'title' => ['mode' => self::AUTOMATIC, 'text' => null],
                'subtitle' => null,
                'grouping' => ['primary' => null, 'additional' => []],
            ],
        ];
    }

    /**
     * @param  list<string>  $formats
     * @param  list<string>  $semanticFields
     * @param  list<string>  $calculationInputs
     * @return Indicator
     */
    private static function indicator(string $label, array $formats, array $semanticFields = [], array $calculationInputs = []): array
    {
        return ['label' => $label, 'formats' => $formats, 'semantic_fields' => $semanticFields, 'calculation_inputs' => $calculationInputs];
    }
}
