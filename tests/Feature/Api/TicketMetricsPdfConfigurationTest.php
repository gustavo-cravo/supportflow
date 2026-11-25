<?php

use Tests\Support\VehicleMetricsFixture;

function metricsPdfConfigurationUrl(): string
{
    return '/api/metrics/export/pdf/configuration';
}

function metricsPdfSummarySelection(string $indicator = 'total', array $options = ['enabled' => true]): array
{
    return ['sections' => ['summary' => ['enabled' => true, 'indicators' => [$indicator => $options]]]];
}

test('publishes the catalog and accepts all five sections and all their indicators', function () {
    $catalog = $this->getJson(metricsPdfConfigurationUrl())->assertOk()->json('data');
    expect(array_keys($catalog['sections']))->toBe(['summary', 'systems', 'categories', 'vehicles', 'vehicle_problems'])
        ->and(array_keys($catalog['formats']))->toBe(['automatic', 'indicator', 'horizontal_bar', 'vertical_bar', 'doughnut', 'table', 'text', 'descriptive_group'])
        ->and($catalog['defaults']['enabled'])->toBeFalse()
        ->and($catalog['vehicle_grouping']['activation_primary'])->toBe('brand');
    $sections = [];
    foreach ($catalog['sections'] as $key => $section) {
        expect($section['activation_indicators'])->toBe(array_keys($section['indicators']));
        $sections[$key] = ['enabled' => true, 'indicators' => array_fill_keys($section['activation_indicators'], ['enabled' => true])];
    }
    $sections['vehicle_problems']['grouping']['primary'] = ['by' => $catalog['vehicle_grouping']['activation_primary']];
    $effective = $this->postJson(metricsPdfConfigurationUrl(), ['configuration' => ['sections' => $sections]])
        ->assertOk()->assertJsonCount(5, 'data.sections')
        ->assertJsonPath('data.sections.vehicle_problems.grouping', ['primary' => ['by' => 'brand', 'format' => 'automatic'], 'additional' => []])
        ->assertJsonPath('data.title', ['mode' => 'automatic', 'text' => null])
        ->assertJsonPath('data.subtitle', null)->json('data');

    foreach ($sections as $key => $section) {
        expect(array_keys($effective['sections'][$key]['indicators']))->toBe(array_keys($section['indicators']));
    }
    $this->postJson(metricsPdfConfigurationUrl(), ['configuration' => $effective])->assertOk()->assertExactJson(['data' => $effective]);
});

test('normalizes only selected indicators and does not enable calculation inputs or empty sections', function () {
    $configuration = ['sections' => [
        'summary' => ['enabled' => true, 'indicators' => [
            'total' => ['enabled' => false], 'closed' => ['enabled' => false, 'format' => 'table'],
            'closed_percentage' => ['enabled' => true, 'format' => 'indicator'],
        ]],
        'systems' => ['enabled' => false, 'indicators' => ['systems_count' => ['enabled' => true]]],
        'categories' => ['enabled' => true, 'indicators' => ['subcategory_occurrences' => ['enabled' => false]]],
        'vehicle_problems' => ['enabled' => false, 'grouping' => ['primary' => ['by' => 'brand']]],
        'vehicles' => ['enabled' => true],
    ]];
    $this->postJson(metricsPdfConfigurationUrl(), ['configuration' => $configuration])->assertOk()->assertExactJson(['data' => [
        'sections' => ['summary' => ['enabled' => true, 'indicators' => ['closed_percentage' => ['enabled' => true, 'format' => 'indicator']]]],
        'title' => ['mode' => 'automatic', 'text' => null],
        'subtitle' => null,
    ]]);

    $catalog = $this->getJson(metricsPdfConfigurationUrl())->assertOk()->json('data');
    expect($catalog['sections']['summary']['indicators']['closed_percentage']['calculation_inputs'])->toBe(['closed', 'total'])
        ->and($catalog['sections']['summary']['indicators']['text_summary']['calculation_inputs'])->toBe(['selected_summary_indicators'])
        ->and($catalog['sections']['vehicles']['indicators']['brands']['semantic_fields'])->toBe(['brand_name']);

    $this->postJson(metricsPdfConfigurationUrl(), ['configuration' => metricsPdfSummarySelection('text_summary')])
        ->assertOk()->assertJsonPath('data.sections.summary.indicators', ['text_summary' => ['enabled' => true, 'format' => 'automatic']]);
});

