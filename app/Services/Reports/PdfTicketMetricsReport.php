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
        $canvas->page_text(40, $canvas->get_height() - 29, 'SupportFlow | Relatório estatístico', $font, 8, [0.46, 0.50, 0.59]);
        $canvas->page_text($canvas->get_width() - 126, $canvas->get_height() - 29, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 8, [0.46, 0.50, 0.59]);

        return $pdf->output();
    }

    /** @param list<array{month: string, total: int}> $rows */
    private function chart(array $rows): string
    {
        $maximum = max(1, ...array_column($rows, 'total'));
        $points = [];
        $labels = '';
        foreach ($rows as $index => $row) {
            $x = count($rows) === 1 ? 270 : 35 + $index * 470 / (count($rows) - 1);
            $y = 125 - $row['total'] * 90 / $maximum;
            $points[] = $x.','.$y;
            $month = substr($row['month'], 5, 2).'/'.substr($row['month'], 0, 4);
            $labels .= '<circle cx="'.$x.'" cy="'.$y.'" r="3" fill="#245cda"/><text x="'.$x.'" y="'.($y - 10).'" text-anchor="middle">'.$row['total'].'</text><text x="'.$x.'" y="149" text-anchor="middle" fill="#758096">'.$month.'</text>';
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="540" height="165" viewBox="0 0 540 165"><g font-family="DejaVu Sans" font-size="10" fill="#202b40"><line x1="25" y1="125" x2="515" y2="125" stroke="#e9edf4"/><polyline points="'.implode(' ', $points).'" fill="none" stroke="#245cda" stroke-width="2"/>'.$labels.'</g></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
