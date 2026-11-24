<?php

use App\Services\Reports\TicketMetricsReport;
use App\Support\TicketMetricsFilters;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\VehicleMetricsFixture;

function vehicleMetricsUrl(string $endpoint, array $filters = []): string
{
    return '/api/metrics/'.$endpoint.'?'.http_build_query(['period' => 'all', ...$filters]);
}

function vehicleWorkbook(string $bytes): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'sf-vehicle-metrics-');
    try {
        file_put_contents($path, $bytes);

        return IOFactory::load($path);
    } finally {
        unlink($path);
    }
}

function vehiclePdfText(string $pdf): string
{
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);

    return implode("\n", array_map(fn (string $stream) => @gzuncompress($stream) ?: $stream, $matches[1]));
}

beforeEach(fn () => app()->instance(VehicleMetricsFixture::class, new VehicleMetricsFixture));

test('separates tickets, distinct vehicles, normalized models and subcategory links', function () {
    $fixture = app(VehicleMetricsFixture::class);
    $data = $this->getJson(vehicleMetricsUrl('vehicles'))->assertOk()->json('data');
    expect($data['summary'])->toBe([
        'with_vehicle' => 6, 'without_vehicle' => 1, 'identification_percentage' => 85.71,
        'distinct_vehicles' => 5, 'distinct_models' => 3, 'vehicles_without_model' => 1,
        'light_tickets' => 4, 'heavy_tickets' => 2,
    ])->and($data['recurrence'])->toBe(['repeat_vehicles' => 1, 'repeat_tickets' => 2, 'max_tickets_per_vehicle' => 2])
        ->and($data['lines'])->toBe([
            ['vehicle_line' => 'light', 'total' => 4, 'vehicles' => 3, 'percentage' => 66.67],
            ['vehicle_line' => 'heavy', 'total' => 2, 'vehicles' => 2, 'percentage' => 33.33],
        ])->and($data['brands'][0])->toMatchArray(['brand_id' => $fixture->alfa->id, 'total' => 5, 'vehicles' => 4, 'percentage' => 83.33])
        ->and($data['models'][0])->toMatchArray(['brand_id' => $fixture->alfa->id, 'total' => 3, 'vehicles' => 2, 'vehicle_line' => 'mixed',
            'model_description' => $fixture->car->model_description])
        ->and(array_column($data['models'], 'model_description'))->toContain(null, 'Série 1.6 LX 2008')
        ->and($data['vehicles'][0])->toMatchArray(['vehicle_id' => $fixture->car->id, 'plate' => 'HFS4456', 'total' => 2]);

    foreach ($data['problems'] as $rows) {
        expect(array_sum(array_column(array_filter($rows, fn ($row) => $row['level'] === 'subcategory'), 'total')))->toBe(3)
            ->and(array_sum(array_column(array_filter($rows, fn ($row) => $row['level'] === 'category'), 'total')))->toBe(6);
    }
});

test('applies the same filters to vehicle and previous metric endpoints with distinct paginated details', function () {
    $fixture = app(VehicleMetricsFixture::class);
    $params = ['period' => 'custom', 'date_from' => '2026-11-20', 'date_to' => '2026-11-20', 'status' => 'closed',
        'system_id' => $fixture->system->id, 'problem_category_id' => $fixture->category->id, 'problem_subcategory_id' => $fixture->first->id,
        'vehicle_plate' => ' hfs-44 ', 'vehicle_model' => '1.6 LX', 'vehicle_brand_id' => $fixture->alfa->id, 'vehicle_line' => 'light'];
    $this->getJson(vehicleMetricsUrl('vehicles', $params))->assertOk()
        ->assertJsonPath('meta.filters.vehicle_plate', 'HFS44')->assertJsonPath('data.summary.with_vehicle', 1)
        ->assertJsonPath('data.summary.distinct_models', 1)->assertJsonPath('data.summary.without_vehicle', 0)
        ->assertJsonCount(2, 'data.problems.brand');
    $this->getJson(vehicleMetricsUrl('summary', $params))->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.subcategory_occurrences', 1);
    $this->getJson(vehicleMetricsUrl('tickets', $params))->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $fixture->secondTicket->id)->assertJsonPath('data.0.vehicle.plate', 'HFS4456');
    $this->getJson(vehicleMetricsUrl('vehicles', ['vehicle_presence' => 'without']))->assertOk()
        ->assertJsonPath('data.summary.without_vehicle', 1)->assertJsonPath('data.summary.identification_percentage', 0)
        ->assertJsonPath('data.lines.0.percentage', 0)->assertJsonCount(0, 'data.brands');
    $this->getJson(vehicleMetricsUrl('vehicles', ['vehicle_presence' => 'with']))->assertOk()->assertJsonPath('data.summary.without_vehicle', 0);
    $this->getJson(vehicleMetricsUrl('vehicles', ['vehicle_line' => 'heavy']))->assertOk()->assertJsonPath('data.summary.with_vehicle', 2);
});

