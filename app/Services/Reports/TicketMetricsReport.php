<?php

namespace App\Services\Reports;

use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\System;
use App\Models\VehicleBrand;
use App\Services\TicketMetrics;
use App\Services\VehicleMetrics;
use App\Support\ReportPlateRedaction;
use App\Support\TicketMetricsFilters;
use App\Support\TicketMetricsReportData;
use App\Support\VehicleMetricsReportTables;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

class TicketMetricsReport
{
    public function __construct(private TicketMetrics $metrics, private VehicleMetrics $vehicleMetrics) {}

    public function build(TicketMetricsFilters $filters, bool $includeVehicleStatistics = false): TicketMetricsReportData
    {
        return DB::transaction(function () use ($filters, $includeVehicleStatistics): TicketMetricsReportData {
            $summary = $this->metrics->summary($filters);
            $systems = $this->metrics->systems($filters);
            $categories = $this->metrics->categories($filters);
            $subcategories = $this->metrics->subcategories($filters, array_column($categories, 'total', 'problem_category_id'));
            $monthly = $this->metrics->monthly($filters);

            // Refuse an inconsistent document rather than alter records or invent corrections.
            foreach ([$systems, $categories, $monthly] as $rows) {
                if (array_sum(array_column($rows, 'total')) !== $summary['total']) {
                    throw new LogicException('Inconsistent statistical report totals.');
                }
            }
            $groups = [];
            $byCategory = [];
            $occurrences = 0;
            foreach ($subcategories as $subcategory) {
                $byCategory[$subcategory['problem_category_id'] ?? 'unclassified'][] = $subcategory;
                if ($subcategory['problem_subcategory_id'] !== null) {
                    $occurrences += (int) $subcategory['total'];
                }
            }
            if ($occurrences !== $summary['subcategory_occurrences']) {
                throw new LogicException('Inconsistent subcategory occurrence totals.');
            }
            foreach ($categories as $category) {
                $children = [];
                foreach ($byCategory[$category['problem_category_id'] ?? 'unclassified'] ?? [] as $subcategory) {
                    if ($subcategory['category_total'] !== $category['total']
                        || $subcategory['total'] > $category['total']
                        || array_sum(array_column($subcategory['systems'], 'total')) !== $subcategory['total']) {
                        throw new LogicException('Inconsistent category report totals.');
                    }
                    $children[] = [
                        'name' => (string) $subcategory['subcategory_name'],
                        'total' => (int) $subcategory['total'],
                        'percentage' => (float) $subcategory['percentage'],
                        'systems' => array_map(fn (array $system): array => [
                            'name' => (string) $system['system_name'], 'total' => (int) $system['total'],
                            'percentage' => $this->percentage((int) $system['total'], (int) $category['total']),
                        ], array_values($subcategory['systems'])),
                    ];
                }
                if (array_sum(array_column($children, 'total')) < $category['total']) {
                    throw new LogicException('Incomplete category report totals.');
                }
                $groups[] = ['name' => (string) $category['category_name'], 'total' => (int) $category['total'],
                    'percentage' => (float) $category['percentage'], 'subcategories' => $children];
            }

            [$criteria, $reportSystems, $reportGroups, $reportMonthly, $vehicleTables] = ReportPlateRedaction::redact([
                $this->criteria($filters),
                array_map(fn (array $row): array => [
                    'name' => (string) $row['system_name'], 'total' => (int) $row['total'],
                    'closed' => (int) $row['closed'], 'not_closed' => (int) $row['not_closed'],
                    'percentage' => $this->percentage((int) $row['total'], $summary['total']),
                ], $systems), $groups, array_map(fn (array $row): array => ['month' => (string) $row['month'], 'total' => (int) $row['total']], $monthly),
                $includeVehicleStatistics ? VehicleMetricsReportTables::fromMetrics($this->vehicleMetrics->build($filters, includeVehicleRanking: false)) : [],
            ]);

            return new TicketMetricsReportData(
                CarbonImmutable::now(TicketMetricsFilters::TIMEZONE),
                $criteria, $summary, $reportSystems, $reportGroups, $reportMonthly, $vehicleTables,
            );
        });
    }

    private function percentage(int $count, int $total): float
    {
        return $total === 0 ? 0.0 : round($count * 100 / $total, 2);
    }

    /** @return list<array{label: string, value: string}> */
    private function criteria(TicketMetricsFilters $filters): array
    {
        $period = match ($filters->period) {
            'this_month' => 'Este mês', 'last_30_days' => 'Últimos 30 dias',
            'last_3_months' => 'Últimos 3 meses', 'last_6_months' => 'Últimos 6 meses',
            'this_year' => 'Este ano', 'custom' => 'Período personalizado', default => 'Todo o histórico',
        };
        if ($filters->from && $filters->to) {
            $period .= ' - '.$filters->from->format('d/m/Y').' a '.$filters->to->format('d/m/Y');
        }
        $status = match ($filters->status) {
            'open' => 'Aberto', 'in_progress' => 'Em andamento', 'waiting' => 'Aguardando retorno',
            'resolved' => 'Resolvido', 'closed' => 'Encerrados', 'not_closed' => 'Não encerrados', default => 'Todos os status',
        };
        $category = $filters->classification === 'unclassified' ? 'Sem classificação'
            : ($filters->categoryId ? (string) ProblemCategory::whereKey($filters->categoryId)->value('name') : 'Todas as categorias');
        $subcategory = $filters->classification === 'without_subcategory' ? 'Sem subcategoria'
            : ($filters->subcategoryId ? (string) ProblemSubcategory::whereKey($filters->subcategoryId)->value('name') : 'Todas as subcategorias aplicáveis');

        $vehicle = $filters->vehicle;
        $vehicleCriteria = array_filter([
            match ($vehicle->presence) {
                'with' => 'Com veículo', 'without' => 'Sem veículo', default => null,
            },
            $vehicle->plate !== null ? 'Filtro de placa aplicado' : null,
            $vehicle->brandId !== null ? VehicleBrand::whereKey($vehicle->brandId)->value('name') : null,
            $vehicle->model !== null ? 'Filtro de descrição do modelo aplicado' : null,
            $vehicle->line !== null ? VehicleMetricsReportTables::lineLabel($vehicle->line) : null,
        ]);

        return [
            ['label' => 'Período analisado', 'value' => $period],
            ['label' => 'Sistemas considerados', 'value' => $filters->systemId ? (string) System::whereKey($filters->systemId)->value('name') : 'Todos os sistemas, incluindo o histórico de inativos'],
            ['label' => 'Status', 'value' => $status],
            ['label' => 'Categoria', 'value' => $category],
            ['label' => 'Subcategoria', 'value' => $subcategory],
            ['label' => 'Fuso horário', 'value' => TicketMetricsFilters::TIMEZONE],
            ...($vehicleCriteria === [] ? [] : [['label' => 'Filtros veiculares', 'value' => implode(' · ', $vehicleCriteria)]]),
        ];
    }
}
