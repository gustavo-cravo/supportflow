<?php

use App\Models\SupportTicket;
use App\Models\System;
use App\Services\Reports\AnalyticalTicketReport;
use App\Support\AnalyticalTicketReportConfiguration;
use App\Support\TicketMetricsFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\Support\VehicleMetricsFixture;

const ANALYTICAL_CONFIGURATION = '/api/metrics/analytical-report/configuration';
const ANALYTICAL_TICKETS = '/api/metrics/analytical-report/tickets';

function analyticalSelection(string $mode, array $ids = []): array
{
    return ['mode' => $mode, 'ids' => $ids];
}

function analyticalDocument(array $configuration = [], array $filters = []): array
{
    return app(AnalyticalTicketReport::class)->prepare(
        TicketMetricsFilters::fromValidated(['period' => 'all', ...$filters]),
        AnalyticalTicketReportConfiguration::fromValidated($configuration),
        CarbonImmutable::parse('2026-11-25 16:34:56', 'UTC'),
    );
}

test('publishes exactly the approved fields, defaults and grouping requirements', function () {
    $catalog = $this->getJson(ANALYTICAL_CONFIGURATION)->assertOk()->assertHeader('Content-Type', 'application/json')
        ->assertHeader('Cache-Control', 'no-store, private')->json('data');
    $fields = [
        'ticket' => ['title', 'system', 'contact', 'customer', 'previous_ticket', 'description', 'problem_category', 'problem_subcategories'],
        'vehicle' => ['vehicle_plate', 'vehicle_brand', 'vehicle_model', 'vehicle_line'],
        'resolution' => ['resolution', 'verification_source', 'verified_at', 'verified_by', 'verification_notes'],
    ];
    expect(array_map(fn (array $section) => array_keys($section['fields']), $catalog['sections']))->toBe($fields)
        ->and($catalog['defaults'])->toBe([
            'selection' => analyticalSelection('all'),
            'sections' => array_map(fn (array $fields) => ['enabled' => true, 'fields' => $fields], $fields),
            'grouping' => 'none', 'order' => 'newest', 'empty_fields' => 'smart',
            'title' => ['mode' => 'automatic', 'text' => null], 'subtitle' => null,
            'layout' => ['pagination' => 'automatic', 'columns' => 'automatic', 'spacing' => 'normal'],
        ])->and($catalog['groupings']['model']['required_fields'])->toBe(['vehicle_brand', 'vehicle_model'])
        ->and($catalog['sections']['vehicle']['fields']['vehicle_model']['width'])->toBe('full')
        ->and($catalog['sections']['ticket']['fields']['description']['width'])->toBe('full')
        ->and($catalog['sections']['resolution']['fields']['verification_notes']['width'])->toBe('full');
});

test('uses a minimal paginated list and selects the whole recorte independently of pages', function () {
    $fixture = new VehicleMetricsFixture;
    $params = ['period' => 'all', 'per_page' => 2, 'page' => 2];
    Model::preventLazyLoading();
    try {
        $list = $this->getJson(ANALYTICAL_TICKETS.'?'.http_build_query($params))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.current_page', 2)->assertJsonCount(2, 'data')->json('data');
        $dashboard = $this->getJson('/api/metrics/tickets?'.http_build_query($params))->assertOk()->json('data');
        expect(array_column($list, 'id'))->toBe(array_column($dashboard, 'id'));
        expect(array_keys($list[0]))->toBe(['id', 'title', 'created_at', 'system', 'category', 'subcategories', 'vehicle']);
        expect($list[0]['vehicle'])->toBeNull()
            ->and(array_keys($list[1]['vehicle']))->toBe(['brand', 'model']);
        expect(json_encode($list, JSON_THROW_ON_ERROR))->not->toContain('DESCRICAO_PRIVADA_987', 'CONTATO_PRIVADO_987', 'CLIENTE_PRIVADO_987', 'HFS4456', 'SOLUCAO_PRIVADA_987');
    } finally {
        Model::preventLazyLoading(false);
    }

    $this->getJson(ANALYTICAL_TICKETS.'?'.http_build_query(['period' => 'all', 'vehicle_plate' => $fixture->car->plate, 'status' => 'open']))
        ->assertOk()->assertJsonPath('data.0.subcategories', ['Modelo divergente', 'Ano divergente'])->assertJsonCount(1, 'data');
    $this->postJson(ANALYTICAL_CONFIGURATION, ['period' => 'all'])->assertOk()
        ->assertJsonPath('data.eligible_count', 7)->assertJsonPath('data.selected_count', 7)
        ->assertJsonPath('data.configuration.selection', analyticalSelection('all'));
    expect(analyticalDocument()['document']['ticket_count'])->toBe(7);
});

