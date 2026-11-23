<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ExportTicketMetricsRequest;
use App\Services\Reports\PdfTicketMetricsReport;
use App\Services\Reports\TicketMetricsReport;
use App\Services\Reports\XlsxTicketMetricsReport;
use App\Support\TicketMetricsFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Throwable;

class ExportTicketMetricsController extends Controller
{
    public function __invoke(ExportTicketMetricsRequest $request, TicketMetricsReport $reports, PdfTicketMetricsReport $pdf, XlsxTicketMetricsReport $xlsx): Response|JsonResponse
    {
        try {
            $report = $reports->build(TicketMetricsFilters::fromValidated($request->validated()));
            $format = $request->input('format');
            $bytes = $format === 'pdf' ? $pdf->render($report) : $xlsx->render($report);

            return response($bytes, 200, [
                'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="supportflow_relatorio_'.$report->generatedAt->format('Y-m-d').'.'.$format.'"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Não foi possível gerar o relatório. Tente novamente.'], 500);
        }
    }
}
