<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

class ExportConfiguredTicketMetricsPdfRequest extends TicketMetricsPdfConfigurationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return parent::rules() + [
            'disposition' => ['bail', 'sometimes', 'required', 'string', Rule::in(['inline', 'attachment'])],
        ];
    }
}