test('matches the Dashboard IDs for standard and combined filters', function (string $case, int $total) {
    $fixture = new VehicleMetricsFixture;
    $inactive = System::where('name', 'Sistema B')->firstOrFail();
    $inactive->update(['is_active' => false]);
    $date = ['period' => 'custom', 'date_from' => '2026-11-20', 'date_to' => '2026-11-20'];
    $filters = ['period' => 'all', ...match ($case) {
        'date' => $date,
        'inactive' => ['system_id' => $inactive->id],
        'category' => ['problem_category_id' => $fixture->category->id],
        'subcategory' => ['problem_subcategory_id' => $fixture->first->id],
        'status' => ['status' => 'closed'],
        'presence' => ['vehicle_presence' => 'without'],
        'plate' => ['vehicle_plate' => 'hfs-44'],
        'brand' => ['vehicle_brand_id' => $fixture->alfa->id],
        'model' => ['vehicle_model' => '2008'],
        'line' => ['vehicle_line' => 'heavy'],
        'unclassified' => ['classification' => 'unclassified'],
        'without_subcategory' => ['classification' => 'without_subcategory'],
        'combined' => [...$date, 'system_id' => $fixture->system->id, 'problem_category_id' => $fixture->category->id,
            'problem_subcategory_id' => $fixture->first->id, 'status' => 'open', 'vehicle_presence' => 'with',
            'vehicle_plate' => 'HFS4456', 'vehicle_brand_id' => $fixture->alfa->id, 'vehicle_model' => '2007', 'vehicle_line' => 'light'],
        'empty' => ['vehicle_brand_id' => $fixture->beta->id, 'vehicle_line' => 'light'],
    }];
    $query = '?'.http_build_query($filters);
    $list = $this->getJson(ANALYTICAL_TICKETS.$query)->assertOk()->assertJsonPath('meta.total', $total)->json('data');
    $dashboard = $this->getJson('/api/metrics/tickets'.$query)->assertOk()->json('data');
    expect(array_column($list, 'id'))->toBe(array_column($dashboard, 'id'));
    $check = $this->postJson(ANALYTICAL_CONFIGURATION, $filters);
    $pdf = $this->postJson('/api/metrics/analytical-report/pdf', [...$filters, 'configuration' => [
        'sections' => ['ticket' => ['enabled' => true, 'fields' => ['title']]],
    ]]);
    if ($total === 0) {
        $check->assertUnprocessable()->assertJsonValidationErrors('configuration.selection');
        $pdf->assertUnprocessable()->assertJsonValidationErrors('configuration.selection');
    } else {
        $check->assertOk()->assertJsonPath('data.selected_count', $total);
        expect(analyticalDocument(filters: $filters)['document']['ticket_count'])->toBe($total);
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Report-Selected-Count', (string) $total);
        expect($pdf->getContent())->toStartWith('%PDF-');
    }
})->with([
    ['date', 6], ['inactive', 1], ['category', 4], ['subcategory', 2], ['status', 2], ['presence', 1], ['plate', 2],
    ['brand', 5], ['model', 1], ['line', 2], ['unclassified', 3], ['without_subcategory', 2], ['combined', 1], ['empty', 0],
]);

