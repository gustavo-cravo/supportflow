<?php

namespace App\Http\Requests\Api;

use App\Support\TicketMetricsPdfCatalog as Catalog;
use App\Support\TicketMetricsPdfConfiguration;
use App\Support\VehiclePlate;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** @phpstan-import-type ConfigurationInput from TicketMetricsPdfConfiguration */
class TicketMetricsPdfConfigurationRequest extends TicketMetricsRequest
{
    private const INDIVIDUAL_REFERENCE = '/(?<![\pL\pN])(?:'
        .'\#\s*\d+|(?:atendimento|chamado|protocolo)\s*[:#-]?\s*(?:[a-z]{1,12}[-\/])?\d+'
        .'|[\pL\pN._%+\-]+@[\pL\pN.\-]+\.[\pL]{2,}'
        .'|\d{3}\.\d{3}\.\d{3}-\d{2}|\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}|\(\d{2}\)\s*\d{4,5}-?\d{4}'
        .')(?![\pL\pN])/iu';

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $sections = Catalog::sections();
        $rules = parent::rules() + [
            'configuration' => ['bail', 'required', 'array:sections,title,subtitle'],
            'configuration.sections' => ['bail', 'required', 'array:'.implode(',', array_keys($sections))],
            'configuration.title' => ['sometimes', 'array:mode,text'],
            'configuration.title.mode' => ['bail', 'sometimes', 'required', 'string', Rule::in(array_keys(Catalog::TITLE_MODES))],
            'configuration.title.text' => [
                'bail', 'required_if:configuration.title.mode,'.Catalog::CUSTOM_TITLE,
                'prohibited_unless:configuration.title.mode,'.Catalog::CUSTOM_TITLE,
                'nullable', 'string', 'max:'.Catalog::TITLE_LIMIT, $this->plainText(...),
            ],
            'configuration.subtitle' => ['bail', 'nullable', 'string', 'max:'.Catalog::SUBTITLE_LIMIT, $this->plainText(...)],
        ];

        foreach ($sections as $key => $section) {
            $path = 'configuration.sections.'.$key;
            $grouped = $key === Catalog::VEHICLE_PROBLEMS;
            $rules[$path] = ['bail', 'sometimes', 'required', $grouped ? 'array:enabled,indicators,grouping' : 'array:enabled,indicators'];
            $rules[$path.'.enabled'] = ['bail', 'required_with:'.$path, 'boolean:strict'];
            $rules[$path.'.indicators'] = ['sometimes', 'array:'.implode(',', array_keys($section['indicators']))];

            foreach ($section['indicators'] as $id => $option) {
                $indicator = $path.'.indicators.'.$id;
                $hasFormat = $option['formats'] !== [];
                $rules[$indicator] = ['bail', 'sometimes', 'required', $hasFormat ? 'array:enabled,format' : 'array:enabled'];
                $rules[$indicator.'.enabled'] = ['bail', 'required_with:'.$indicator, 'boolean:strict'];
                if ($hasFormat) {
                    $rules[$indicator.'.format'] = ['bail', 'sometimes', 'required', 'string', Rule::in($option['formats'])];
                }
            }
        }

        $grouping = 'configuration.sections.'.Catalog::VEHICLE_PROBLEMS.'.grouping';
        $rules[$grouping] = ['sometimes', 'array:primary,additional'];
        $rules[$grouping.'.additional'] = ['bail', 'sometimes', 'array', 'list', 'max:'.(count(Catalog::GROUPINGS) - 1)];
        foreach ([$grouping.'.primary', $grouping.'.additional.*'] as $group) {
            $rules[$group] = ['bail', 'sometimes', 'required', 'array:by,format'];
            $rules[$group.'.by'] = ['bail', 'required_with:'.$group, 'string', Rule::in(array_keys(Catalog::GROUPINGS))];
            $rules[$group.'.format'] = ['bail', 'sometimes', 'required', 'string', Rule::in(Catalog::GROUPING_FORMATS)];
        }

        return $rules;
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $input = $this->configurationInput();
            $selection = $input['sections'][Catalog::VEHICLE_PROBLEMS] ?? null;
            $primary = $selection['grouping']['primary']['by'] ?? null;
            $additional = array_column($selection['grouping']['additional'] ?? [], 'by');
            $complements = ($selection['enabled'] ?? false)
                && in_array(true, array_column($selection['indicators'] ?? [], 'enabled'), true);
            $path = 'configuration.sections.'.Catalog::VEHICLE_PROBLEMS.'.grouping';

            if ($primary === null && ($additional !== [] || $complements)) {
                $validator->errors()->add($path.'.primary', 'Selecione o agrupamento veicular principal.');
            } elseif (count(array_unique($additional)) !== count($additional) || in_array($primary, $additional, true)) {
                $validator->errors()->add($path.'.additional', 'Selecione cada agrupamento veicular apenas uma vez.');
            } elseif (TicketMetricsPdfConfiguration::fromValidated($input)->sections === []) {
                $validator->errors()->add('configuration.sections', 'Selecione pelo menos um indicador ou agrupamento estatístico.');
            }
        }];
    }

    public function configuration(): TicketMetricsPdfConfiguration
    {
        return TicketMetricsPdfConfiguration::fromValidated($this->configurationInput());
    }

    /** @return ConfigurationInput */
    private function configurationInput(): array
    {
        return $this->validated('configuration');
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'configuration.title.text.required_if' => 'Informe o título personalizado.',
            'configuration.title.text.prohibited_unless' => 'O título personalizado só pode ser informado nesse modo.',
        ] + parent::messages();
    }

    private function plainText(string $attribute, string $value, Closure $fail): void
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/[<>\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $decoded) !== 0) {
            $fail('Informe um texto simples, sem HTML ou caracteres de controle.');
        } elseif (preg_match(VehiclePlate::configuredReportTextPattern(), $decoded) === 1) {
            $fail('Remova a placa do texto do relatório.');
        } elseif (preg_match(self::INDIVIDUAL_REFERENCE, $decoded) === 1) {
            $fail('Remova referências a atendimentos, protocolos ou dados de contato do texto.');
        }
    }
}
