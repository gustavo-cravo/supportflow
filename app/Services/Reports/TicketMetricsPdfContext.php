<?php

namespace App\Services\Reports;

use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\System;
use App\Models\VehicleBrand;
use App\Support\TicketMetricsFilters;
use App\Support\TicketMetricsPdfCatalog;
use App\Support\TicketMetricsPdfConfiguration;
use App\Support\VehicleMetricsReportTables;
use Illuminate\Support\Facades\File;

final readonly class TicketMetricsPdfContext
{
    private string $system;

    private string $category;

    private string $subcategory;

    private string $brand;

    public function __construct(private TicketMetricsFilters $filters)
    {
        $this->system = $filters->systemId ? (string) System::whereKey($filters->systemId)->value('name') : '';
        $this->category = $filters->categoryId ? (string) ProblemCategory::whereKey($filters->categoryId)->value('name') : '';
        $this->subcategory = $filters->subcategoryId ? (string) ProblemSubcategory::whereKey($filters->subcategoryId)->value('name') : '';
        $this->brand = $filters->vehicle->brandId ? (string) VehicleBrand::whereKey($filters->vehicle->brandId)->value('name') : '';
    }

    public function title(TicketMetricsPdfConfiguration $configuration): string
    {
        if ($configuration->title['mode'] === TicketMetricsPdfCatalog::CUSTOM_TITLE) {
            return (string) $configuration->title['text'];
        }

        /** @var array{topicPattern: string, vehicleTopicPattern: string, labelPattern: string, replacements: array<string, string>, rules: array<string, string>, fallback: string, separator: string} $policy */
        $policy = File::json(resource_path('reports/metrics-pdf-title.json'));
        $vehicle = $this->filters->vehicle;
        $compact = static fn (string $value): string => mb_strtoupper(preg_replace('/[^\p{L}\p{N}]/u', '', $value) ?? '');
        $label = static fn (string $name): string => preg_match('~'.$policy['labelPattern'].'~Du', $name)
            && ($vehicle->plate === null || ! str_contains($compact($name), $compact($vehicle->plate))) ? trim($name) : '';
        $system = $label($this->system);
        $brand = $label($this->brand);
        $topic = '';
        foreach ([$this->subcategory, $this->category] as $name) {
            $normalized = strtr(preg_replace('/[\p{M}]+/u', '', mb_strtolower($name)) ?? '', $policy['replacements']);
            $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? '');
            if (preg_match('~'.$policy['topicPattern'].'~Du', $normalized)) {
                $topic = $normalized;
                break;
            }
        }

        $selected = array_keys($configuration->sections);
        $grouping = $configuration->sections[TicketMetricsPdfCatalog::VEHICLE_PROBLEMS]['grouping'] ?? null;
        $vehicleOnly = $selected !== [] && array_diff($selected, ['vehicles', TicketMetricsPdfCatalog::VEHICLE_PROBLEMS]) === [];
        $vehicleScope = $vehicleOnly || $vehicle->presence === 'with' || $vehicle->brandId !== null
            || $vehicle->model !== null || $vehicle->plate !== null || $vehicle->line !== null;
        $conditions = [
            'vehicleIndication' => $topic !== '' && (($vehicleScope && $brand === '') || preg_match('~'.$policy['vehicleTopicPattern'].'~Du', $topic)),
            'indication' => $topic !== '',
            'modelOnly' => ($grouping['primary']['by'] ?? null) === 'model' && count($selected) === 1,
            'vehicleOnly' => $vehicleOnly,
            'namedScope' => $system !== '' || $brand !== '',
            'summaryOnly' => $selected === ['summary'],
        ];
        $title = $policy['fallback'];
        foreach ($policy['rules'] as $rule => $caption) {
            if ($conditions[$rule]) {
                $title = $caption;
                break;
            }
        }
        foreach ([$brand, $system] as $name) {
            $candidate = $title.$policy['separator'].$name;
            if ($name !== '' && mb_strlen($candidate) <= TicketMetricsPdfCatalog::TITLE_LIMIT) {
                return $candidate;
            }
        }

        return $title;
    }

    public function period(): string
    {
        return $this->filters->from && $this->filters->to
            ? $this->filters->from->format('d/m/Y').' a '.$this->filters->to->format('d/m/Y')
            : 'Todo o histórico';
    }

    /** @return array<string, string> */
    public function criteria(): array
    {
        $filters = $this->filters;
        $vehicle = $filters->vehicle;
        $status = match ($filters->status) {
            'open' => 'Aberto', 'in_progress' => 'Em andamento', 'waiting' => 'Aguardando retorno',
            'resolved' => 'Resolvido', 'closed' => 'Encerrados', 'not_closed' => 'Não encerrados', default => '',
        };
        $category = $filters->classification === 'unclassified' ? 'Sem classificação' : $this->category;
        $subcategory = $filters->classification === 'without_subcategory' ? 'Sem subcategoria' : $this->subcategory;

        return array_filter([
            'Sistema' => $this->system,
            'Categoria' => $category,
            'Problema' => $subcategory,
            'Status' => $status,
            'Identificação veicular' => match ($vehicle->presence) {
                'with' => 'Com veículo', 'without' => 'Sem veículo', default => '',
            },
            'Marca' => $this->brand,
            'Modelo' => $vehicle->model !== null ? 'Filtro de descrição aplicado' : '',
            'Linha' => $vehicle->line !== null ? VehicleMetricsReportTables::lineLabel($vehicle->line) : '',
            'Placa' => $vehicle->plate !== null ? 'Filtro de placa aplicado' : '',
        ], static fn (string $value): bool => $value !== '');
    }
}