test('accepts include or exclude selection, deduplicates IDs and preserves original records', function () {
    $fixture = new VehicleMetricsFixture;
    $first = $fixture->firstTicket->id;
    $second = $fixture->secondTicket->id;
    $fixture->firstTicket->update(['title' => 'Registro um']);
    $fixture->secondTicket->update(['title' => 'Registro dois']);
    $before = SupportTicket::orderBy('id')->get()->toArray();
    foreach ([['all_except', [$first], 6], ['all_except', [$first, $first, $second], 5], ['only', [$first, $first], 1]] as [$mode, $ids, $expected]) {
        $configuration = ['selection' => analyticalSelection($mode, $ids), 'sections' => ['ticket' => ['enabled' => true, 'fields' => ['title']]]];
        $this->postJson(ANALYTICAL_CONFIGURATION, ['period' => 'all', 'configuration' => $configuration])
            ->assertOk()->assertJsonPath('data.eligible_count', 7)->assertJsonPath('data.selected_count', $expected)
            ->assertJsonPath('data.configuration.selection.ids', array_values(array_unique($ids)));
        $document = analyticalDocument($configuration)['document'];
        expect($document['ticket_count'])->toBe($expected)
            ->and($document['groups'][0]['tickets'])->toHaveCount($expected)
            ->and(str_contains(json_encode($document, JSON_THROW_ON_ERROR), 'Registro um'))->toBe($mode === 'only');
    }
    expect(SupportTicket::orderBy('id')->get()->toArray())->toBe($before);
});

test('rejects empty, unavailable and out-of-scope selections without disclosing the reason per ID', function () {
    $fixture = new VehicleMetricsFixture;
    $filters = ['period' => 'all', 'status' => 'closed'];
    $configuration = ['selection' => analyticalSelection('only', [])];
    $this->postJson(ANALYTICAL_CONFIGURATION, [...$filters, 'configuration' => $configuration])
        ->assertUnprocessable()->assertJsonValidationErrors('configuration.selection.ids');

    foreach (['only', 'all_except'] as $mode) {
        $outside = $this->postJson(ANALYTICAL_CONFIGURATION, [...$filters, 'configuration' => ['selection' => analyticalSelection($mode, [$fixture->firstTicket->id])]])
            ->assertUnprocessable()->assertJsonValidationErrors('configuration.selection.ids')->json('errors');
        $missing = $this->postJson(ANALYTICAL_CONFIGURATION, [...$filters, 'configuration' => ['selection' => analyticalSelection($mode, [999999])]])
            ->assertUnprocessable()->json('errors');
        expect($outside)->toBe($missing);
    }
    $this->postJson(ANALYTICAL_CONFIGURATION, [
        'period' => 'all', 'vehicle_plate' => $fixture->car->plate, 'status' => 'closed',
        'configuration' => ['selection' => analyticalSelection('all_except', [$fixture->secondTicket->id])],
    ])->assertUnprocessable()->assertJsonValidationErrors('configuration.selection');
});

