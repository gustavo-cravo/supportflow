<?php

use App\Models\Contact;
use App\Models\Customer;
use App\Models\PendingTask;
use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\Reminder;
use App\Models\SupportTicket;
use App\Models\System;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Services\Reports\PdfSupportTicketReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;

function individualReportTicket(array $attributes = []): SupportTicket
{
    return SupportTicket::create([
        'system_id' => System::create(['name' => 'Portal de Suporte'])->id,
        'contact_id' => Contact::create(['name' => 'João Técnico', 'email' => 'email-privado@example.test', 'notes' => 'CONTATO_INTERNO', 'phone' => '991234567'])->id,
        'title' => 'Identificação incorreta do veículo',
        'description' => "Primeira linha: verificação de pressão.\n\nSegunda linha: <b>literal</b> & 45°.",
        ...$attributes,
    ]);
}

function individualReportUrl(SupportTicket $ticket): string
{
    return '/api/support-tickets/'.$ticket->id.'/export/pdf';
}

function individualPdfStreams(string $bytes): string
{
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $matches);

    return implode("\n", array_map(fn (string $stream) => @gzuncompress($stream) ?: $stream, $matches[1]));
}

function individualPdfText(string $text): string
{
    return mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
}

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-24 16:45:00', 'America/Sao_Paulo')));
afterEach(function () {
    $this->travelBack();
    gc_collect_cycles();
});

test('downloads an open ticket PDF with defaults, placeholders, literal Unicode text and safe attachment headers', function () {
    $ticket = individualReportTicket();
    $response = $this->postJson(individualReportUrl($ticket))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload(sprintf('supportflow-atendimento-%04d-2026-11-24.pdf', $ticket->id));
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $bytes = $response->getContent();
    expect($bytes)->toStartWith('%PDF-')->toContain('%%EOF');
    expect($bytes)->toMatch('/\/MediaBox\s*\[\s*0\.\d+\s+0\.\d+\s+595\.\d+\s+841\.\d+\s*\]/');
    $streams = individualPdfStreams($bytes);
    foreach (['Relatório individual de atendimento', 'Aberto', 'Não encerrado', 'Não associado',
        'Informações gerais do atendimento', 'Descrição do problema', 'Primeira linha: verificação de pressão.',
        '<b>literal</b> & 45°.', 'Nenhuma classificação registrada.', 'A solução ainda não foi registrada.',
        'Nenhuma pendência associada a este atendimento.', 'Nenhum lembrete associado a este atendimento.',
        'Sem vínculos anteriores ou retornos.', '24/11/2026 16:45', 'Página 1 de'] as $text) {
        expect($streams)->toContain(individualPdfText($text));
    }
    expect($streams)->not->toContain(individualPdfText('Identificação veicular'), individualPdfText('CONTATO_INTERNO'),
        individualPdfText('email-privado@example.test'), individualPdfText('991234567'));
    $this->assertDatabaseHas('support_tickets', ['id' => $ticket->id, 'status' => 'open', 'closed_at' => null]);
});

