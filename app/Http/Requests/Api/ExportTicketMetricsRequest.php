<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

class ExportTicketMetricsRequest extends TicketMetricsRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return parent::rules() + ['format' => ['required', Rule::in(['pdf', 'xlsx'])]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['format' => 'formato'];
    }
}
