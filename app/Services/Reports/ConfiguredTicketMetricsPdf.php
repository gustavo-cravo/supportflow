<?php

namespace App\Services\Reports;

use App\Services\TicketMetrics;
use App\Services\VehicleMetrics;
use App\Support\ReportPlateRedaction;
use App\Support\TicketMetricsFilters;
use App\Support\TicketMetricsPdfCatalog;
use App\Support\TicketMetricsPdfConfiguration;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ConfiguredTicketMetricsPdf
{
    public function __construct(
        private TicketMetrics $metrics,
        private VehicleMetrics $vehicles,
        private VehicleProblemsPdfProjection $vehicleProblems,
        private TicketMetricsPdfLayout $layout,
    ) {}

    public function generate(TicketMetricsFilters $filters, TicketMetricsPdfConfiguration $configuration, ?CarbonImmutable $issuedAt = null): string
    {
        $document = $this->prepare($filters, $configuration, $issuedAt);
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsFontSubsettingEnabled(true);
        $options->setTempDir(sys_get_temp_dir());
        $options->setFontCache(sys_get_temp_dir());
        $options->setChroot([base_path('vendor/dompdf/dompdf'), resource_path('views/reports')]);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'portrait');
        $pdf->loadHtml(view('reports.configured-ticket-metrics', ['document' => $document, 'layout' => $this->layout])->render(), 'UTF-8');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        assert($font !== null);
        $y = $canvas->get_height() - 28;
        $ink = [0.32, 0.39, 0.49];
        $canvas->page_line(38, $y - 8, $canvas->get_width() - 38, $y - 8, [0.86, 0.89, 0.93], .55);
        $canvas->page_text(38, $y, 'SupportFlow | Relatório estatístico', $font, 8, $ink);
        $canvas->page_text($canvas->get_width() - 128, $y, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 8, $ink);

        return $pdf->output();
    }

    /** @return array{title: string, subtitle: ?string, issued: string, period: string, criteria: list<array{label: string, name: string}>, empty: bool, sections: list<array{label: string, blocks: list<array<string, mixed>>}>} */
    public function prepare(TicketMetricsFilters $filters, TicketMetricsPdfConfiguration $configuration, ?CarbonImmutable $issuedAt = null): array
    {
        if ($configuration->sections === []) {
            throw new LogicException('A configured statistical PDF requires an effective selection.');
        }

        $issuedAt ??= CarbonImmutable::now(TicketMetricsFilters::TIMEZONE);

        return DB::transaction(function () use ($filters, $configuration, $issuedAt): array {
            $context = new TicketMetricsPdfContext($filters);
            $summary = $this->metrics->summary($filters);
            $vehicleSelection = $configuration->sections[TicketMetricsPdfCatalog::VEHICLE_PROBLEMS]['grouping'] ?? null;
            $groups = $vehicleSelection === null ? [] : [$vehicleSelection['primary'], ...$vehicleSelection['additional']];
            $vehicleData = isset($configuration->sections['vehicles']) || $groups !== []
                ? $this->vehicles->build($filters, includeVehicleRanking: false, problemDimensions: array_column($groups, 'by')) : [];
            $sections = [];

            foreach (TicketMetricsPdfCatalog::sections() as $key => $section) {
                $selection = $configuration->sections[$key]['indicators'] ?? null;
                if ($selection === null) {
                    continue;
                }
                $projection = new TicketMetricsPdfProjection($selection);
                $blocks = match ($key) {
                    'summary' => $projection->summary($summary),
                    'systems' => $projection->systems($summary,
                        isset($selection['tickets_by_system']) || isset($selection['system_percentage']) ? $this->metrics->systems($filters) : []),
                    'categories' => $this->categories($filters, $projection, $selection),
                    'vehicles' => $projection->vehicles($vehicleData),
                    TicketMetricsPdfCatalog::VEHICLE_PROBLEMS => array_map(
                        fn (array $group): array => $this->vehicleProblems->build($group, $selection, $vehicleData['problems'][$group['by']]), $groups,
                    ),
                    default => throw new LogicException('No projection for statistical PDF section '.$key),
                };
                $sections[] = ['label' => $section['label'], 'blocks' => $blocks];
            }

            $criteria = [];
            foreach ($context->criteria() as $label => $name) {
                $criteria[] = ['label' => $label, 'name' => $name];
            }

            return ReportPlateRedaction::redact([
                'title' => $context->title($configuration),
                'subtitle' => $configuration->subtitle,
                'issued' => $issuedAt->setTimezone(TicketMetricsFilters::TIMEZONE)->format('d/m/Y H:i'),
                'period' => $context->period(),
                'criteria' => $criteria,
                'empty' => $summary['total'] === 0,
                'sections' => $sections,
            ], $filters->vehicle->plate, typographicSeparators: true);
        });
    }

    /**
     * @param  array<string, array{enabled: true, format?: string}>  $selection
     * @return list<array<string, mixed>>
     */
    private function categories(TicketMetricsFilters $filters, TicketMetricsPdfProjection $projection, array $selection): array
    {
        $categories = $this->metrics->categories($filters);
        $problems = array_diff(array_keys($selection), ['tickets_by_category']) !== []
            ? $this->metrics->subcategories($filters, array_column($categories, 'total', 'problem_category_id')) : [];

        return $projection->categories($categories, $problems);
    }
}