test('exports all chosen sections of a closed ticket using only current and directly related records', function () {
    $customer = Customer::create(['name' => 'Oficina de José', 'document' => '01234567891234', 'email' => 'cliente-privado@example.test']);
    $brand = VehicleBrand::create(['name' => 'IVECO']);
    $vehicle = Vehicle::factory()->for($brand, 'brand')->create(['plate' => 'aaa-0000', 'vehicle_line' => 'heavy', 'model_description' => 'Descrição anterior']);
    $previous = individualReportTicket(['title' => 'Chamado anterior vinculado', 'description' => 'CONTEUDO_ANTERIOR_PRIVADO']);
    $category = ProblemCategory::create(['name' => 'Identificação em oficina']);
    $subcategories = ['Placa divergente', 'Modelo incorreto'];
    $ids = array_map(fn (string $name) => ProblemSubcategory::create(['name' => $name, 'problem_category_id' => $category->id])->id, $subcategories);
    $ticket = individualReportTicket([
        'previous_ticket_id' => $previous->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
        'problem_category_id' => $category->id, 'problem_subcategory_id' => $ids[0],
        'status' => 'closed', 'closed_at' => '2026-11-24 15:30:00', 'resolution' => "Correção aplicada.\nResultado conferido.",
        'verification_source' => 'responsible_department', 'verified_at' => '2026-11-24 15:30:00', 'verified_by' => 'Ana',
        'verification_notes' => 'Conferência pela engenharia.',
    ]);
    $ticket->problemSubcategories()->attach($ids, ['problem_category_id' => $category->id]);
    $vehicle->update(['model_description' => null]);
    $followUp = individualReportTicket(['title' => 'Retorno do chamado', 'previous_ticket_id' => $ticket->id]);
    individualReportTicket(['title' => 'Chamado fora do recorte', 'vehicle_id' => $vehicle->id, 'previous_ticket_id' => $followUp->id]);
    $ticket->pendingTasks()->create(['description' => 'Conferir especificação', 'due_at' => '2026-11-26 08:30:00']);
    $ticket->pendingTasks()->create(['description' => 'Verificação vencida', 'due_at' => '2026-11-23 08:00:00']);
    $ticket->pendingTasks()->create(['description' => 'Revisão concluída', 'status' => 'completed', 'completed_at' => '2026-11-24 16:00:00']);
    PendingTask::create(['description' => 'PENDENCIA_EXTERNA', 'support_ticket_id' => $previous->id]);
    $ticket->reminders()->create(['title' => 'Conferir retorno', 'description' => 'Ligar ao setor', 'scheduled_at' => '2026-11-25 09:15:00']);
    $ticket->reminders()->create(['title' => 'Retorno concluído', 'scheduled_at' => '2026-11-24 13:00:00', 'completed_at' => '2026-11-24 14:00:00']);
    $ticket->reminders()->create(['title' => 'Retorno em atraso', 'scheduled_at' => '2026-11-23 13:00:00']);
    Reminder::create(['title' => 'LEMBRETE_INDEPENDENTE', 'scheduled_at' => '2026-11-25 10:00:00']);
    $original = $ticket->refresh()->getRawOriginal();

    DB::enableQueryLog();
    try {
        $streams = individualPdfStreams($this->postJson(individualReportUrl($ticket), [
            'include_classification' => true, 'include_vehicle' => true, 'include_resolution' => true,
            'include_pending_tasks' => true, 'include_reminders' => true, 'include_related_tickets' => true,
        ])->assertOk()->getContent());
        expect(array_filter(DB::getQueryLog(), fn (array $entry) => ! str_starts_with(strtolower($entry['query']), 'select')))->toBe([]);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    foreach (['Encerrado', '24/11/2026 15:30', 'Oficina de José', 'Identificação veicular', 'AAA0000', 'IVECO', 'Linha pesada',
        'Modelo não informado', 'Identificação em oficina', ...$subcategories, 'Correção aplicada.', 'Resultado conferido.',
        'Setor responsável', 'Ana', 'Conferência pela engenharia.', 'Conferir especificação', '26/11/2026 08:30',
        'Revisão concluída', 'Concluída', 'Conferir retorno', '25/11/2026 09:15', 'Retorno concluído', 'Concluído',
        'Chamado anterior vinculado', 'Retorno do chamado', 'Pendente', 'Atrasada', 'Atrasado'] as $text) {
        expect($streams)->toContain(individualPdfText($text));
    }
    foreach (['Descrição anterior', 'PENDENCIA_EXTERNA', 'LEMBRETE_INDEPENDENTE', 'Chamado fora do recorte',
        'CONTEUDO_ANTERIOR_PRIVADO', '01234567891234', 'cliente-privado@example.test'] as $text) {
        expect($streams)->not->toContain(individualPdfText($text));
    }
    expect($ticket->fresh()->getRawOriginal())->toBe($original);
});

test('omits all unchecked sections and does not load their relations or honor attempts to omit mandatory data', function () {
    $vehicle = Vehicle::factory()->create(['plate' => 'PRV-0123', 'model_description' => 'Modelo reservado']);
    $ticket = individualReportTicket(['vehicle_id' => $vehicle->id, 'resolution' => 'SOLUCAO_OCULTA']);
    DB::enableQueryLog();
    try {
        $streams = individualPdfStreams($this->postJson(individualReportUrl($ticket), [
            'include_classification' => false, 'include_vehicle' => false, 'include_resolution' => false,
            'include_pending_tasks' => false, 'include_reminders' => false, 'include_related_tickets' => false,
            'include_general' => false, 'include_description' => false, 'status' => 'closed',
        ])->assertOk()->getContent());
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        expect($sql)->not->toContain('"vehicles"', '"vehicle_brands"', '"pending_tasks"', '"reminders"', '"problem_categories"', '"problem_subcategory_support_ticket"');
        expect(substr_count($sql, 'from "support_tickets"'))->toBe(1);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    foreach (['Classificação do problema', 'Identificação veicular', 'Resolução e confirmação', 'Pendências associadas',
        'Lembretes associados', 'Atendimentos relacionados', 'SOLUCAO_OCULTA', 'PRV0123'] as $text) {
        expect($streams)->not->toContain(individualPdfText($text));
    }
    expect($streams)->toContain(individualPdfText('Informações gerais do atendimento'), individualPdfText('Descrição do problema'), individualPdfText('Aberto'));
});

test('exports every status as recorded even when a solution is filled', function (string $status, string $label) {
    $ticket = individualReportTicket(['status' => $status, 'resolution' => '0']);
    $streams = individualPdfStreams($this->postJson(individualReportUrl($ticket))->assertOk()->getContent());
    expect($streams)->toContain(individualPdfText($label))->not->toContain(individualPdfText('A solução ainda não foi registrada.'));
})->with([
    ['open', 'Aberto'], ['in_progress', 'Em andamento'], ['waiting', 'Aguardando retorno'], ['resolved', 'Resolvido'], ['closed', 'Encerrado'],
]);

test('paginates extended descriptions and complete model text across the PDF', function () {
    $vehicle = Vehicle::factory()->create([
        'model_description' => str_repeat('Versão 4x4 16V edição especial. ', 265).'FIMMODELO',
    ]);
    $ticket = individualReportTicket([
        'vehicle_id' => $vehicle->id,
        'description' => str_repeat("Parágrafo técnico. Tensão medida, revisão e conferência.\n\n", 115).'FIMDESCRICAO',
        'resolution' => str_repeat("Solução técnica com orientação para manutenção.\n", 110).'FIMSOLUCAO',
    ]);
    $ticket->pendingTasks()->create(['description' => str_repeat("Pendência de revisão do diagnóstico.\n", 120).'FIMPENDENCIA']);
    $pdf = $this->postJson(individualReportUrl($ticket))->assertOk()->getContent();
    preg_match_all('/\/Type\s*\/Page\b/', $pdf, $pages);
    expect(count($pages[0]))->toBeGreaterThan(5);
    $streams = individualPdfStreams($pdf);
    foreach (['FIMMODELO', 'FIMDESCRICAO', 'FIMSOLUCAO', 'FIMPENDENCIA'] as $last) {
        expect($streams)->toContain(individualPdfText($last));
    }
});

test('validates options, responds to missing tickets and handles renderer failures without exposing details', function () {
    $this->postJson('/api/support-tickets/999999/export/pdf')->assertNotFound();
    $ticket = individualReportTicket();
    $pdf = Mockery::mock(PdfSupportTicketReport::class, function (MockInterface $mock): void {
        $mock->shouldReceive('render')->once()->andThrow(new RuntimeException('PRIVATE_RENDER_FAILURE'));
    });
    $this->instance(PdfSupportTicketReport::class, $pdf);
    $this->postJson(individualReportUrl($ticket), ['include_vehicle' => 'true', 'include_pending_tasks' => 0, 'include_related_tickets' => null])
        ->assertUnprocessable()->assertJsonValidationErrors(['include_vehicle', 'include_pending_tasks', 'include_related_tickets']);
    $response = $this->postJson(individualReportUrl($ticket))->assertStatus(500)
        ->assertJsonPath('message', 'Não foi possível gerar o PDF do atendimento. Tente novamente.');
    expect($response->headers->get('Content-Disposition'))->toBeNull();
    expect($response->getContent())->not->toContain('PRIVATE_RENDER_FAILURE');
});
