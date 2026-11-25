<?php

namespace App\Services\Reports;

use App\Support\TicketMetricsPdfCatalog;
use App\Support\VehicleMetricsReportTables;

final class TicketMetricsPdfProjection
{
    public const NOT_APPLICABLE = 'Não aplicável';

    private const SUMMARY = [
        'total' => 'Total de atendimentos',
        'closed' => 'Atendimentos encerrados',
        'not_closed' => 'Atendimentos não encerrados',
        'closed_percentage' => 'Percentual de encerramento',
        'unclassified' => 'Atendimentos sem classificação',
    ];

    /** @param array<string, array{enabled: true, format?: string}> $selection */
    public function __construct(private array $selection) {}

    /**
     * @param  array<string, int>  $summary
     * @return list<array<string, mixed>>
     */
    public function summary(array $summary): array
    {
        $values = [];
        $sentences = [];
        foreach (self::SUMMARY as $key => $label) {
            if (! isset($this->selection[$key])) {
                continue;
            }
            $value = $key === 'closed_percentage'
                ? $this->percent($summary['closed'], $summary['total'])
                : self::number($summary[$key]);
            $values[] = $this->metric($key, $label, $value);
            $sentences[] = $label.': '.$value.'.';
        }
        $blocks = $this->metrics($values);
        if (isset($this->selection['text_summary'])) {
            $blocks[] = ['type' => 'text', 'label' => 'Resumo textual', 'text' => $sentences === []
                ? 'O resumo textual utiliza os indicadores selecionados nesta seção.' : implode(' ', $sentences)];
        }

        return $blocks;
    }

