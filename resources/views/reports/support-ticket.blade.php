<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatório individual de atendimento | SupportFlow</title>
    <style>
        @page { margin: 32pt 36pt 44pt; }
        body { margin: 0; color: #28364a; font: 9.5pt/1.45 'DejaVu Sans', sans-serif; }
        header { border-bottom: 1.4pt solid #245cda; margin-bottom: 14pt; padding-bottom: 9pt; }
        .masthead { margin: 0 0 9pt; width: 100%; }
        .masthead td { padding: 0; }
        .brand { color: #245cda; font-size: 15pt; font-weight: bold; }
        .number { text-align: right; font-size: 9pt; color: #475569; }
        h1 { margin: 0; font-size: 17pt; line-height: 1.2; color: #163861; }
        .ticket-title { margin: 5pt 0 6pt; font-size: 12pt; font-weight: bold; }
        h2 { color: #163861; font-size: 11pt; margin: 14pt 0 5pt; padding: 0 0 3pt; border-bottom: .6pt solid #dbe4ef; page-break-after: avoid; }
        h3 { font-size: 9pt; margin: 7pt 0 3pt; color: #163861; page-break-after: avoid; }
        p { margin: 0 0 5pt; }
        .badge { display: inline-block; background: #edf3fa; color: #163861; padding: 2pt 5pt; font-size: 8pt; font-weight: bold; }
        .badge-complete { background: #e8f6ee; color: #21563c; }
        .badge-pending { background: #fff4de; color: #784a17; }
        .badge-overdue { background: #fceced; color: #8a3042; }
        .muted { color: #596b82; }
        .facts, .related { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .facts th, .facts td, .related th, .related td { padding: 4pt 7pt; border-bottom: .5pt solid #e5ebf2; text-align: left; vertical-align: top; overflow-wrap: break-word; }
        .facts th { width: 31%; background: #f5f8fc; font-weight: bold; }
        .related th { background: #edf3fa; color: #163861; font-size: 8pt; }
        .related thead { display: table-header-group; page-break-after: avoid; }
        .related tr, .facts tr { page-break-inside: avoid; }
        .related .reference { width: 11%; }
        .related .topic { width: 32%; }
        .related .system { width: 22%; }
        .related .opened { width: 18%; }
        .related .status { width: 17%; }
        .vehicle td, .vehicle th { background: #f0f5ff; }
        .plate { font-size: 11pt; font-weight: bold; color: #163861; }
        .narrative { white-space: pre-wrap; overflow-wrap: break-word; }
        .empty { background: #f5f8fc; color: #596b82; padding: 5pt 7pt; }
        .entry { border-top: .5pt solid #e5ebf2; padding-top: 5pt; margin: 0 0 7pt; }
        .entry:first-child { border-top: 0; }
        .entry-meta { font-size: 8pt; margin-bottom: 4pt; page-break-after: avoid; }
        .entry-meta span { margin-right: 9pt; }
        .subcategories { margin: 3pt 0 6pt; padding-left: 17pt; }
    </style>
</head>
<body>
    <header>
        <table class="masthead"><tr>
            <td class="brand">SupportFlow</td>
            <td class="number">Atendimento #{{ $number }}</td>
        </tr></table>
        <h1>Relatório individual de atendimento</h1>
        <p class="ticket-title narrative">{{ $ticket->title }}</p>
        <span class="badge">{{ $statusLabel($ticket->status) }}</span>
    </header>

    <section>
        <h2>Informações gerais do atendimento</h2>
        <table class="facts">
            <tbody>
                <tr><th scope="row">Abertura</th><td>{{ $date($ticket->created_at) }}</td></tr>
                <tr><th scope="row">Última atualização</th><td>{{ $date($ticket->updated_at) }}</td></tr>
                <tr><th scope="row">Encerramento</th><td>{{ $ticket->status === \App\Enums\TicketStatus::Closed->value ? $date($ticket->closed_at, 'Não informado') : 'Não encerrado' }}</td></tr>
                <tr><th scope="row">Sistema</th><td>{{ $ticket->system?->name ?? 'Não informado' }}</td></tr>
                <tr><th scope="row">Contato</th><td>{{ $ticket->contact?->name ?? 'Não informado' }}</td></tr>
                <tr><th scope="row">Cliente</th><td>{{ $ticket->customer?->name ?? 'Não associado' }}</td></tr>
            </tbody>
        </table>
    </section>

    @if ($sections['include_classification'] ?? true)
        <section>
            <h2>Classificação do problema</h2>
            @if ($ticket->problemCategory)
                <p class="narrative"><strong>Categoria</strong> · {{ $ticket->problemCategory->name }}</p>
                @if ($ticket->problemSubcategories->isNotEmpty())
                    <h3>Subcategorias</h3>
                    <ul class="subcategories">
                        @foreach ($ticket->problemSubcategories as $subcategory)
                            <li class="narrative">{{ $subcategory->name }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="muted">Nenhuma subcategoria vinculada.</p>
                @endif
            @else
                <p class="empty">Nenhuma classificação registrada.</p>
            @endif
        </section>
    @endif

    @if (($sections['include_vehicle'] ?? true) && ($vehicle = $ticket->vehicle))
        <section>
            <h2>Identificação veicular</h2>
            <table class="facts vehicle">
                <tbody>
                    <tr><th scope="row">Placa</th><td class="plate">{{ $vehicle->plate }}</td></tr>
                    <tr><th scope="row">Marca</th><td>{{ $vehicle->brand?->name ?? 'Não informada' }}</td></tr>
                    <tr><th scope="row">Linha veicular</th><td>{{ match ($vehicle->vehicle_line) {
                        \App\Enums\VehicleLine::Light => 'Linha leve',
                        \App\Enums\VehicleLine::Heavy => 'Linha pesada',
                        default => 'Não informada',
                    } }}</td></tr>
                </tbody>
            </table>
            <h3>Modelo</h3>
            <p class="narrative">{{ $vehicle->model_description ?? 'Modelo não informado' }}</p>
        </section>
    @endif

    <section>
        <h2>Descrição do problema</h2>
        <p class="narrative">{{ $ticket->description }}</p>
    </section>

    @if ($sections['include_resolution'] ?? true)
        <section>
            <h2>Resolução e confirmação</h2>
            <h3>Solução aplicada</h3>
            <p class="narrative">{{ filled($ticket->resolution) ? $ticket->resolution : 'A solução ainda não foi registrada.' }}</p>
            <table class="facts">
                <tbody>
                    <tr><th scope="row">Fonte da confirmação</th><td>{{ match ($ticket->verification_source) {
                        'customer' => 'Cliente',
                        'responsible_department' => 'Setor responsável',
                        default => 'Não informada',
                    } }}</td></tr>
                    <tr><th scope="row">Confirmado em</th><td>{{ $date($ticket->verified_at) }}</td></tr>
                    <tr><th scope="row">Confirmado por</th><td>{{ filled($ticket->verified_by) ? $ticket->verified_by : 'Não informado' }}</td></tr>
                </tbody>
            </table>
            <h3>Como foi verificado</h3>
            <p class="narrative">{{ filled($ticket->verification_notes) ? $ticket->verification_notes : 'Nenhuma observação registrada.' }}</p>
        </section>
    @endif

    @if ($sections['include_pending_tasks'] ?? true)
        <section>
            <h2>Pendências associadas</h2>
            <div>
                @forelse ($ticket->pendingTasks->sortBy('id') as $task)
                    <div class="entry">
                        @php
                            $completed = $task->status === \App\Enums\PendingTaskStatus::Completed->value;
                            $overdue = !$completed && ($task->due_at?->lt($generatedAt) ?? false);
                        @endphp
                        <div class="entry-meta muted">
                            <span @class(['badge', 'badge-complete' => $completed, 'badge-overdue' => $overdue, 'badge-pending' => !$completed && !$overdue])>{{ $completed ? 'Concluída' : ($overdue ? 'Atrasada' : 'Pendente') }}</span>
                            <span>Criação {{ $date($task->created_at) }}</span>
                            <span>Prazo {{ $date($task->due_at, 'não informado') }}</span>
                            @if ($task->completed_at)<span>Conclusão {{ $date($task->completed_at) }}</span>@endif
                        </div>
                        <p class="narrative">{{ $task->description }}</p>
                    </div>
                @empty
                    <p class="empty">Nenhuma pendência associada a este atendimento.</p>
                @endforelse
            </div>
        </section>
    @endif

    @if ($sections['include_reminders'] ?? true)
        <section>
            <h2>Lembretes associados</h2>
            <div>
                @forelse ($ticket->reminders->sortBy([['scheduled_at', 'asc'], ['id', 'asc']]) as $reminder)
                    <div class="entry">
                        @php($overdue = !$reminder->completed_at && $reminder->scheduled_at->lt($generatedAt))
                        <div class="entry-meta muted">
                            <span @class(['badge', 'badge-complete' => $reminder->completed_at, 'badge-overdue' => $overdue, 'badge-pending' => !$reminder->completed_at && !$overdue])>{{ $reminder->completed_at ? 'Concluído' : ($overdue ? 'Atrasado' : 'Pendente') }}</span>
                            <span>Programado {{ $date($reminder->scheduled_at) }}</span>
                            @if ($reminder->completed_at)<span>Conclusão {{ $date($reminder->completed_at) }}</span>@endif
                        </div>
                        <p class="narrative"><strong>{{ $reminder->title }}</strong></p>
                        @if (filled($reminder->description))<p class="narrative">{{ $reminder->description }}</p>@endif
                    </div>
                @empty
                    <p class="empty">Nenhum lembrete associado a este atendimento.</p>
                @endforelse
            </div>
        </section>
    @endif

    @if ($sections['include_related_tickets'] ?? true)
        <section>
            <h2>Atendimentos relacionados</h2>
            @if ($ticket->previousTicket || $ticket->followUpTickets->isNotEmpty())
                <table class="related">
                    <thead>
                        <tr><th class="reference">Número</th><th class="topic">Título</th><th class="system">Sistema</th><th class="opened">Abertura</th><th class="status">Status</th></tr>
                    </thead>
                    <tbody>
                        @foreach (array_filter([$ticket->previousTicket, ...$ticket->followUpTickets->sortBy('id')]) as $related)
                            <tr>
                                <td>#{{ str_pad((string) $related->id, 4, '0', STR_PAD_LEFT) }}</td>
                                <td>{{ $related->title }}</td><td>{{ $related->system?->name ?? 'Não informado' }}</td>
                                <td>{{ $date($related->created_at) }}</td><td>{{ $statusLabel($related->status) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="empty">Sem vínculos anteriores ou retornos.</p>
            @endif
        </section>
    @endif
</body>
</html>