test('details model, brand, line, vehicle and classification groups with the same counts', function () {
    $fixture = app(VehicleMetricsFixture::class);
    $data = $this->getJson(vehicleMetricsUrl('vehicles'))->assertOk()->json('data');
    foreach ([
        [['vehicle_brand_id' => $fixture->alfa->id], 5],
        [['vehicle_line' => 'heavy'], 2],
        [['group_vehicle_id' => $fixture->car->id], 2],
        [['vehicle_brand_id' => $fixture->alfa->id, 'group_vehicle_model' => $data['models'][0]['model_key']], 3],
        [['vehicle_brand_id' => $fixture->alfa->id, 'group_vehicle_model' => 'missing'], 1],
    ] as [$filters, $count]) {
        $this->getJson(vehicleMetricsUrl('tickets', [...$filters, 'per_page' => 1]))->assertOk()
            ->assertJsonPath('meta.total', $count)->assertJsonCount(1, 'data');
    }
    $modelGroup = ['vehicle_brand_id' => $fixture->alfa->id, 'group_vehicle_model' => $data['models'][0]['model_key'],
        'system_id' => $fixture->system->id, 'problem_category_id' => $fixture->category->id];
    $this->getJson(vehicleMetricsUrl('tickets', $modelGroup))->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson(vehicleMetricsUrl('tickets', [...$modelGroup, 'problem_subcategory_id' => $fixture->second->id]))
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $fixture->firstTicket->id);
});

test('rejects contradictory and invalid vehicle filters and keeps detail groups off exports', function () {
    $fixture = app(VehicleMetricsFixture::class);
    $this->getJson(vehicleMetricsUrl('vehicles', ['vehicle_presence' => 'without', 'vehicle_plate' => 'HFS4456', 'vehicle_line' => 'heavy']))
        ->assertUnprocessable()->assertJsonValidationErrors(['vehicle_plate', 'vehicle_line']);
    $this->getJson(vehicleMetricsUrl('summary', ['vehicle_plate' => 'HFS%42', 'vehicle_line' => 'medium', 'vehicle_brand_id' => 999999]))
        ->assertUnprocessable()->assertJsonValidationErrors(['vehicle_plate', 'vehicle_line', 'vehicle_brand_id']);
    $this->getJson(vehicleMetricsUrl('tickets', ['group_vehicle_model' => 'missing']))->assertUnprocessable()->assertJsonValidationErrors('vehicle_brand_id');
    $this->getJson(vehicleMetricsUrl('export', ['format' => 'xlsx', 'group_vehicle_id' => $fixture->car->id, 'include_vehicle_statistics' => 'yes']))
        ->assertUnprocessable()->assertJsonValidationErrors(['group_vehicle_id', 'include_vehicle_statistics']);
});

test('recalculates prior periods when model, brand and line change in the central registry', function () {
    $fixture = app(VehicleMetricsFixture::class);
    $params = ['period' => 'custom', 'date_from' => '2026-11-20', 'date_to' => '2026-11-20', 'vehicle_plate' => $fixture->missing->plate];
    $this->getJson(vehicleMetricsUrl('vehicles', $params))->assertOk()
        ->assertJsonPath('data.summary.vehicles_without_model', 1)->assertJsonPath('data.summary.light_tickets', 1);
    $this->patchJson('/api/vehicles/'.$fixture->missing->id, [
        'brand_id' => $fixture->beta->id, 'vehicle_line' => 'heavy', 'model_description' => 'Modelo atualizado',
    ])->assertOk();
    $this->getJson(vehicleMetricsUrl('vehicles', $params))->assertOk()
        ->assertJsonPath('data.summary.with_vehicle', 1)->assertJsonPath('data.summary.vehicles_without_model', 0)
        ->assertJsonPath('data.summary.heavy_tickets', 1)->assertJsonPath('data.brands.0.brand_id', $fixture->beta->id)
        ->assertJsonPath('data.models.0.model_description', 'Modelo atualizado');
});