    /**
     * @param  array<string, int>  $summary
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function systems(array $summary, array $rows): array
    {
        $blocks = isset($this->selection['systems_count'])
            ? $this->metrics([$this->metric('systems_count', 'Sistemas com atendimentos', self::number($summary['systems_count']))]) : [];
        $counts = isset($this->selection['tickets_by_system']);
        $percentages = isset($this->selection['system_percentage']);
        if ($this->combine($counts, $percentages, 'tickets_by_system', 'system_percentage')) {
            $blocks[] = ['type' => 'text', 'text' => 'Base do percentual: todos os atendimentos do recorte.'];
            $blocks[] = $rows === []
                ? ['type' => 'empty', 'text' => 'Percentual não aplicável sem atendimentos no recorte.']
                : ['type' => 'table', 'headers' => ['Sistema', 'Atendimentos', '% do total'],
                    'rows' => array_map(fn (array $row): array => [
                        $row['system_name'], self::number($row['total']), $this->percent($row['total'], $summary['total']),
                    ], $rows)];

            return $blocks;
        }
        if ($counts) {
            $blocks[] = $this->series('tickets_by_system', 'Atendimentos por sistema', 'Atendimentos',
                array_map(fn (array $row): array => ['name' => $row['system_name'], 'value' => $row['total']], $rows));
        }
        if ($percentages) {
            $blocks[] = $this->series('system_percentage', 'Percentual do total por sistema', '% do total',
                array_map(fn (array $row): array => ['name' => $row['system_name'], 'value' => (float) $this->ratio($row['total'], $summary['total'])], $rows),
                note: 'Base: todos os atendimentos do recorte.', empty: 'Percentual não aplicável sem atendimentos no recorte.');
        }

        return $blocks;
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     * @param  list<array<string, mixed>>  $problems
     * @return list<array<string, mixed>>
     */
    public function categories(array $categories, array $problems): array
    {
        $blocks = [];
        if (isset($this->selection['tickets_by_category'])) {
            $blocks[] = $this->series('tickets_by_category', 'Atendimentos por categoria', 'Atendimentos',
                array_map(fn (array $row): array => ['name' => $row['category_name'], 'value' => $row['total']], $categories));
        }
        $counts = isset($this->selection['subcategory_occurrences']);
        $percentages = isset($this->selection['subcategory_percentage']);
        $ranked = isset($this->selection['most_frequent_problems']);
        if (! $counts && ! $percentages && ! $ranked) {
            return $blocks;
        }

        $groups = [];
        foreach ($problems as $problem) {
            if ($problem['problem_subcategory_id'] !== null) {
                $groups[$problem['problem_category_id']][] = $problem;
            }
        }
        if ($groups === []) {
            $blocks[] = ['type' => 'empty', 'text' => 'Nenhum problema identificado por subcategoria no recorte.'];

            return $blocks;
        }
        if ($percentages) {
            $blocks[] = ['type' => 'text', 'text' => 'O percentual usa os atendimentos da categoria. Um atendimento pode ter mais de um problema, e a soma pode ultrapassar 100%.'];
        }
        foreach ($categories as $category) {
            $rows = $groups[$category['problem_category_id'] ?? ''] ?? [];
            if ($rows === []) {
                continue;
            }
            $children = [];
            $merge = $this->combine($counts, $percentages, 'subcategory_occurrences', 'subcategory_percentage');
            if ($merge) {
                $children[] = [
                    'type' => 'table', 'headers' => ['Problema identificado', 'Ocorrências', '% na categoria'],
                    'rows' => array_map(fn (array $row): array => [$row['subcategory_name'], self::number($row['total']), self::percentage($row['percentage'])], $rows),
                    'table_context' => $category['category_name'],
                ];
            } else {
                if ($counts) {
                    $children[] = $this->series('subcategory_occurrences', 'Ocorrências por problema', 'Ocorrências',
                        array_map(fn (array $row): array => ['name' => $row['subcategory_name'], 'value' => $row['total']], $rows),
                        tableContext: $category['category_name']);
                }
                if ($percentages) {
                    $children[] = $this->series('subcategory_percentage', 'Percentual por problema', '% na categoria',
                        array_map(fn (array $row): array => ['name' => $row['subcategory_name'], 'value' => $row['percentage']], $rows),
                        tableContext: $category['category_name']);
                }
            }
            if ($ranked) {
                $highest = $rows[0]['total'];
                $names = [];
                foreach ($rows as $row) {
                    if ($highest >= 2 && $row['total'] === $highest) {
                        $names[] = [$row['subcategory_name']];
                    }
                }
                $children[] = $names === []
                    ? ['type' => 'empty', 'text' => 'Nenhum problema apareceu mais de uma vez nesta categoria.']
                    : ['type' => 'table', 'headers' => ['Problemas mais frequentes'], 'rows' => $names, 'table_context' => $category['category_name']];
            }
            if ($merge && count($children) === 1) {
                $blocks[] = $children[0];
            } else {
                $contextInTable = $merge || count(array_column($children, 'table_context')) === count($children);
                $blocks[] = ['type' => 'group', 'label' => $contextInTable ? null : $category['category_name'], 'children' => $children];
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public function vehicles(array $data): array
    {
        $values = [];
        foreach (['distinct_vehicles' => 'Veículos diferentes', 'with_vehicle' => 'Atendimentos com veículo identificado',
            'without_vehicle' => 'Atendimentos sem veículo identificado'] as $key => $label) {
            if (isset($this->selection[$key])) {
                $values[] = $this->metric($key, $label, self::number($data['summary'][$key]));
            }
        }
        $blocks = $this->metrics($values);
        foreach (['lines' => 'Atendimentos por linha veicular', 'brands' => 'Ranking de marcas por atendimentos',
            'models' => 'Ranking de modelos por atendimentos'] as $key => $label) {
            if (! isset($this->selection[$key])) {
                continue;
            }
            $rows = array_map(fn (array $row): array => [
                'name' => match ($key) {
                    'lines' => VehicleMetricsReportTables::lineLabel($row['vehicle_line']),
                    'models' => $row['brand_name'].' / '.($row['model_description'] ?? 'Modelo não informado'),
                    default => $row['brand_name'],
                },
                'value' => $row['total'],
                'vehicles' => $row['vehicles'],
            ], array_values($data[$key]));
            $blocks[] = $this->series($key, $label, 'Atendimentos', $rows,
                note: $key === 'brands' ? 'O ranking considera quantidade de atendimentos.' : null,
                empty: 'Nenhum atendimento com veículo identificado no recorte.');
        }
        if (isset($this->selection['recurrence'])) {
            $recurrence = $data['recurrence'];
            $blocks[] = ['type' => 'group', 'label' => 'Repetição de atendimentos por veículo', 'children' => [
                ...$this->metrics([
                    $this->metric('recurrence', 'Veículos com dois ou mais atendimentos', self::number($recurrence['repeat_vehicles'])),
                    $this->metric('recurrence', 'Atendimentos desses veículos', self::number($recurrence['repeat_tickets'])),
                    $this->metric('recurrence', 'Maior quantidade de atendimentos de um veículo', self::number($recurrence['max_tickets_per_vehicle'])),
                ]),
                ...($recurrence['repeat_vehicles'] === 0
                    ? [['type' => 'text', 'text' => 'Nenhum veículo apresentou dois ou mais atendimentos no período analisado.']] : []),
            ]];
        }

        return $blocks;
    }

    public static function number(int|float $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    public static function percentage(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',').'%';
    }

    private function percent(int $value, int $total): string
    {
        $ratio = $this->ratio($value, $total);

        return $ratio === null ? self::NOT_APPLICABLE : self::percentage($ratio);
    }

    private function ratio(int $value, int $total): ?float
    {
        return $total === 0 ? null : round($value * 100 / $total, 2);
    }

    private function format(string $key): string
    {
        return $this->selection[$key]['format'] ?? TicketMetricsPdfCatalog::AUTOMATIC;
    }

    private function combine(bool $first, bool $second, string $firstKey, string $secondKey): bool
    {
        $compatible = [TicketMetricsPdfCatalog::AUTOMATIC, 'table'];

        return $first && $second && in_array($this->format($firstKey), $compatible, true)
            && in_array($this->format($secondKey), $compatible, true);
    }

    /** @return array{label: string, value: string, format: string} */
    private function metric(string $key, string $label, string $value): array
    {
        return ['label' => $label, 'value' => $value, 'format' => $this->format($key) === 'table' ? 'table' : 'indicator'];
    }

    /**
     * @param  list<array{label: string, value: string, format: string}>  $metrics
     * @return list<array<string, mixed>>
     */
    private function metrics(array $metrics): array
    {
        $blocks = [];
        foreach ($metrics as $metric) {
            $format = $metric['format'];
            $last = array_key_last($blocks);
            if ($last === null || $blocks[$last]['type'] !== $format) {
                $blocks[] = $format === 'table'
                    ? ['type' => 'table', 'headers' => ['Indicador', 'Resultado'], 'rows' => []]
                    : ['type' => 'indicator', 'rows' => []];
                $last = array_key_last($blocks);
            }
            $blocks[$last]['rows'][] = $format === 'table' ? [$metric['label'], $metric['value']] : $metric;
        }

        return $blocks;
    }

    /**
     * @param  list<array{name: string, value: int|float, vehicles?: int}>  $rows
     * @return array<string, mixed>
     */
    private function series(string $key, string $label, string $unit, array $rows, ?string $note = null, string $empty = 'Nenhum atendimento no recorte.', ?string $tableContext = null): array
    {
        $format = TicketMetricsPdfLayout::seriesFormat($key, $this->format($key), $rows);

        return ['type' => 'series', 'label' => $label, 'unit' => $unit, 'rows' => $rows, 'format' => $format, 'note' => $note, 'empty' => $empty,
            ...($format === 'table' && $tableContext !== null ? ['table_context' => $tableContext] : [])];
    }
}
