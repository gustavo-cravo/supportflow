<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AnalyticalTicketReportRequest;
use App\Http\Resources\AnalyticalTicketSelectionResource;
use App\Services\Reports\AnalyticalTicketReport;
use App\Services\Reports\PdfAnalyticalTicketReport;
use App\Support\AnalyticalTicketReportCatalog;
use App\Support\TicketMetricsFilters;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class AnalyticalTicketReportController extends Controller
{
    private const HEADERS = ['Cache-Control' => 'private, no-store'];

    public function catalog(): JsonResponse
    {
        return response()->json(['data' => AnalyticalTicketReportCatalog::options()], 200, self::HEADERS);
    }

    public function tickets(AnalyticalTicketReportRequest $request, AnalyticalTicketReport $report): JsonResponse
    {
        $filters = TicketMetricsFilters::fromValidated($request->validated());
        $tickets = $report->eligible($filters, $request->integer('per_page', 20), $request->integer('page', 1));

        return AnalyticalTicketSelectionResource::collection($tickets)->additional(['meta' => ['filters' => $filters->toArray()]])
            ->response()->withHeaders(self::HEADERS);
    }

    public function validateConfiguration(AnalyticalTicketReportRequest $request, AnalyticalTicketReport $report): JsonResponse
    {
        $filters = TicketMetricsFilters::fromValidated($request->validated());
        $data = $report->validateConfiguration($filters, $request->configuration());

        return response()->json(['data' => $data, 'meta' => ['filters' => $filters->toArray()]], 200, self::HEADERS);
    }

    public function pdf(AnalyticalTicketReportRequest $request, PdfAnalyticalTicketReport $pdf): Response|JsonResponse
    {
        $issuedAt = CarbonImmutable::now(TicketMetricsFilters::TIMEZONE);
        $headers = self::HEADERS + ['X-Content-Type-Options' => 'nosniff'];

        try {
            $document = $pdf->generateDocument(TicketMetricsFilters::fromValidated($request->validated()), $request->configuration(), $issuedAt);
            $bytes = $document['bytes'];
            if (! str_starts_with($bytes, '%PDF-') || ! str_ends_with(rtrim($bytes), '%%EOF')) {
                throw new RuntimeException('Invalid analytical PDF.');
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Exception messages can contain query bindings and individual report content.
            Log::error('Falha ao gerar o PDF analítico de atendimentos.', ['exception_type' => $exception::class]);

            return response()->json(['message' => 'Não foi possível gerar o relatório. Tente novamente.'], 500, $headers);
        }

        $disposition = $request->validated('disposition', 'attachment');
        $filename = 'supportflow-relatorio-analitico-'.$issuedAt->format('Y-m-d').'.pdf';

        return response($bytes, 200, $headers + [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'X-Report-Selected-Count' => (string) $document['selected_count'],
        ]);
    }
}