test('exports matched aggregate metrics when selected and keeps both formats private and optional', function () {
    $fixture = app(VehicleMetricsFixture::class);
    $fixture->car->update(['model_description' => 'Série LX (consulta hfs - 4456)']);
    $filters = ['vehicle_plate' => 'HFS-4456'];
    $data = $this->getJson(vehicleMetricsUrl('vehicles', $filters))->assertOk()->json('data');
    $report = app(TicketMetricsReport::class)->build(TicketMetricsFilters::fromValidated(['period' => 'all', 'vehicle_plate' => 'HFS4456']), true);
    expect($report->vehicleTables[0]['rows'][0][1])->toBe($data['summary']['with_vehicle'])
        ->and($report->summary['total'])->toBe($data['summary']['with_vehicle'] + $data['summary']['without_vehicle']);
    $book = vehicleWorkbook($this->getJson(vehicleMetricsUrl('export', [...$filters, 'format' => 'xlsx']))->assertOk()->getContent());
    expect($book->getSheetNames())->toBe(['Resumo Geral', 'Por Sistema', 'Categorias', 'Subcategorias', 'Evolução Mensal']);
    expect($book->getSheetByName('Resumo Geral')->getCell('D14')->getValue())->toBe(2);
    $book->disconnectWorksheets();
    $book = vehicleWorkbook($this->getJson(vehicleMetricsUrl('export', [...$filters, 'format' => 'xlsx', 'include_vehicle_statistics' => 1]))->assertOk()->getContent());
    expect(array_slice($book->getSheetNames(), 5))->toBe(['Resumo veicular', 'Linhas veiculares', 'Marcas', 'Modelos', 'Problemas por marca', 'Problemas por modelo', 'Problemas por linha']);
    expect($book->getSheetByName('Resumo veicular')->getCell('B5')->getValue())->toBe($data['summary']['with_vehicle']);
    expect($book->getSheetByName('Resumo veicular')->getCell('B7')->getDataType())->toBe(DataType::TYPE_NUMERIC);
    expect($book->getSheetByName('Resumo veicular')->getCell('B7')->getFormattedValue())->toBe('100.00%');
    expect($book->getSheetByName('Linhas veiculares')->getCell('C5')->getValue())->toBe($data['lines'][0]['vehicles']);
    expect($book->getSheetByName('Modelos')->getCell('B5')->getValue())->toBe('Série LX (consulta [placa omitida])');
    expect($book->getSheetByName('Problemas por modelo')->getCell('G5')->getDataType())->toBe(DataType::TYPE_NUMERIC);
    $private = ['HFS4456', 'HFS-4456', 'hfs - 4456', 'TITULO_PRIVADO_987', 'DESCRICAO_PRIVADA_987', 'SOLUCAO_PRIVADA_987', 'CONTATO_PRIVADO_987', 'CLIENTE_PRIVADO_987'];
    foreach ($book->getAllSheets() as $sheet) {
        expect(json_encode($sheet->toArray(), JSON_THROW_ON_ERROR))->not->toContain(...$private);
    }
    $book->disconnectWorksheets();

    $legacyPdf = vehiclePdfText($this->getJson(vehicleMetricsUrl('export', [...$filters, 'format' => 'pdf']))->assertOk()->getContent());
    $pdf = $this->getJson(vehicleMetricsUrl('export', [...$filters, 'format' => 'pdf', 'include_vehicle_statistics' => 1]))->assertOk()->getContent();
    $pdfText = vehiclePdfText($pdf);
    expect($pdf)->toStartWith('%PDF-');
    expect($legacyPdf)->not->toContain(mb_convert_encoding('Estatísticas veiculares', 'UTF-16BE', 'UTF-8'));
    expect($pdfText)->toContain(mb_convert_encoding('Estatísticas veiculares', 'UTF-16BE', 'UTF-8'), mb_convert_encoding('100,00%', 'UTF-16BE', 'UTF-8'));
    foreach ($private as $value) {
        expect($pdfText)->not->toContain($value, mb_convert_encoding($value, 'UTF-16BE', 'UTF-8'));
    }
});

test('renders empty sets and preserves long model descriptions in both formats', function () {
    $fixture = app(VehicleMetricsFixture::class);
    $long = str_repeat('Modelo estendido com versão e ano 2007. ', 151).'FINAL_MODELO_987';
    $fixture->car->update(['model_description' => $long]);
    $scope = ['vehicle_plate' => $fixture->car->plate, 'include_vehicle_statistics' => 1];
    $pdf = $this->getJson(vehicleMetricsUrl('export', [...$scope, 'format' => 'pdf']))->assertOk()->getContent();
    preg_match_all('/\/Type\s*\/Page\b/', $pdf, $pages);
    expect(count($pages[0]))->toBeGreaterThan(2);
    expect(vehiclePdfText($pdf))->toContain(mb_convert_encoding('FINAL_MODELO_987', 'UTF-16BE', 'UTF-8'));
    $book = vehicleWorkbook($this->getJson(vehicleMetricsUrl('export', [...$scope, 'format' => 'xlsx']))->assertOk()->getContent());
    expect($book->getSheetByName('Modelos')->getCell('B5')->getValue())->toBe($long);
    $book->disconnectWorksheets();

    $empty = ['vehicle_plate' => 'ZZZ9999', 'include_vehicle_statistics' => 1];
    $this->getJson(vehicleMetricsUrl('vehicles', ['vehicle_plate' => 'ZZZ9999']))->assertOk()
        ->assertJsonPath('data.summary.with_vehicle', 0)->assertJsonPath('data.summary.without_vehicle', 0)
        ->assertJsonPath('data.summary.identification_percentage', 0);
    $this->getJson(vehicleMetricsUrl('export', [...$empty, 'format' => 'pdf']))->assertOk();
    $book = vehicleWorkbook($this->getJson(vehicleMetricsUrl('export', [...$empty, 'format' => 'xlsx']))->assertOk()->getContent());
    expect($book->getSheetByName('Resumo veicular')->getCell('B5')->getValue())->toBe(0)
        ->and($book->getSheetByName('Modelos')->getCell('A5')->getValue())->toBe('Nenhuma ocorrência para os filtros selecionados.');
    $book->disconnectWorksheets();
});
