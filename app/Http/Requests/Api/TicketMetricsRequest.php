<?php

namespace App\Http\Requests\Api;

use App\Enums\TicketStatus;
use App\Models\ProblemSubcategory;
use App\Support\VehicleTicketFilters;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TicketMetricsRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('vehicle_plate')) {
            $this->merge(['vehicle_plate' => VehicleTicketFilters::normalizePlate($this->input('vehicle_plate'))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $tickets = $this->routeIs('metrics.tickets');
        $vehicleRules = VehicleTicketFilters::rules();
        $vehicleRules['vehicle_brand_id'][] = 'exists:vehicle_brands,id';

        return [
            ...$vehicleRules,
            'group_vehicle_id' => $tickets
                ? ['bail', 'sometimes', 'prohibited_if:vehicle_presence,without', 'required', 'integer', 'min:1', 'exists:vehicles,id']
                : ['prohibited'],
            'group_vehicle_model' => $tickets
                ? ['bail', 'sometimes', 'prohibited_if:vehicle_presence,without', 'required', 'string', 'regex:/\A(?:missing|[0-9a-f]{64})\z/']
                : ['prohibited'],
            'period' => ['sometimes', 'required', Rule::in(['this_month', 'last_30_days', 'last_3_months', 'last_6_months', 'this_year', 'all', 'custom'])],
            'date_from' => ['bail', 'required_if:period,custom', 'prohibited_unless:period,custom', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before:9999-12-31'],
            'date_to' => ['bail', 'required_if:period,custom', 'prohibited_unless:period,custom', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before:9999-12-31'],
            'system_id' => ['bail', 'nullable', 'integer', 'min:1', 'exists:systems,id'],
            'status' => ['sometimes', 'required', Rule::in(['all', 'not_closed', ...array_column(TicketStatus::cases(), 'value')])],
            'problem_category_id' => ['bail', 'nullable', 'integer', 'min:1', 'exists:problem_categories,id'],
            'problem_subcategory_id' => ['bail', 'nullable', 'integer', 'min:1', 'exists:problem_subcategories,id'],
            'classification' => ['sometimes', 'required', Rule::in(['all', 'unclassified', 'without_subcategory'])],
            'page' => $this->routeIs('metrics.tickets') ? ['sometimes', 'integer', 'min:1'] : ['prohibited'],
            'per_page' => $this->routeIs('metrics.tickets') ? ['sometimes', 'integer', 'min:1', 'max:100'] : ['prohibited'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->filled('group_vehicle_model') && ! $this->filled('vehicle_brand_id')) {
                $validator->errors()->add('vehicle_brand_id', 'Informe a marca para detalhar o modelo.');
            }
            if ($this->input('classification') === 'unclassified' && ($this->filled('problem_category_id') || $this->filled('problem_subcategory_id'))) {
                $validator->errors()->add('classification', 'Sem classificação não pode ser combinado com uma categoria ou subcategoria específica.');
            }
            if ($this->input('classification') === 'without_subcategory' && $this->filled('problem_subcategory_id')) {
                $validator->errors()->add('classification', 'Sem subcategoria não pode ser combinado com uma subcategoria específica.');
            }
            if ($this->filled('problem_category_id') && $this->filled('problem_subcategory_id') && ! ProblemSubcategory::query()
                ->whereKey($this->integer('problem_subcategory_id'))->where('problem_category_id', $this->integer('problem_category_id'))->exists()) {
                $validator->errors()->add('problem_subcategory_id', 'A subcategoria deve pertencer à categoria selecionada.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'required_if' => 'Informe :attribute para o período personalizado.',
            'prohibited_unless' => 'Datas inicial e final só podem ser informadas no período personalizado.',
            'after_or_equal' => 'A data final deve ser igual ou posterior à inicial, e as datas devem estar no intervalo suportado.',
            'before' => 'Informe uma data anterior a 31/12/9999.',
            'vehicle_plate.regex' => 'Informe até sete letras ou números da placa.',
            'prohibited_if' => VehicleTicketFilters::CONTRADICTION,
        ];
    }
}
