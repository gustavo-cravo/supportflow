<?php

namespace App\Services\Reports;

use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\System;
use App\Models\VehicleBrand;
use App\Support\AnalyticalTicketReportCatalog as Catalog;
use App\Support\AnalyticalTicketReportConfiguration;
use App\Support\ProblemName;
use App\Support\TicketMetricsFilters;
use App\Support\VehicleMetricsReportTables;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final readonly class AnalyticalTicketReportContext
{
    private string $system;

    private string $category;

    private string $subcategory;

    private string $brand;

    /** @var array<string, true> */
    private array $fields;

    public function __construct(private TicketMetricsFilters $filters, private AnalyticalTicketReportConfiguration $configuration)
    {
        $this->fields = array_fill_keys($configuration->fields(), true);
        $this->system = isset($this->fields['system']) && $filters->systemId !== null
            ? $this->safeFilterName((string) System::whereKey($filters->systemId)->value('name')) : '';
        $this->category = isset($this->fields['problem_category']) && $filters->categoryId !== null
            ? $this->safeFilterName((string) ProblemCategory::whereKey($filters->categoryId)->value('name')) : '';
        $this->subcategory = isset($this->fields['problem_subcategories']) && $filters->subcategoryId !== null
            ? $this->safeFilterName((string) ProblemSubcategory::whereKey($filters->subcategoryId)->value('name')) : '';
        $this->brand = isset($this->fields['vehicle_brand']) && $filters->vehicle->brandId !== null
            ? $this->safeFilterName((string) VehicleBrand::whereKey($filters->vehicle->brandId)->value('name')) : '';
    }

    public function title(): string
    {
        if ($this->configuration->title['text'] !== null) {
            return $this->configuration->title['text'];
        }

        /** @var array{topicPattern: string, labelPattern: string, separator: string} $policy */
        $policy = File::json(resource_path('reports/metrics-pdf-title.json'));
        $title = 'Relatório analítico de atendimentos';
        foreach ([$this->subcategory, $this->category] as $topic) {
            if (preg_match('~'.$policy['topicPattern'].'~Du', Str::ascii(ProblemName::key($topic), 'pt')) === 1) {
                $title = 'Relatório analítico de erros de indicação';
                break;
            }
        }

        foreach ([$this->brand, $this->system] as $name) {
            $candidate = $title.$policy['separator'].trim($name);
            if (trim($name) !== '' && preg_match('~'.$policy['labelPattern'].'~Du', $name) === 1
                && mb_strlen($candidate) <= Catalog::TITLE_LIMIT) {
                return $candidate;
            }
        }

        return $title;
    }

    public function period(): string
    {
        return $this->filters->from !== null && $this->filters->to !== null
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

        return array_filter([
            'Sistema' => $filters->systemId !== null ? ($this->system ?: 'Filtro aplicado') : '',
            'Categoria' => $filters->classification === 'unclassified'
                ? (isset($this->fields['problem_category']) ? 'Sem classificação' : 'Filtro aplicado')
                : ($filters->categoryId !== null ? ($this->category ?: 'Filtro aplicado') : ''),
            'Subcategoria' => $filters->classification === 'without_subcategory'
                ? (isset($this->fields['problem_subcategories']) ? 'Sem subcategoria' : 'Filtro aplicado')
                : ($filters->subcategoryId !== null ? ($this->subcategory ?: 'Filtro aplicado') : ''),
            'Status' => $status,
            'Identificação veicular' => match ($vehicle->presence) {
                'with' => 'Com veículo', 'without' => 'Sem veículo', default => '',
            },
            'Marca' => $vehicle->brandId !== null ? ($this->brand ?: 'Filtro aplicado') : '',
            'Modelo' => $vehicle->model !== null ? 'Filtro de descrição aplicado' : '',
            'Linha' => $vehicle->line !== null
                ? (isset($this->fields['vehicle_line']) ? VehicleMetricsReportTables::lineLabel($vehicle->line) : 'Filtro aplicado') : '',
            'Placa' => $vehicle->plate !== null ? 'Filtro aplicado' : '',
        ], static fn (string $value): bool => $value !== '');
    }

    private function safeFilterName(string $name): string
    {
        $plate = $this->filters->vehicle->plate;
        $compact = static fn (string $value): string => mb_strtoupper(preg_replace('/[^\pL\pN]/u', '', $value) ?? '');

        return $plate !== null && str_contains($compact($name), $compact($plate)) ? '' : $name;
    }
}
