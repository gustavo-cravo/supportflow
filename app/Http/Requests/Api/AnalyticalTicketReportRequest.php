<?php

namespace App\Http\Requests\Api;

use App\Support\AnalyticalTicketReportCatalog as Catalog;
use App\Support\AnalyticalTicketReportConfiguration;
use App\Support\ReportHeadingText;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** @phpstan-import-type ConfigurationInput from AnalyticalTicketReportConfiguration */
final class AnalyticalTicketReportRequest extends TicketMetricsRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $filters = parent::rules();
        if ($this->isMethod('GET')) {
            return array_replace($filters, [
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);
        }

        $sections = Catalog::sections();
        $rules = $filters + [
            'configuration' => ['sometimes', 'array:selection,sections,grouping,order,empty_fields,title,subtitle,layout'],
            'configuration.selection' => ['sometimes', 'required', 'array:mode,ids'],
            'configuration.selection.mode' => ['required_with:configuration.selection', 'string', Rule::in(array_keys(Catalog::SELECTION_MODES))],
            'configuration.selection.ids' => ['sometimes', 'array', 'list'],
            'configuration.selection.ids.*' => ['bail', 'required', 'integer:strict', 'min:1'],
            'configuration.sections' => ['sometimes', 'array:'.implode(',', array_keys($sections))],
            'configuration.grouping' => ['sometimes', 'string', Rule::in(array_keys(Catalog::GROUPINGS))],
            'configuration.order' => ['sometimes', 'string', Rule::in(array_keys(Catalog::ORDERS))],
            'configuration.empty_fields' => ['sometimes', 'string', Rule::in(array_keys(Catalog::EMPTY_MODES))],
            'configuration.title' => ['sometimes', 'array:mode,text'],
            'configuration.title.mode' => ['sometimes', 'string', Rule::in(array_keys(Catalog::TITLE_MODES))],
            'configuration.title.text' => ['bail', 'required_if:configuration.title.mode,custom', 'prohibited_unless:configuration.title.mode,custom',
                'nullable', 'string', 'max:'.Catalog::TITLE_LIMIT, ReportHeadingText::validate(...)],
            'configuration.subtitle' => ['bail', 'nullable', 'string', 'max:'.Catalog::SUBTITLE_LIMIT, ReportHeadingText::validate(...)],
            'configuration.layout' => ['sometimes', 'array:pagination,columns,spacing'],
            'configuration.layout.pagination' => ['sometimes', 'string', Rule::in(array_keys(Catalog::PAGINATION))],
            'configuration.layout.columns' => ['sometimes', 'string', Rule::in(array_keys(Catalog::COLUMNS))],
            'configuration.layout.spacing' => ['sometimes', 'string', Rule::in(array_keys(Catalog::SPACING))],
        ];
        if ($this->routeIs('metrics.analytical-report.pdf')) {
            $rules['disposition'] = ['sometimes', 'string', Rule::in(['inline', 'attachment'])];
        }
        foreach ($sections as $id => $section) {
            $path = 'configuration.sections.'.$id;
            $rules[$path] = ['sometimes', 'required', 'array:enabled,fields'];
            $rules[$path.'.enabled'] = ['required_with:'.$path, 'boolean:strict'];
            $rules[$path.'.fields'] = ['sometimes', 'array', 'list'];
            $rules[$path.'.fields.*'] = ['required', 'string', Rule::in(array_keys($section['fields']))];
        }

        return $rules;
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            $known = $validator->getRules();
            foreach (array_diff_key($this->all(), $known) as $key => $value) {
                $validator->errors()->add((string) $key, 'A opção informada não é aceita nesta operação.');
            }
            if ($this->isMethod('GET') || $validator->errors()->isNotEmpty()) {
                return;
            }

            $configuration = $this->configuration();
            if ($configuration->sections === []) {
                $validator->errors()->add('configuration.sections', 'Selecione pelo menos um campo para o relatório.');
            } elseif (array_diff(Catalog::GROUPINGS[$configuration->grouping]['required_fields'], $configuration->fields()) !== []) {
                $validator->errors()->add('configuration.grouping', 'Selecione os campos necessários para identificar o agrupamento escolhido.');
            }
            $selection = $configuration->selection;
            if ($selection['mode'] === 'all' && $selection['ids'] !== []) {
                $validator->errors()->add('configuration.selection.ids', 'Selecione todos pelo recorte, sem enviar identificadores.');
            } elseif ($selection['mode'] !== 'all' && $selection['ids'] === []) {
                $validator->errors()->add('configuration.selection.ids', 'Informe pelo menos um atendimento para o modo escolhido.');
            }
        }];
    }

    public function configuration(): AnalyticalTicketReportConfiguration
    {
        /** @var ConfigurationInput $input */
        $input = $this->validated('configuration', []);

        return AnalyticalTicketReportConfiguration::fromValidated($input);
    }
}
