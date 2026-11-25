<?php

namespace App\Services\Reports;

use App\Enums\TicketStatus;
use App\Models\SupportTicket;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;

class PdfSupportTicketReport
{
    /** @param array<string, bool> $sections */
    public function render(SupportTicket $ticket, array $sections, CarbonImmutable $generatedAt, string $number): string
    {
        $relations = ['system:id,name', 'contact:id,name', 'customer:id,name'];
        $optionalRelations = [
            'include_classification' => ['problemCategory:id,name', 'problemSubcategories:id,name'],
            'include_vehicle' => ['vehicle:id,brand_id,plate,model_description,vehicle_line', 'vehicle.brand:id,name'],
            'include_pending_tasks' => ['pendingTasks:id,support_ticket_id,description,status,created_at,due_at,completed_at'],
            'include_reminders' => ['reminders:id,support_ticket_id,title,description,scheduled_at,completed_at'],
            'include_related_tickets' => [
                'previousTicket:id,system_id,title,status,created_at', 'previousTicket.system:id,name',
                'followUpTickets:id,previous_ticket_id,system_id,title,status,created_at', 'followUpTickets.system:id,name',
            ],
        ];
        foreach ($optionalRelations as $section => $selectedRelations) {
            if ($sections[$section] ?? true) {
                array_push($relations, ...$selectedRelations);
            }
        }
        $ticket->load($relations);

        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsFontSubsettingEnabled(true);
        $options->setTempDir(sys_get_temp_dir());
        $options->setFontCache(sys_get_temp_dir());
        $options->setChroot([base_path('vendor/dompdf/dompdf'), resource_path('views/reports')]);

        $timezone = $generatedAt->getTimezone();
        $date = static fn (?CarbonInterface $value, string $empty = 'Não informado'): string => $value
            ? $value->copy()->setTimezone($timezone)->format('d/m/Y H:i')
            : $empty;
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'portrait');
        $pdf->loadHtml(view('reports.support-ticket', [
            'ticket' => $ticket,
            'number' => $number,
            'sections' => $sections,
            'generatedAt' => $generatedAt,
            'date' => $date,
            'statusLabel' => $this->statusLabel(...),
        ])->render(), 'UTF-8');
        $pdf->render();

        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        assert($font !== null);
        $ink = [0.39, 0.45, 0.55];
        $y = $canvas->get_height() - 26;
        $canvas->page_text(36, $y, 'SupportFlow | Atendimento #'.$number.' | Gerado em '.$date($generatedAt), $font, 7.5, $ink);
        $canvas->page_text($canvas->get_width() - 119, $y, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 7.5, $ink);

        return $pdf->output();
    }

    private function statusLabel(string $status): string
    {
        return match (TicketStatus::tryFrom($status)) {
            TicketStatus::Open => 'Aberto',
            TicketStatus::InProgress => 'Em andamento',
            TicketStatus::Waiting => 'Aguardando retorno',
            TicketStatus::Resolved => 'Resolvido',
            TicketStatus::Closed => 'Encerrado',
            null => $status,
        };
    }
}
