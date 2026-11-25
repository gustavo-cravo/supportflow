<?php

namespace App\Services;

use App\Enums\VehicleLine;
use App\Models\SupportTicket;
use App\Support\TicketMetricsFilters;
use App\Support\VehicleModelGroup;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class VehicleMetrics
{
    /**
     * @param  list<string>|null  $problemDimensions
     * @return array<string, mixed>
     */
    public function build(TicketMetricsFilters $filters, bool $includeVehicleRanking = true, ?array $problemDimensions = null): array
    {
        $base = $filters->apply(DB::table('support_tickets'));
        $count = (clone $base)->count();
        $linked = $base->join('vehicles', 'vehicles.id', '=', 'support_tickets.vehicle_id');
        $modelKey = VehicleModelGroup::expression($linked);
        $vehicleCounts = (clone $linked)->select('vehicles.id AS vehicle_id')
            ->selectRaw('COUNT(*) AS total')->groupBy('vehicles.id');
        $recurrence = DB::query()->fromSub($vehicleCounts, 'vehicle_counts')
            ->selectRaw('COUNT(*) AS vehicles, COALESCE(SUM(total), 0) AS tickets, COALESCE(MAX(total), 0) AS highest')
            ->selectRaw('COUNT(CASE WHEN total > 1 THEN 1 END) AS repeat_vehicles, COALESCE(SUM(CASE WHEN total > 1 THEN total ELSE 0 END), 0) AS repeat_tickets')
            ->first();

        $withVehicle = (int) $recurrence->tickets;
        $lineCounts = (clone $linked)->select('vehicles.vehicle_line')
            ->selectRaw('COUNT(*) AS total, COUNT(DISTINCT vehicles.id) AS vehicles')
            ->groupBy('vehicles.vehicle_line')->get()->keyBy('vehicle_line');
        $lines = array_map(function (VehicleLine $line) use ($lineCounts, $withVehicle): array {
            $row = $lineCounts->get($line->value);
            $total = (int) ($row->total ?? 0);

            return [
                'vehicle_line' => $line->value, 'total' => $total,
                'vehicles' => (int) ($row->vehicles ?? 0), 'percentage' => $this->percentage($total, $withVehicle),
            ];
        }, VehicleLine::cases());
        $brands = (clone $linked)->join('vehicle_brands', 'vehicle_brands.id', '=', 'vehicles.brand_id')
            ->select('vehicles.brand_id', 'vehicle_brands.name AS brand_name')
            ->selectRaw('COUNT(*) AS total, COUNT(DISTINCT vehicles.id) AS vehicles')
            ->groupBy('vehicles.brand_id', 'vehicle_brands.name')->orderByDesc('total')->orderBy('vehicles.brand_id')
            ->get()->map(fn ($row) => [
                'brand_id' => (int) $row->brand_id, 'brand_name' => $row->brand_name, 'total' => (int) $row->total,
                'vehicles' => (int) $row->vehicles, 'percentage' => $this->percentage((int) $row->total, $withVehicle),
            ])->all();
        $modelGroups = (clone $linked)->select('vehicles.brand_id')->selectRaw($modelKey.' AS model_key')
            ->selectRaw('MIN(vehicles.id) AS example_id, COUNT(*) AS total, COUNT(DISTINCT vehicles.id) AS vehicles')
            ->selectRaw('MIN(vehicles.vehicle_line) AS first_line, MAX(vehicles.vehicle_line) AS last_line')
            ->groupBy('vehicles.brand_id', 'model_key');
        $models = DB::query()->fromSub($modelGroups, 'model_groups')
            ->join('vehicles AS example', 'example.id', '=', 'model_groups.example_id')
            ->join('vehicle_brands', 'vehicle_brands.id', '=', 'model_groups.brand_id')
            ->select('model_groups.*', 'example.model_description', 'vehicle_brands.name AS brand_name')
            ->orderByDesc('model_groups.total')->orderBy('model_groups.brand_id')->orderBy('model_groups.example_id')
            ->get()->map(fn ($row) => [
                'brand_id' => (int) $row->brand_id, 'brand_name' => $row->brand_name, 'model_key' => $row->model_key ?? 'missing',
                'model_description' => $row->model_key === null ? null : $row->model_description,
                'vehicle_line' => $row->first_line === $row->last_line ? $row->first_line : 'mixed',
                'total' => (int) $row->total, 'vehicles' => (int) $row->vehicles,
            ])->all();
        $missing = array_filter($models, fn (array $row) => $row['model_key'] === 'missing');

        return [
            'summary' => [
                'with_vehicle' => $withVehicle, 'without_vehicle' => $count - $withVehicle,
                'identification_percentage' => $this->percentage($withVehicle, $count),
                'distinct_vehicles' => (int) $recurrence->vehicles, 'distinct_models' => count($models) - count($missing),
                'vehicles_without_model' => (int) array_sum(array_column($missing, 'vehicles')),
                'light_tickets' => (int) ($lineCounts->get(VehicleLine::Light->value)->total ?? 0),
                'heavy_tickets' => (int) ($lineCounts->get(VehicleLine::Heavy->value)->total ?? 0),
            ],
            'recurrence' => [
                'repeat_vehicles' => (int) $recurrence->repeat_vehicles, 'repeat_tickets' => (int) $recurrence->repeat_tickets,
                'max_tickets_per_vehicle' => (int) $recurrence->highest,
            ],
            'lines' => $lines,
            'brands' => $brands,
            'models' => $models,
            'problems' => collect($problemDimensions ?? ['brand', 'model', 'line'])
                ->mapWithKeys(fn (string $dimension) => [$dimension => $this->problems($linked, $filters, $dimension, $modelKey)])->all(),
            ...($includeVehicleRanking ? ['vehicles' => $this->vehicles($vehicleCounts)] : []),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function vehicles(Builder $counts): array
    {
        return array_values(DB::query()->fromSub($counts, 'vehicle_counts')
            ->join('vehicles', 'vehicles.id', '=', 'vehicle_counts.vehicle_id')
            ->join('vehicle_brands', 'vehicle_brands.id', '=', 'vehicles.brand_id')
            ->select('vehicle_counts.*', 'vehicles.plate', 'vehicles.model_description', 'vehicles.vehicle_line', 'vehicle_brands.name AS brand_name')
            ->orderByDesc('vehicle_counts.total')->orderBy('vehicles.id')->get()->map(fn ($row) => [
                'vehicle_id' => (int) $row->vehicle_id, 'plate' => $row->plate, 'brand_name' => $row->brand_name,
                'model_description' => $row->model_description, 'vehicle_line' => $row->vehicle_line, 'total' => (int) $row->total,
            ])->all());
    }

    /**
     * @param  literal-string  $modelKey
     * @return list<array<string, mixed>>
     */
    private function problems(Builder $linked, TicketMetricsFilters $filters, string $dimension, string $modelKey): array
    {
        $query = (clone $linked)->join('systems', 'systems.id', '=', 'support_tickets.system_id')
            ->leftJoin('problem_categories AS category', 'category.id', '=', 'support_tickets.problem_category_id')
            ->select('systems.id AS system_id', 'systems.name AS system_name', 'category.id AS problem_category_id', 'category.name AS category_name')
            ->groupBy('systems.id', 'systems.name', 'category.id', 'category.name');
        if ($dimension === 'line') {
            $query->addSelect('vehicles.vehicle_line')->groupBy('vehicles.vehicle_line');
        } else {
            $query->join('vehicle_brands', 'vehicle_brands.id', '=', 'vehicles.brand_id')
                ->addSelect('vehicles.brand_id', 'vehicle_brands.name AS brand_name')
                ->groupBy('vehicles.brand_id', 'vehicle_brands.name');
        }
        if ($dimension === 'model') {
            $query->selectRaw($modelKey.' AS model_key')->selectRaw('MIN(vehicles.id) AS example_id')->groupBy('model_key');
        }

        $categories = (clone $query)->selectRaw("NULL AS problem_subcategory_id, NULL AS subcategory_name, 'category' AS level, COUNT(*) AS total");
        $subcategories = $query->join(SupportTicket::SUBCATEGORY_PIVOT.' AS links', 'links.support_ticket_id', '=', 'support_tickets.id')
            ->join('problem_subcategories AS subcategory', 'subcategory.id', '=', 'links.problem_subcategory_id')
            ->addSelect('subcategory.id AS problem_subcategory_id', 'subcategory.name AS subcategory_name')
            ->selectRaw("'subcategory' AS level, COUNT(*) AS total")->groupBy('subcategory.id', 'subcategory.name');
        if ($filters->subcategoryId !== null) {
            $subcategories->where('subcategory.id', $filters->subcategoryId);
        }
        $results = DB::query()->fromSub($categories->unionAll($subcategories), 'problems')->select('problems.*')
            ->orderByDesc('total')->orderBy('system_id')->orderBy('problem_category_id')->orderBy('problem_subcategory_id')
            ->orderBy($dimension === 'line' ? 'vehicle_line' : 'brand_id');
        if ($dimension === 'model') {
            $results->join('vehicles AS example', 'example.id', '=', 'problems.example_id')
                ->addSelect('example.model_description')->orderBy('problems.example_id');
        }

        return array_values($results->get()->map(fn ($row) => [
            'system_id' => (int) $row->system_id, 'system_name' => $row->system_name,
            'problem_category_id' => isset($row->problem_category_id) ? (int) $row->problem_category_id : null,
            'category_name' => $row->category_name ?? 'Sem classificação',
            'problem_subcategory_id' => isset($row->problem_subcategory_id) ? (int) $row->problem_subcategory_id : null,
            'subcategory_name' => $row->subcategory_name, 'level' => $row->level, 'total' => (int) $row->total,
            ...($dimension === 'line' ? ['vehicle_line' => $row->vehicle_line] : ['brand_id' => (int) $row->brand_id, 'brand_name' => $row->brand_name]),
            ...($dimension === 'model' ? [
                'model_key' => $row->model_key ?? 'missing',
                'model_description' => $row->model_key === null ? null : $row->model_description,
            ] : []),
        ])->all());
    }

    private function percentage(int $count, int $total): float
    {
        return $total === 0 ? 0.0 : round($count * 100 / $total, 2);
    }
}
