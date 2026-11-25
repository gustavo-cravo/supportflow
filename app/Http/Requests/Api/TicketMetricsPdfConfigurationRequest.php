<?php

namespace App\Http\Requests\Api;

use App\Support\ReportHeadingText;
use App\Support\TicketMetricsPdfCatalog as Catalog;
use App\Support\TicketMetricsPdfConfiguration;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** @phpstan-import-type ConfigurationInput from TicketMetricsPdfConfiguration */
class TicketMetricsPdfConfigurationRequest extends TicketMetricsRequest
{
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
                'nullable', 'string', 'max:'.Catalog::TITLE_LIMIT, ReportHeadingText::validate(...),
            ],
            'configuration.subtitle' => ['bail', 'nullable', 'string', 'max:'.Catalog::SUBTITLE_LIMIT, ReportHeadingText::validate(...)],
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
}