test('rejects unknown and incompatible report options', function (array $configuration, string $error) {
    $this->postJson(ANALYTICAL_CONFIGURATION, ['period' => 'all', 'configuration' => $configuration])
        ->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    [['unknown' => true], 'configuration'],
    [['sections' => []], 'configuration.sections'],
    [['sections' => ['ticket' => ['enabled' => true, 'fields' => []]]], 'configuration.sections'],
    [['sections' => ['vehicle' => ['enabled' => false]]], 'configuration.sections'],
    [['sections' => ['other' => ['enabled' => true]]], 'configuration.sections'],
    [['sections' => ['ticket' => ['enabled' => 'true']]], 'configuration.sections.ticket.enabled'],
    [['sections' => ['ticket' => ['enabled' => true, 'fields' => ['status']]]], 'configuration.sections.ticket.fields.0'],
    [['sections' => ['ticket' => ['enabled' => true, 'fields' => ['vehicle_model']]]], 'configuration.sections.ticket.fields.0'],
    [['sections' => ['vehicle' => ['enabled' => true, 'fields' => ['vehicle_model']]], 'grouping' => 'model'], 'configuration.grouping'],
    [['selection' => ['mode' => 'all', 'ids' => [1]]], 'configuration.selection.ids'],
    [['selection' => ['mode' => 'only', 'ids' => ['1']]], 'configuration.selection.ids.0'],
    [['selection' => ['mode' => 'only', 'ids' => [0]]], 'configuration.selection.ids.0'],
    [['selection' => ['mode' => 'only', 'ids' => [true]]], 'configuration.selection.ids.0'],
    [['order' => 'invalid'], 'configuration.order'],
    [['grouping' => 'invalid'], 'configuration.grouping'],
    [['empty_fields' => 'invalid'], 'configuration.empty_fields'],
    [['layout' => ['columns' => 'three']], 'configuration.layout.columns'],
    [['layout' => ['pagination' => 'invalid']], 'configuration.layout.pagination'],
    [['layout' => ['spacing' => 'invalid']], 'configuration.layout.spacing'],
    [['title' => ['mode' => 'custom']], 'configuration.title.text'],
    [['title' => ['mode' => 'automatic', 'text' => 'Manual']], 'configuration.title.text'],
    [['title' => ['mode' => 'custom', 'text' => '&lt;script&gt;']], 'configuration.title.text'],
    [['title' => ['mode' => 'custom', 'text' => str_repeat('Á', 121)]], 'configuration.title.text'],
    [['subtitle' => str_repeat('Ó', 181)], 'configuration.subtitle'],
    [['subtitle' => 'Placa HFS‑4456'], 'configuration.subtitle'],
]);

