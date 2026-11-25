<?php

namespace App\Services\Reports;

use App\Support\TicketMetricsPdfCatalog;
use App\Support\VehicleMetricsReportTables;

/** Rolls up disjoint ticket and occurrence rows independently along the selected axes. */
final class VehicleProblemsPdfProjection
{
    /**
     * @param  array{by: string, format?: string}  $selection
     * @param  array<string, array{enabled: true, format?: string}>  $complements
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function build(array $selection, array $complements, array $rows): array
    {
        $by = $selection['by'];
        $systems = isset($complements['systems']);
        $categories = isset($complements['categories']);
        $counts = isset($complements['counts']);
        $label = 'Por '.mb_strtolower(TicketMetricsPdfCatalog::GROUPINGS[$by]['label']);
        $groups = $this->rollup($rows, $by, $systems, $categories);
        if ($groups === []) {
            return ['type' => 'group', 'label' => $label, 'children' => [
                ['type' => 'empty', 'text' => 'Nenhum atendimento com veículo identificado no recorte.'],
            ]];
        }

        $format = $selection['format'] ?? TicketMetricsPdfCatalog::AUTOMATIC;
        if ($format === TicketMetricsPdfCatalog::AUTOMATIC) {
            $format = $by === 'model' || count($groups) <= 4 ? 'descriptive_group' : 'table';
        }
        if ($format === 'table' && $complements === []) {
            return ['type' => 'table', 'label' => $label, 'headers' => [TicketMetricsPdfCatalog::GROUPINGS[$by]['label']],
                'rows' => array_map(fn (array $group): array => [$group['name']], array_values($groups))];
        }

        $blocks = [];
        foreach ($groups as $group) {
            $scopes = array_values($group['scopes']);
            $children = match (true) {
                ! $categories && $format === 'table' => [$this->scopeTable($scopes, $systems, $counts)],
                default => array_map(fn (array $scope): array => $this->scope($scope, $group['name'], $format, $systems, $categories, $counts), $scopes),
            };
            $headingInTable = ! $systems && isset($children[0]['children'][0]['table_context']);
            $blocks[] = $complements === []
                ? ['type' => 'text', 'text' => $group['name']]
                : ['type' => 'group', 'label' => $headingInTable ? null : $group['name'], 'children' => $children, 'keep' => $this->small($group)];
        }

        return ['type' => 'group', 'label' => $label, 'children' => $blocks];
    }

    /** @param array<string, mixed> $group */
    private function small(array $group): bool
    {
        $size = mb_strlen($group['name']);
        foreach ($group['scopes'] as $scope) {
            $size += 100;
            foreach ($scope['categories'] as $category) {
                $size += 50 + mb_strlen($category['name']);
                foreach ($category['problems'] as $problem) {
                    $size += 35 + mb_strlen($problem['name']);
                }
            }
        }

        return $size <= 300;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function rollup(array $rows, string $dimension, bool $systems, bool $categories): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = match ($dimension) {
                'line' => $row['vehicle_line'],
                'model' => $row['brand_id'].':'.$row['model_key'],
                default => (string) $row['brand_id'],
            };
            $name = match ($dimension) {
                'line' => VehicleMetricsReportTables::lineLabel($row['vehicle_line']),
                'model' => $row['brand_name'].' / '.($row['model_description'] ?? 'Modelo não informado'),
                default => $row['brand_name'],
            };
            $groups[$key] ??= ['name' => $name, 'scopes' => []];
            $systemKey = $systems ? $row['system_id'] : 'all';
            $scope = &$groups[$key]['scopes'][$systemKey];
            $scope ??= ['name' => $systems ? $row['system_name'] : null, 'tickets' => 0, 'occurrences' => 0, 'categories' => []];
            $ticket = $row['level'] === 'category';
            $scope[$ticket ? 'tickets' : 'occurrences'] += $row['total'];
            if ($categories) {
                $category = &$scope['categories'][$row['problem_category_id'] ?? 'unclassified'];
                $category ??= ['name' => $row['category_name'], 'tickets' => 0, 'problems' => []];
                if ($ticket) {
                    $category['tickets'] += $row['total'];
                } else {
                    $problem = &$category['problems'][$row['problem_subcategory_id']];
                    $problem ??= ['name' => $row['subcategory_name'], 'total' => 0];
                    $problem['total'] += $row['total'];
                    unset($problem);
                }
                unset($category);
            }
            unset($scope);
        }

        return $groups;
    }

    /**
     * @param  list<array<string, mixed>>  $scopes
     * @return array<string, mixed>
     */
    private function scopeTable(array $scopes, bool $systems, bool $counts): array
    {
        return [
            'type' => 'table',
            'headers' => [...($systems ? ['Sistema'] : []), ...($counts ? ['Atendimentos', 'Ocorrências'] : [])],
            'rows' => array_map(fn (array $scope): array => [
                ...($systems ? [$scope['name']] : []),
                ...($counts ? [TicketMetricsPdfProjection::number($scope['tickets']), TicketMetricsPdfProjection::number($scope['occurrences'])] : []),
            ], $scopes),
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function scope(array $scope, string $name, string $format, bool $systems, bool $categories, bool $counts): array
    {
        $scopeLabel = $systems ? 'Sistema: '.$scope['name'] : null;
        if (! $categories) {
            return ['type' => 'text', 'label' => $scopeLabel, 'text' => $counts
                ? 'Atendimentos: '.TicketMetricsPdfProjection::number($scope['tickets']).' · Ocorrências: '.TicketMetricsPdfProjection::number($scope['occurrences']) : ''];
        }

        $blocks = [];
        if ($format === 'table') {
            $rows = [];
            foreach ($scope['categories'] as $category) {
                $rows[] = [$category['name'], ...($counts ? [TicketMetricsPdfProjection::number($category['tickets']), ''] : [])];
                foreach ($category['problems'] as $problem) {
                    $rows[] = ['- '.$problem['name'], ...($counts ? ['', TicketMetricsPdfProjection::number($problem['total'])] : [])];
                }
            }
            $blocks[] = ['type' => 'table', 'headers' => ['Categoria / problema', ...($counts ? ['Atendimentos', 'Ocorrências'] : [])], 'rows' => $rows,
                ...(count($rows) > 4 && mb_strlen($name.($scopeLabel ?? '')) <= 125
                    ? ['table_context' => implode(' · ', array_filter([$name, $scopeLabel]))] : []),
            ];
        } else {
            foreach ($scope['categories'] as $category) {
                $blocks[] = ['type' => 'description', 'label' => $category['name'],
                    'text' => $counts ? 'Atendimentos: '.TicketMetricsPdfProjection::number($category['tickets']) : '',
                    'items' => array_map(fn (array $problem): string => $problem['name']
                        .($counts ? ' · Ocorrências: '.TicketMetricsPdfProjection::number($problem['total']) : ''), array_values($category['problems'])),
                ];
            }
        }
        if ($counts && $scope['occurrences'] === 0) {
            $blocks[] = ['type' => 'text', 'text' => 'Ocorrências de problemas: 0'];
        }

        return ['type' => 'group', 'label' => $scopeLabel, 'children' => $blocks];
    }
}
