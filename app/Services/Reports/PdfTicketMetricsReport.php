<?php

namespace App\Services\Reports;

use App\Support\TicketMetricsReportData;
use Dompdf\Dompdf;
use Dompdf\Options;

class PdfTicketMetricsReport
{
    public function render(TicketMetricsReportData $report): string
    {
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
        $pdf->setPaper('A4');
        $charts = array_map(fn (array $rows): array => ['rows' => $rows, 'image' => $this->chart($rows)], array_chunk($report->monthly, 12));
        $pdf->loadHtml(view('reports.ticket-metrics', ['report' => $report, 'charts' => $charts])->render(), 'UTF-8');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        assert($font !== null);
        $canvas->page_text(36, $canvas->get_height() - 27, 'SupportFlow | America/Sao_Paulo', $font, 7.5, [0.39, 0.45, 0.55]);
        $canvas->page_text($canvas->get_width() - 125, $canvas->get_height() - 27, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 7.5, [0.39, 0.45, 0.55]);

        return $pdf->output();
    }

    /** @param list<array{month: string, total: int}> $rows */
    private function chart(array $rows): string
    {
        // Keep labels at a readable size in both full-width and side-by-side charts.
        $compact = count($rows) <= 4;
        $width = $compact ? 340 : 540;
        $height = $compact ? 74 : 114;
        $baseline = $compact ? 48 : 82;
        $amplitude = $compact ? 26 : 58;
        $left = $compact ? 25 : 35;
        $span = $width - 2 * $left;
        $labelY = $compact ? 69 : 103;
        $labelFont = $compact ? 10 : 9;
        if (count($rows) === 1) {
            $height = 54;
            $baseline = 30;
            $amplitude = 10;
            $labelY = 49;
        }
        $maximum = max(1, ...array_column($rows, 'total'));
        $points = [];
        $labels = '';
        foreach ($rows as $index => $row) {
            $x = count($rows) === 1 ? $width / 2 : $left + $index * $span / (count($rows) - 1);
            $y = $baseline - $row['total'] * $amplitude / $maximum;
            $points[] = $x.','.$y;
            $month = substr($row['month'], 5, 2).'/'.substr($row['month'], 0, 4);
            $labels .= '<circle cx="'.$x.'" cy="'.$y.'" r="2.5" fill="#245cda"/><text x="'.$x.'" y="'.($y - 8).'" text-anchor="middle">'.$row['total'].'</text><text x="'.$x.'" y="'.$labelY.'" font-size="'.$labelFont.'" text-anchor="middle" fill="#64748b">'.$month.'</text>';
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'"><g font-family="DejaVu Sans" font-size="10" fill="#28364a"><line x1="'.($left - 10).'" y1="'.$baseline.'" x2="'.($width - $left + 10).'" y2="'.$baseline.'" stroke="#dbe4ef"/><polyline points="'.implode(' ', $points).'" fill="none" stroke="#245cda" stroke-width="2"/>'.$labels.'</g></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