test('rejects manipulated filters and pagination parameters', function () {
    $fixture = new VehicleMetricsFixture;
    $this->postJson(ANALYTICAL_CONFIGURATION, ['period' => 'all', 'filters' => ['vehicle_plate' => $fixture->car->plate]])
        ->assertUnprocessable()->assertJsonValidationErrors('filters');
    $this->postJson(ANALYTICAL_CONFIGURATION, ['period' => 'all', 'per_page' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('per_page');
    $this->getJson(ANALYTICAL_TICKETS.'?period=all&vehicle_presence=without&vehicle_line=heavy')
        ->assertUnprocessable()->assertJsonValidationErrors('vehicle_line');
    $this->getJson(ANALYTICAL_TICKETS.'?period=all&group_vehicle_id='.$fixture->car->id)
        ->assertUnprocessable()->assertJsonValidationErrors('group_vehicle_id');
    $this->getJson(ANALYTICAL_TICKETS.'?page=0&per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['page', 'per_page']);
});

test('computes titles from catalog filters and accepts manual headings and presentation options', function () {
    $fixture = new VehicleMetricsFixture;
    $fixture->category->update(['name' => 'Erro de indicação']);
    $fixture->alfa->update(['name' => 'Ford']);
    $fixture->system->update(['name' => 'ArgoLub']);
    $filters = ['period' => 'all', 'system_id' => $fixture->system->id];

    $this->postJson(ANALYTICAL_CONFIGURATION, $filters)->assertOk()
        ->assertJsonPath('data.title', 'Relatório analítico de atendimentos — ArgoLub');
    $filters['problem_category_id'] = $fixture->category->id;
    $filters['vehicle_brand_id'] = $fixture->alfa->id;
    $filters['vehicle_plate'] = 'HFS4456';
    $this->postJson(ANALYTICAL_CONFIGURATION, $filters)->assertOk()
        ->assertJsonPath('data.title', 'Relatório analítico de erros de indicação — Ford');

    $custom = ['title' => ['mode' => 'custom', 'text' => '  Revisão técnica  '], 'subtitle' => '  Primeira conferência  ',
        'layout' => ['pagination' => 'individual', 'columns' => 'two', 'spacing' => 'expanded']];
    $this->postJson(ANALYTICAL_CONFIGURATION, [...$filters, 'configuration' => $custom])->assertOk()
        ->assertJsonPath('data.title', 'Revisão técnica')->assertJsonPath('data.configuration.subtitle', 'Primeira conferência')
        ->assertJsonPath('data.configuration.layout', $custom['layout']);
});

test('groups each selected ticket once by the existing system, category and vehicle identities', function (string $by, array $expected) {
    new VehicleMetricsFixture;
    $document = analyticalDocument(['grouping' => $by])['document'];
    $counts = [];
    foreach ($document['groups'] as $group) {
        expect(array_keys($group))->toBe(['label', 'tickets']);
        $counts[$group['label'] ?? 'ungrouped'] = count($group['tickets']);
    }
    expect($counts)->toEqual($expected)->and(array_sum($counts))->toBe($document['ticket_count']);
})->with([
    ['none', ['ungrouped' => 7]],
    ['system', ['Sistema A' => 6, 'Sistema B' => 1]],
    ['category', ['Identificação' => 4, 'Sem classificação' => 3]],
    ['brand', ['Marca Alfa' => 5, 'Marca Beta' => 1, 'Sem identificação veicular' => 1]],
    ['model', ['Marca Alfa / Série 1.6 LX 2007' => 3, 'Marca Beta / Série 1.6 LX 2007' => 1,
        'Marca Alfa / Modelo não informado' => 1, 'Marca Alfa / Série 1.6 LX 2008' => 1, 'Sem identificação veicular' => 1]],
]);

test('applies each order and tie-breaker deterministically within each group', function (string $order, array $expected) {
    $fixture = new VehicleMetricsFixture;
    $fixture->firstTicket->forceFill(['title' => 'Bravo', 'created_at' => '2026-11-20 09:00:00'])->save();
    $fixture->secondTicket->forceFill(['title' => 'bravo', 'created_at' => '2026-11-20 09:00:00'])->save();
    $third = $fixture->secondTicket->replicate()->forceFill(['title' => 'Alfa', 'created_at' => '2026-11-21 09:00:00']);
    $third->save();
    $selection = analyticalSelection('only', [$third->id, $fixture->secondTicket->id, $fixture->firstTicket->id]);
    $document = analyticalDocument([
        'sections' => ['ticket' => ['enabled' => true, 'fields' => ['title', 'system']]],
        'selection' => $selection, 'grouping' => 'system', 'order' => $order,
    ])['document'];
    expect($document['groups'])->toHaveCount(1);
    $group = $document['groups'][0];
    expect($group['label'])->toBe($fixture->system->name)
        ->and(array_map(fn (array $ticket) => $ticket['sections']['ticket']['fields']['title']['value'], $group['tickets']))->toBe($expected)
        ->and(array_keys($group['tickets'][0]['sections']['ticket']['fields']))->toBe(['title', 'system'])
        ->and(json_encode($group, JSON_THROW_ON_ERROR))->not->toContain('created_at', 'DESCRICAO_PRIVADA_987', 'CONTATO_PRIVADO_987');
})->with([
    ['newest', ['Alfa', 'bravo', 'Bravo']], ['oldest', ['Bravo', 'bravo', 'Alfa']], ['title', ['Alfa', 'Bravo', 'bravo']],
]);

test('only groups selected records and retains no hidden group identifiers in the document', function () {
    $fixture = new VehicleMetricsFixture;
    $payload = analyticalDocument([
        'grouping' => 'model', 'selection' => analyticalSelection('only', [$fixture->firstTicket->id]),
        'sections' => ['vehicle' => ['enabled' => true, 'fields' => ['vehicle_brand', 'vehicle_model']]],
    ], ['vehicle_plate' => 'HFS4456']);
    $document = $payload['document'];
    expect($payload['controls']['configuration']['selection']['ids'])->toBe([$fixture->firstTicket->id])
        ->and($payload['controls']['filters']['vehicle_plate'])->toBe('HFS4456')
        ->and($document['title'])->toBe('Relatório analítico de atendimentos')
        ->and($document['ticket_count'])->toBe(1)
        ->and($document['groups'][0]['label'])->toBe('Marca Alfa / Série 1.6 LX 2007');
    expect(json_encode($document, JSON_THROW_ON_ERROR))->not->toContain('HFS4456', 'vehicle_id', 'analytical_model_key', '"id":', 'Identificação');
});

test('prepares the 17 selected fields with original values, multiline text and a standalone prior reference', function () {
    $fixture = new VehicleMetricsFixture;
    $previous = $fixture->secondTicket;
    $previous->update(['title' => 'Retorno anterior', 'description' => 'Só na referência']);
    $description = "Primeira linha — ação nº 7.\r\n\t<exemplo> & café\n".str_repeat('Descrição original com acentuação e detalhes. ', 95)."\nÚltima linha.";
    $solution = "Ajuste do parâmetro <ABC>.\n\nResultado conferido e salvo.";
    $verification = "1. Revisão do catálogo\n2. Confirmação de resolução por telefone";
    $model = "Série 2007 — versão completa\n".str_repeat('Detalhe de configuração sem abreviação. ', 85);
    $fixture->car->update(['model_description' => $model]);
    $fixture->firstTicket->update([
        'previous_ticket_id' => $previous->id, 'description' => $description, 'resolution' => $solution,
        'verification_source' => 'responsible_department', 'verified_at' => '2026-11-24 10:15:00',
        'verified_by' => '0', 'verification_notes' => $verification,
    ]);
    $report = analyticalDocument(['selection' => analyticalSelection('only', [$fixture->firstTicket->id])]);
    $document = $report['document'];
    $sections = $document['groups'][0]['tickets'][0]['sections'];
    expect($document['generated_at'])->toBe('2026-11-25T13:34:56-03:00')
        ->and($document['ticket_count'])->toBe(1)->and($sections)->toHaveCount(3)
        ->and(array_column($sections['ticket']['fields'], 'value'))->toBe([
            'TITULO_PRIVADO_987', 'Sistema A', 'CONTATO_PRIVADO_987', 'CLIENTE_PRIVADO_987',
            '#'.str_pad((string) $previous->id, 4, '0', STR_PAD_LEFT).' · Retorno anterior',
            $description, 'Identificação', ['Modelo divergente', 'Ano divergente'],
        ])->and(array_column($sections['vehicle']['fields'], 'value'))->toBe(['HFS4456', 'Marca Alfa', $model, 'light'])
        ->and(array_column($sections['resolution']['fields'], 'value'))->toBe([
            $solution, 'responsible_department', '2026-11-24 10:15:00', '0', $verification,
        ])->and($sections['ticket']['fields']['description']['width'])->toBe('full')
        ->and($sections['vehicle']['fields']['vehicle_model']['width'])->toBe('full')
        ->and($sections['resolution']['fields']['verification_notes']['absence'])->toBeNull();
    expect(json_encode($document, JSON_THROW_ON_ERROR))->not->toContain('Só na referência', '"id":', 'closed_at', '"status"', 'previous_ticket_id');
});

test('keeps absence separate from values and honors every empty-field mode', function () {
    new VehicleMetricsFixture;
    $ticket = SupportTicket::whereNull('vehicle_id')->firstOrFail();
    $ticket->update(['customer_id' => null, 'resolution' => "  \t ", 'verification_notes' => null]);
    $configuration = ['selection' => analyticalSelection('only', [$ticket->id])];
    $load = fn (string $mode) => analyticalDocument([...$configuration, 'empty_fields' => $mode])['document']['groups'][0]['tickets'][0]['sections'];
    $smart = $load('smart');
    expect(array_keys($smart['ticket']['fields']))->toBe(['title', 'system', 'contact', 'description', 'problem_category'])
        ->and($smart['ticket']['fields']['problem_category']['value'])->toBeNull()
        ->and($smart['ticket']['fields']['problem_category']['absence'])->toBe('Sem classificação')
        ->and($smart['vehicle']['absence'])->toBe('Sem veículo vinculado')
        ->and($smart['vehicle']['fields'])->toBe([])
        ->and(array_column($smart['resolution']['fields'], 'absence', 'label'))->toBe([
            'Solução aplicada' => 'Nenhuma solução registrada', 'Como a resolução foi verificada' => 'Nenhuma verificação registrada',
        ]);
    $show = $load('show');
    expect($show['ticket']['fields']['customer']['absence'])->toBe('Cliente não informado')
        ->and($show['ticket']['fields']['previous_ticket']['value'])->toBeNull()
        ->and($show['ticket']['fields']['problem_subcategories']['absence'])->toBe('Sem subcategorias registradas')
        ->and($show['vehicle']['fields'])->toHaveCount(4)
        ->and($show['resolution']['fields'])->toHaveCount(5)
        ->and($show['resolution']['fields']['verification_source']['value'])->toBeNull()
        ->and($show['resolution']['fields']['verified_at']['absence'])->toBe('Data e horário da confirmação não informados');
    $hide = $load('hide');
    expect(array_keys($hide))->toBe(['ticket'])
        ->and(array_keys($hide['ticket']['fields']))->toBe(['title', 'system', 'contact', 'description']);

    $partial = analyticalDocument([...$configuration, 'empty_fields' => 'show', 'sections' => [
        'ticket' => ['enabled' => true, 'fields' => ['title', 'title']],
        'vehicle' => ['enabled' => false], 'resolution' => ['enabled' => false],
    ]])['document']['groups'][0]['tickets'][0]['sections'];
    expect(array_keys($partial))->toBe(['ticket'])->and(array_keys($partial['ticket']['fields']))->toBe(['title']);
});

test('distinguishes an existing vehicle without a model from an absent vehicle', function () {
    $fixture = new VehicleMetricsFixture;
    $ticket = SupportTicket::where('vehicle_id', $fixture->missing->id)->firstOrFail();
    $document = analyticalDocument([
        'sections' => ['vehicle' => ['enabled' => true, 'fields' => ['vehicle_model']]],
        'selection' => analyticalSelection('only', [$ticket->id]),
    ])['document'];
    $section = $document['groups'][0]['tickets'][0]['sections']['vehicle'];
    expect($section['absence'])->toBeNull()
        ->and($section['fields']['vehicle_model']['value'])->toBeNull()
        ->and($section['fields']['vehicle_model']['absence'])->toBe('Modelo não informado')
        ->and($section['fields'])->toHaveCount(1);
});

test('prepares records across multiple internal batches with bounded relation queries', function () {
    $fixture = new VehicleMetricsFixture;
    $row = $fixture->firstTicket->replicate()->getAttributes();
    $rows = array_map(fn (int $i): array => [...$row, 'title' => 'Lote teste '.$i, 'created_at' => '2026-11-20 09:00:00'], range(1, 201));
    SupportTicket::insert($rows);
    DB::enableQueryLog();
    Model::preventLazyLoading();
    try {
        $configuration = ['sections' => ['ticket' => ['enabled' => true, 'fields' => ['title', 'system', 'problem_subcategories']]]];
        $payload = analyticalDocument($configuration);
        $queries = array_filter(DB::getQueryLog(), fn (array $entry): bool => str_starts_with(strtolower($entry['query']), 'select'));
        $tickets = $payload['document']['groups'][0]['tickets'];
        expect($tickets)->toHaveCount(208)->and($queries)->toHaveCount(7);
        $contents = json_encode($tickets, JSON_THROW_ON_ERROR);
        for ($i = 1; $i <= 201; $i++) {
            expect(substr_count($contents, '"Lote teste '.$i.'"'))->toBe(1);
        }
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
        Model::preventLazyLoading(false);
    }
});
