<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ExportSupportTicketPdfRequest;
use App\Models\SupportTicket;
use App\Services\Reports\PdfSupportTicketReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Throwable;

class ExportSupportTicketPdfController extends Controller
{
    public function __invoke(ExportSupportTicketPdfRequest $request, SupportTicket $supportTicket, PdfSupportTicketReport $pdf): Response|JsonResponse
    {
        $generatedAt = now()->toImmutable();
        $number = str_pad((string) $supportTicket->id, 4, '0', STR_PAD_LEFT);

        try {
            $bytes = $pdf->render($supportTicket, $request->validated(), $generatedAt, $number);

            return response($bytes, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="supportflow-atendimento-'.$number.'-'.$generatedAt->format('Y-m-d').'.pdf"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Não foi possível gerar o PDF do atendimento. Tente novamente.'], 500);
        }
    }
}
