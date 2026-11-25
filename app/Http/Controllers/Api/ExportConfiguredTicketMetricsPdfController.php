<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ExportConfiguredTicketMetricsPdfRequest;
use App\Services\Reports\ConfiguredTicketMetricsPdf;
use App\Support\TicketMetricsFilters;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ExportConfiguredTicketMetricsPdfController extends Controller
{
    public function __invoke(ExportConfiguredTicketMetricsPdfRequest $request, ConfiguredTicketMetricsPdf $pdf): Response|JsonResponse
    {
        $issuedAt = CarbonImmutable::now(TicketMetricsFilters::TIMEZONE);
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

        try {
            $bytes = $pdf->generate(TicketMetricsFilters::fromValidated($request->validated()), $request->configuration(), $issuedAt);
            if (! str_starts_with($bytes, '%PDF-') || ! str_ends_with(rtrim($bytes), '%%EOF')) {
                throw new RuntimeException('Invalid statistical PDF.');
            }
            $disposition = $request->validated('disposition', 'attachment');

            return response($bytes, 200, $headers + [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => $disposition.'; filename="supportflow_relatorio_'.$issuedAt->format('Y-m-d').'.pdf"',
            ]);
        } catch (Throwable $exception) {
            // SQL errors can contain filter bindings and report text.
            Log::error('Falha ao gerar o PDF estatístico configurado.', ['exception_type' => $exception::class]);

            return response()->json(['message' => 'Não foi possível gerar o relatório. Tente novamente.'], 500, $headers);
        }
    }
}
