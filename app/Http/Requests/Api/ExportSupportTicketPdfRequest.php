<?php

namespace App\Http\Requests\Api;

class ExportSupportTicketPdfRequest extends ApiRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'include_classification' => ['sometimes', 'boolean:strict'],
            'include_vehicle' => ['sometimes', 'boolean:strict'],
            'include_resolution' => ['sometimes', 'boolean:strict'],
            'include_pending_tasks' => ['sometimes', 'boolean:strict'],
            'include_reminders' => ['sometimes', 'boolean:strict'],
            'include_related_tickets' => ['sometimes', 'boolean:strict'],
        ];
    }
}