test('rejects configuration that is absent or has no effective content', function (array $payload, string $field) {
    $this->postJson(metricsPdfConfigurationUrl(), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'absent' => [[], 'configuration'],
    'null' => [['configuration' => null], 'configuration'],
    'empty' => [['configuration' => []], 'configuration'],
    'no sections' => [['configuration' => ['sections' => []]], 'configuration.sections'],
    'disabled section' => [['configuration' => ['sections' => ['summary' => ['enabled' => false, 'indicators' => ['total' => ['enabled' => true]]]]]], 'configuration.sections'],
    'empty section' => [['configuration' => ['sections' => ['summary' => ['enabled' => true]]]], 'configuration.sections'],
    'disabled indicator' => [['configuration' => metricsPdfSummarySelection(options: ['enabled' => false])], 'configuration.sections'],
    'no grouping' => [['configuration' => ['sections' => ['vehicle_problems' => ['enabled' => true]]]], 'configuration.sections'],
]);

test('rejects unknown fields even in inactive selections and requires actual boolean flags', function (array $configuration, string $field) {
    $this->postJson(metricsPdfConfigurationUrl(), ['configuration' => $configuration])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown section' => [['sections' => ['unknown' => ['enabled' => false]]], 'configuration.sections'],
    'unknown indicator' => [['sections' => ['summary' => ['enabled' => false, 'indicators' => ['unknown' => ['enabled' => false]]]]], 'configuration.sections.summary.indicators'],
    'individual fields' => [[...metricsPdfSummarySelection(), 'ticket_id' => 12, 'vehicle_id' => 34, 'plate' => 'HFS4456'], 'configuration'],
    'non-boolean section' => [['sections' => ['summary' => ['enabled' => 'true']]], 'configuration.sections.summary.enabled'],
    'non-boolean indicator' => [metricsPdfSummarySelection(options: ['enabled' => 1]), 'configuration.sections.summary.indicators.total.enabled'],
    'missing indicator flag' => [metricsPdfSummarySelection(options: ['format' => 'indicator']), 'configuration.sections.summary.indicators.total.enabled'],
]);

test('accepts every advertised compatible indicator and group format', function () {
    $catalog = $this->getJson(metricsPdfConfigurationUrl())->assertOk()->json('data');
    foreach ($catalog['sections'] as $section => $definition) {
        foreach ($definition['indicators'] as $key => $indicator) {
            foreach ($indicator['formats'] as $format) {
                $this->postJson(metricsPdfConfigurationUrl(), ['configuration' => ['sections' => [
                    $section => ['enabled' => true, 'indicators' => [$key => ['enabled' => true, 'format' => $format]]],
                ]]])->assertOk()->assertJsonPath("data.sections.$section.indicators.$key.format", $format);
            }
        }
    }
    foreach ($catalog['vehicle_grouping']['formats'] as $format) {
        $this->postJson(metricsPdfConfigurationUrl(), ['configuration' => ['sections' => [
            'vehicle_problems' => ['enabled' => true, 'grouping' => ['primary' => ['by' => 'model', 'format' => $format]]],
        ]]])->assertOk()->assertJsonPath('data.sections.vehicle_problems.grouping.primary.format', $format);
    }
});

test('rejects unknown or incompatible formats including doughnuts for overlapping categories of problems', function (string $section, string $indicator, string $format, string $field) {
    $this->postJson(metricsPdfConfigurationUrl(), ['configuration' => ['sections' => [
        $section => ['enabled' => true, 'indicators' => [$indicator => ['enabled' => false, 'format' => $format]]],
    ]]])->assertUnprocessable()->assertJsonValidationErrors("configuration.sections.$section.indicators.$indicator$field");
})->with([
    ['summary', 'total', 'unknown', '.format'],
    ['summary', 'total', 'horizontal_bar', '.format'],
    ['summary', 'text_summary', 'indicator', '.format'],
    ['systems', 'system_percentage', 'doughnut', '.format'],
    ['categories', 'subcategory_percentage', 'doughnut', '.format'],
    ['categories', 'subcategory_occurrences', 'doughnut', '.format'],
    ['vehicles', 'recurrence', 'vertical_bar', '.format'],
    ['vehicle_problems', 'systems', 'table', ''],
]);

test('preserves the grouping order and uses only the requested dimensions and complements', function () {
    $section = ['enabled' => true, 'indicators' => [
        'counts' => ['enabled' => true], 'systems' => ['enabled' => false],
    ], 'grouping' => [
        'primary' => ['by' => 'model', 'format' => 'descriptive_group'],
        'additional' => [['by' => 'line'], ['by' => 'brand', 'format' => 'table']],
    ]];
    $this->postJson(metricsPdfConfigurationUrl(), ['configuration' => ['sections' => ['vehicle_problems' => $section]]])
        ->assertOk()->assertJsonPath('data.sections.vehicle_problems', [
            'enabled' => true, 'indicators' => ['counts' => ['enabled' => true]], 'grouping' => [
                'primary' => ['by' => 'model', 'format' => 'descriptive_group'],
                'additional' => [['by' => 'line', 'format' => 'automatic'], ['by' => 'brand', 'format' => 'table']],
            ],
        ]);

    $this->getJson(metricsPdfConfigurationUrl())->assertOk()
        ->assertJsonPath('data.vehicle_grouping.dimensions.model.semantic_fields', ['brand_name', 'model_description']);
});

test('validates grouping identifiers, compatibility and the dependency on a primary dimension', function (array $grouping, array $indicators, string $field) {
    $this->postJson(metricsPdfConfigurationUrl(), ['configuration' => ['sections' => [
        'vehicle_problems' => ['enabled' => true, 'indicators' => $indicators, 'grouping' => $grouping],
    ]]])->assertUnprocessable()->assertJsonValidationErrors('configuration.sections.vehicle_problems.grouping.'.$field);
})->with([
    'unknown primary' => [['primary' => ['by' => 'plate']], [], 'primary.by'],
    'unknown additional' => [['primary' => ['by' => 'brand'], 'additional' => [['by' => 'vehicle_id']]], [], 'additional.0.by'],
    'missing dimension' => [['primary' => ['format' => 'table']], [], 'primary.by'],
    'incompatible presentation' => [['primary' => ['by' => 'line', 'format' => 'doughnut']], [], 'primary.format'],
    'additional without primary' => [['additional' => [['by' => 'brand']]], [], 'primary'],
    'complement without primary' => [[], ['counts' => ['enabled' => true]], 'primary'],
    'repeated primary' => [['primary' => ['by' => 'model'], 'additional' => [['by' => 'model', 'format' => 'table']]], [], 'additional'],
    'repeated additional' => [['primary' => ['by' => 'model'], 'additional' => [['by' => 'line'], ['by' => 'line']]], [], 'additional'],
    'non-list' => [['primary' => ['by' => 'brand'], 'additional' => ['line' => ['by' => 'line']]], [], 'additional'],
]);

test('trims custom texts, preserves the title across selections and filters and restores automatic mode', function () {
    $texts = ['title' => ['mode' => 'custom', 'text' => '  Ocorrências — Ford  '], 'subtitle' => '  Recorte de novembro  '];
    foreach ([
        ['period' => 'all', 'configuration' => [...metricsPdfSummarySelection(), ...$texts]],
        ['period' => 'this_year', 'configuration' => [...metricsPdfSummarySelection('closed'), ...$texts]],
    ] as $payload) {
        $this->postJson(metricsPdfConfigurationUrl(), $payload)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.title', ['mode' => 'custom', 'text' => 'Ocorrências — Ford'])
            ->assertJsonPath('data.subtitle', 'Recorte de novembro');
    }

    $this->postJson(metricsPdfConfigurationUrl(), ['configuration' => [
        ...metricsPdfSummarySelection(), 'title' => ['mode' => 'automatic', 'text' => null], 'subtitle' => '   ',
    ]])->assertOk()->assertJsonPath('data.title', ['mode' => 'automatic', 'text' => null])->assertJsonPath('data.subtitle', null);
});

test('rejects invalid title and subtitle values as fields without echoing the content', function (array $texts, string $field) {
    $this->postJson(metricsPdfConfigurationUrl(), ['configuration' => [...metricsPdfSummarySelection(), ...$texts]])
        ->assertUnprocessable()->assertJsonValidationErrors('configuration.'.$field)
        ->assertDontSee('HFS4456')->assertDontSee('alert(99)');
})->with([
    'missing title' => [['title' => ['mode' => 'custom']], 'title.text'],
    'blank title' => [['title' => ['mode' => 'custom', 'text' => '   ']], 'title.text'],
    'automatic with text' => [['title' => ['mode' => 'automatic', 'text' => 'Ocorrências']], 'title.text'],
    'unknown mode' => [['title' => ['mode' => 'manual']], 'title.mode'],
    'title too long' => [['title' => ['mode' => 'custom', 'text' => str_repeat('á', 121)]], 'title.text'],
    'subtitle too long' => [['subtitle' => str_repeat('a', 181)], 'subtitle'],
    'title markup' => [['title' => ['mode' => 'custom', 'text' => '<script>alert(99)</script>']], 'title.text'],
    'encoded markup' => [['subtitle' => '&lt;script&gt;alert(99)&lt;/script&gt;'], 'subtitle'],
    'line break' => [['subtitle' => "Uma linha\nOutra linha"], 'subtitle'],
    'invalid type' => [['subtitle' => ['texto']], 'subtitle'],
    'plate in title' => [['title' => ['mode' => 'custom', 'text' => 'Atendimentos HFS4456']], 'title.text'],
    'formatted plate in subtitle' => [['subtitle' => 'Veículo HFS - 4A56'], 'subtitle'],
]);

test('shares the complete filter validation and keeps zero-valued selections and filters out of the content response', function () {
    $fixture = new VehicleMetricsFixture;
    $filters = [
        'period' => 'custom', 'date_from' => '2026-11-20', 'date_to' => '2026-11-20', 'status' => 'open',
        'system_id' => $fixture->system->id, 'problem_category_id' => $fixture->category->id,
        'problem_subcategory_id' => $fixture->first->id, 'classification' => 'all', 'vehicle_presence' => 'with',
        'vehicle_plate' => ' hfs-44 ', 'vehicle_model' => '1.6 LX', 'vehicle_brand_id' => $fixture->alfa->id, 'vehicle_line' => 'light',
    ];
    $query = http_build_query($filters);
    $this->getJson('/api/metrics/summary?'.$query)->assertOk()
        ->assertJsonPath('data.total', 1)->assertJsonPath('data.closed', 0)->assertJsonPath('data.subcategory_occurrences', 1);
    $this->getJson('/api/metrics/vehicles?'.$query)->assertOk()
        ->assertJsonPath('data.summary.distinct_vehicles', 1)->assertJsonPath('data.recurrence.repeat_vehicles', 0);

    $configuration = [...metricsPdfSummarySelection('closed'), 'title' => ['mode' => 'automatic', 'text' => null], 'subtitle' => null];
    $this->postJson(metricsPdfConfigurationUrl(), [...$filters, 'configuration' => $configuration])->assertOk()
        ->assertExactJson(['data' => [
            ...$configuration,
            'sections' => ['summary' => ['enabled' => true, 'indicators' => ['closed' => ['enabled' => true, 'format' => 'automatic']]]],
        ]])->assertDontSee('HFS44')->assertDontSee('HFS4456');

    foreach ([
        ['status' => 'invalid'],
        ['date_to' => '2026-11-19'],
        ['vehicle_plate' => '*'],
        ['classification' => 'unclassified'],
        ['vehicle_presence' => 'without'],
        ['vehicle_line' => 'invalid'],
        ['vehicle_brand_id' => 999999],
        ['group_vehicle_id' => $fixture->car->id],
    ] as $invalid) {
        $params = [...$filters, ...$invalid];
        $summary = $this->getJson('/api/metrics/summary?'.http_build_query($params))->assertUnprocessable();
        $this->postJson(metricsPdfConfigurationUrl(), [...$params, 'configuration' => $configuration])
            ->assertUnprocessable()->assertExactJson($summary->json());
    }
});

test('keeps the legacy downloads usable and refuses PDF configuration at the download endpoint', function () {
    $response = $this->getJson('/api/metrics/export?format=pdf&period=all')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF-');
    $response = $this->getJson('/api/metrics/export?format=xlsx&period=all')->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->getContent())->toStartWith("PK\x03\x04");

    foreach (['configuration=', 'configuration[sections]=', http_build_query(['configuration' => metricsPdfSummarySelection()])] as $query) {
        $this->getJson('/api/metrics/export?format=pdf&'.$query)->assertUnprocessable()
            ->assertJsonValidationErrors('configuration');
    }
});
