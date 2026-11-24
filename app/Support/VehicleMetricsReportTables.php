<?php

namespace App\Support;

/** Projects aggregate rows into the same columns for both document formats. */
final class VehicleMetricsReportTables
{
    public const SUMMARY = [
        'with_vehicle' => 'Atendimentos com veículo',
        'without_vehicle' => 'Atendimentos sem veículo',
        'identification_percentage' => 'Atendimentos com identificação veicular (%)',
        'distinct_vehicles' => 'Veículos distintos',
        'distinct_models' => 'Modelos distintos',
        'vehicles_without_model' => 'Veículos distintos sem modelo',
        'light_tickets' => 'Atendimentos de linha leve',
        'heavy_tickets' => 'Atendimentos de linha pesada',
        'repeat_vehicles' => 'Veículos com dois ou mais atendimentos',
        'repeat_tickets' => 'Atendimentos desses veículos recorrentes',
        'max_tickets_per_vehicle' => 'Maior número de atendimentos por veículo',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{title: string, sheet: string, headers: list<string>, widths: list<int>, rows: list<list<string|int|float>>, percentages: list<int>, percentageRows: list<int>, note: ?string}>
     */
    public static function fromMetrics(array $data): array
    {
        $indicators = $data['summary'] + $data['recurrence'];
        $summary = [];
        $percentageRows = [];
        foreach (self::SUMMARY as $key => $label) {
            if ($key === 'identification_percentage') {
                $percentageRows[] = count($summary);
            }
            $summary[] = [$label, $key === 'identification_percentage' ? $indicators[$key] / 100 : $indicators[$key]];
        }
        $tables = [
            self::table('Resumo veicular e recorrência agregada', 'Resumo veicular', ['Indicador', 'Valor'], [58, 23], $summary,
                note: 'O percentual de identificação usa o total filtrado de atendimentos. Recorrência considera dois ou mais atendimentos do mesmo veículo no recorte.',
                percentageRows: $percentageRows),
            self::table('Distribuição por linha', 'Linhas veiculares', ['Linha', 'Atendimentos', 'Veículos distintos', 'Participação'], [34, 20, 24, 21],
                array_values(array_map(fn (array $row) => [self::lineLabel($row['vehicle_line']), $row['total'], $row['vehicles'], $row['percentage'] / 100], $data['lines'])), [4],
                'Participação entre os atendimentos com veículo no período e nos filtros aplicados.'),
            self::table('Ranking de marcas', 'Marcas', ['Marca', 'Atendimentos', 'Veículos distintos', 'Participação'], [40, 20, 24, 21],
                array_values(array_map(fn (array $row) => [$row['brand_name'], $row['total'], $row['vehicles'], $row['percentage'] / 100], $data['brands'])), [4],
                'Participação entre os atendimentos com veículo no período e nos filtros aplicados.'),
            self::table('Ranking de modelos', 'Modelos', ['Marca', 'Modelo', 'Linha', 'Atendimentos', 'Veículos distintos'], [23, 60, 20, 26, 24],
                array_values(array_map(fn (array $row) => [$row['brand_name'], $row['model_description'] ?? 'Modelo não informado',
                    self::lineLabel($row['vehicle_line']), $row['total'], $row['vehicles']], $data['models']))),
        ];
        foreach (['brand' => 'marca', 'model' => 'modelo', 'line' => 'linha'] as $dimension => $label) {
            $groupHeaders = match ($dimension) {
                'brand' => ['Marca'], 'model' => ['Marca', 'Modelo'], default => ['Linha'],
            };
            $rows = [];
            foreach ($data['problems'][$dimension] as $row) {
                $group = match ($dimension) {
                    'brand' => [$row['brand_name']],
                    'model' => [$row['brand_name'], $row['model_description'] ?? 'Modelo não informado'],
                    default => [self::lineLabel($row['vehicle_line'])],
                };
                $rows[] = [...$group, $row['system_name'], $row['category_name'], $row['subcategory_name'] ?? '—',
                    $row['level'] === 'category' ? 'Atendimentos' : 'Vínculos', $row['total']];
            }
            $tables[] = self::table('Problemas recorrentes por '.$label, 'Problemas por '.$label,
                [...$groupHeaders, 'Sistema', 'Categoria', 'Subcategoria', 'Contagem', 'Total'],
                [...($dimension === 'model' ? [22, 54] : [26]), 25, 30, 30, 29, 12], $rows,
                note: 'Linhas de categoria contam atendimentos distintos. Linhas de subcategoria contam vínculos e podem somar mais que os atendimentos.');
        }

        return $tables;
    }

    public static function lineLabel(string $line): string
    {
        return match ($line) {
            'light' => 'Linha leve', 'heavy' => 'Linha pesada', default => 'Leve e pesada',
        };
    }

    /**
     * @param  list<string>  $headers
     * @param  list<int>  $widths
     * @param  list<list<string|int|float>>  $rows
     * @param  list<int>  $percentages
     * @param  list<int>  $percentageRows
     * @return array{title: string, sheet: string, headers: list<string>, widths: list<int>, rows: list<list<string|int|float>>, percentages: list<int>, percentageRows: list<int>, note: ?string}
     */
    private static function table(string $title, string $sheet, array $headers, array $widths, array $rows, array $percentages = [], ?string $note = null, array $percentageRows = []): array
    {
        return compact('title', 'sheet', 'headers', 'widths', 'rows', 'percentages', 'percentageRows', 'note');
    }
}
