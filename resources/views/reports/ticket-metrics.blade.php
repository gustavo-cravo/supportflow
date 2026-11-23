<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>{{ \App\Support\TicketMetricsReportData::TITLE }}</title>
    <style>
        @page { margin: 38pt 40pt 52pt; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #202b40; line-height: 1.45; }
        .brand { font-size: 22pt; font-weight: bold; color: #245cda; margin: 0 0 15pt; }
        h1 { font-size: 20pt; line-height: 1.25; margin: 0 0 9pt; }
        h2 { font-size: 12pt; margin: 22pt 0 10pt; padding-bottom: 7pt; border-bottom: 1pt solid #e9edf4; page-break-after: avoid; }
        p { margin: 0 0 10pt; }
        .muted { color: #758096; font-size: 8pt; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 12pt; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { padding: 8pt; border-bottom: 0.5pt solid #e9edf4; text-align: left; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
        th { background: #edf3ff; color: #245cda; font-size: 8pt; font-weight: bold; }
        tbody tr:nth-child(even) td { background: #f8faff; }
        .number { text-align: right; width: 19%; }
        .criteria { margin: 17pt 0; }
        .criteria th { width: 25%; background: #f6f8fc; color: #758096; }
        .criteria td { font-size: 8pt; }
        .cards { margin-bottom: 18pt; }
        .cards td { width: 20%; padding: 10pt 6pt; background: #f6f8fc; border: 3pt solid white; }
        .cards strong { font-size: 22pt; color: #245cda; display: block; }
        .cards span { font-size: 8pt; color: #758096; }
        .group-title { background: #f6f8fc; color: #202b40; font-size: 10pt; }
        .empty { padding: 15pt; background: #f6f8fc; color: #758096; }
        .new-page { page-break-before: always; }
        .monthly-block { page-break-inside: avoid; margin-bottom: 15pt; }
        .monthly-block img { width: 100%; height: 150pt; }
        .distribution { page-break-inside: avoid; }
    </style>
</head>
<body>
    <div class="brand">SupportFlow</div>
    <h1>{{ \App\Support\TicketMetricsReportData::TITLE }}</h1>
    <p class="muted">Gerado em {{ $report->generatedAt->format('d/m/Y \à\s H:i') }} - Horário de Brasília</p>
    <table class="criteria"><tbody>
        @foreach ($report->criteria as $criterion)
            <tr><th>{{ $criterion['label'] }}</th><td>{{ $criterion['value'] }}</td></tr>
        @endforeach
    </tbody></table>
    <h2>1. Resumo geral</h2>
    <table class="cards"><tr>
        @foreach (['total' => 'Total de atendimentos', 'closed' => 'Encerrados', 'not_closed' => 'Não encerrados', 'systems_count' => 'Sistemas com atendimentos', 'unclassified' => 'Sem classificação'] as $key => $label)
            <td><strong>{{ number_format($report->summary[$key], 0, ',', '.') }}</strong><span>{{ $label }}</span></td>
        @endforeach
    </tr></table>
    @if ($report->summary['total'] === 0)
        <p class="empty">Nenhum atendimento encontrado para os filtros selecionados.</p>
    @endif
    <div class="distribution">
    <h2>2. Distribuição por sistema</h2>
    <table><thead><tr><th>Sistema</th><th class="number">Atendimentos</th><th class="number">Participação</th></tr></thead><tbody>
        @forelse ($report->systems as $row)
            <tr><td>{{ $row['name'] }}</td><td class="number">{{ number_format($row['total'], 0, ',', '.') }}</td><td class="number">{{ number_format($row['percentage'], 2, ',', '.') }}%</td></tr>
        @empty
            <tr><td colspan="3">Sem ocorrências no período.</td></tr>
        @endforelse
    </tbody></table>
    </div>
    <div class="distribution">
    <h2>3. Distribuição por categoria</h2>
    <table><thead><tr><th>Categoria</th><th class="number">Ocorrências</th><th class="number">Participação</th></tr></thead><tbody>
        @forelse ($report->categories as $row)
            <tr><td>{{ $row['name'] }}</td><td class="number">{{ number_format($row['total'], 0, ',', '.') }}</td><td class="number">{{ number_format($row['percentage'], 2, ',', '.') }}%</td></tr>
        @empty
            <tr><td colspan="3">Sem ocorrências no período.</td></tr>
        @endforelse
    </tbody></table>
    </div>
    <div class="new-page"></div>
    <h2>4. Distribuição por subcategoria</h2>
    <p class="muted">Participação calculada dentro de cada categoria, respeitando todos os filtros.</p>
    @forelse ($report->categories as $category)
        <div class="distribution">
        <table><colgroup><col style="width: 62%"><col style="width: 19%"><col style="width: 19%"></colgroup><thead>
            <tr><th colspan="3" class="group-title">{{ $category['name'] }} - Total: {{ number_format($category['total'], 0, ',', '.') }}</th></tr>
            <tr><th>Subcategoria</th><th class="number">Ocorrências</th><th class="number">Na categoria</th></tr>
        </thead><tbody>
            @foreach ($category['subcategories'] as $row)
                <tr><td>{{ $row['name'] }}</td><td class="number">{{ number_format($row['total'], 0, ',', '.') }}</td><td class="number">{{ number_format($row['percentage'], 2, ',', '.') }}%</td></tr>
            @endforeach
        </tbody></table>
        </div>
    @empty
        <p class="empty">Nenhuma categoria ou subcategoria com ocorrências no período.</p>
    @endforelse
    <div class="new-page"></div>
    <h2>5. Evolução temporal</h2>
    <p class="muted">Quantidade por mês de abertura. Os períodos limitados incluem os meses sem atendimentos; todo o histórico apresenta os meses com registros.</p>
    @forelse ($charts as $chart)
        <div class="monthly-block">
            <img src="{{ $chart['image'] }}" alt="Evolução mensal dos atendimentos" />
            <table><thead><tr><th>Mês</th><th class="number">Atendimentos</th></tr></thead><tbody>
                @foreach ($chart['rows'] as $row)
                    <tr><td>{{ substr($row['month'], 5, 2) }}/{{ substr($row['month'], 0, 4) }}</td><td class="number">{{ number_format($row['total'], 0, ',', '.') }}</td></tr>
                @endforeach
            </tbody></table>
        </div>
    @empty
        <p class="empty">Sem meses com registros para os filtros selecionados.</p>
    @endforelse
</body>
</html>
