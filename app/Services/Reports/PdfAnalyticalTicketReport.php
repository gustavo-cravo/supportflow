<?php

namespace App\Services\Reports;

use App\Support\AnalyticalTicketReportConfiguration;
use App\Support\TicketMetricsFilters;
use Carbon\CarbonImmutable;
use DOMElement;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Frame;
use Dompdf\Options;
use DOMText;
use LogicException;

final class PdfAnalyticalTicketReport
{
    public const NAME = 'Relatório analítico de atendimentos';

    public function __construct(private AnalyticalTicketReport $report, private AnalyticalTicketPdfLayout $layout) {}

    public function generate(TicketMetricsFilters $filters, AnalyticalTicketReportConfiguration $configuration, ?CarbonImmutable $issuedAt = null): string
    {
        return $this->generateDocument($filters, $configuration, $issuedAt)['bytes'];
    }

    /** @return array{bytes: string, selected_count: int} */
    public function generateDocument(TicketMetricsFilters $filters, AnalyticalTicketReportConfiguration $configuration, ?CarbonImmutable $issuedAt = null): array
    {
        $document = $this->report->prepare($filters, $configuration, $issuedAt)['document'];
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
        $pdf->addInfo('Author', 'SupportFlow');
        $pdf->addInfo('Subject', self::NAME);

        $firstTicket = [];
        $pdf->setCallbacks([['event' => 'begin_frame', 'f' => static function (Frame $frame, Canvas $canvas) use (&$firstTicket): void {
            $page = $canvas->get_page_number();
            if (isset($firstTicket[$page])) {
                return;
            }
            $node = $frame->get_node();
            if (! $node instanceof DOMText || trim($node->data) === '') {
                return;
            }

            // A split article retains its attributes. Only actual text determines which ticket starts the page.
            $heading = false;
            for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
                $heading = $heading || $parent->hasAttribute('data-ticket-start');
                if ($parent->hasAttribute('data-ticket-number')) {
                    $firstTicket[$page] = $heading ? [] : [
                        'number' => $parent->getAttribute('data-ticket-number'),
                        'group' => (int) $parent->getAttribute('data-group-index'),
                    ];
                    break;
                }
            }
        }]]);
        $pdf->loadHtml(view('reports.analytical-ticket-report', [
            'document' => $document,
            'layout' => $this->layout,
            'issued' => CarbonImmutable::parse($document['generated_at'])->format('d/m/Y H:i'),
        ])->render(), 'UTF-8');
        $pdf->render();

        $groupLabels = array_column($document['groups'], 'label');
        $pdf->getCanvas()->page_script(function (int $page, int $pages, Canvas $canvas, FontMetrics $fonts) use ($firstTicket, $groupLabels): void {
            $font = $fonts->getFont('DejaVu Sans');
            if ($font === null) {
                throw new LogicException('DejaVu Sans is required for the analytical ticket report.');
            }
            $ink = [0.32, 0.39, 0.49];
            $left = 38;
            $right = $canvas->get_width() - $left;
            $y = $canvas->get_height() - 28;
            $canvas->line($left, $y - 8, $right, $y - 8, [0.86, 0.89, 0.93], .55);
            $canvas->text($left, $y, 'SupportFlow | '.self::NAME, $font, 8, $ink);
            $pageLabel = 'Página '.$page.' de '.$pages;
            $canvas->text($right - $fonts->getTextWidth($pageLabel, $font, 8), $y, $pageLabel, $font, 8, $ink);
            if (! empty($firstTicket[$page])) {
                $ticket = $firstTicket[$page];
                $label = 'Atendimento '.$ticket['number'].' - Continuação';
                $canvas->text($left, 21, $label, $font, 9, $ink);
                $group = $groupLabels[$ticket['group']] ?? null;
                if ($group !== null) {
                    $space = $right - $left - $fonts->getTextWidth($label, $font, 9) - 18;
                    $caption = $this->fitGroupLabel($group, $fonts, $font, $space);
                    $canvas->text($right - $fonts->getTextWidth($caption, $font, 8), 22, $caption, $font, 8, $ink);
                }
                $canvas->line($left, 38, $right, 38, [0.86, 0.89, 0.93], .55);
            }
        });

        return ['bytes' => $pdf->output(), 'selected_count' => $document['ticket_count']];
    }

    private function fitGroupLabel(string $group, FontMetrics $fonts, string $font, float $width): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', $group) ?? $group);
        if ($fonts->getTextWidth($label, $font, 8) <= $width) {
            return $label;
        }
        $length = min(mb_strlen($label), 140);
        do {
            $caption = rtrim(mb_substr($label, 0, --$length)).'…';
        } while ($length > 0 && $fonts->getTextWidth($caption, $font, 8) > $width);

        return $caption;
    }
}
