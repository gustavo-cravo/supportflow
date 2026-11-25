<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

class ExportTicketMetricsRequest extends TicketMetricsRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return parent::rules() + [
            'format' => ['required', Rule::in(['pdf', 'xlsx'])],
            'include_vehicle_statistics' => ['sometimes', 'boolean'],
            'configuration' => ['missing_if:format,pdf'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'configuration.missing_if' => 'Use a rota de exportação de PDF configurável para enviar as opções do relatório.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['format' => 'formato', 'include_vehicle_statistics' => 'incluir estatísticas veiculares'];
    }
}
