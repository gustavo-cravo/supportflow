<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatório Estatístico de Atendimentos | SupportFlow</title>
    <style>
        @page { margin: 30pt 36pt 44pt; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #28364a; line-height: 1.3; }
        h1 { font-size: 16pt; line-height: 1.2; color: #163861; margin: 0 0 4pt; }
        h2 { font-size: 10.5pt; color: #163861; margin: 9pt 0 5pt; padding-bottom: 4pt; border-bottom: 0.6pt solid #dbe4ef; page-break-after: avoid; }
        p { margin: 0 0 6pt; }
        .muted { color: #64748b; font-size: 8pt; }
        .intro { margin-bottom: 5pt; line-height: 1.2; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 4pt; }
        thead { display: table-header-group; page-break-after: avoid; }
        thead tr { page-break-after: avoid; }
        tr { page-break-inside: avoid; }
        th, td { padding: 4pt 7pt; border-bottom: 0.5pt solid #e5ebf2; text-align: left; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
        th { background: #edf3fa; color: #163861; font-size: 8pt; font-weight: bold; }
        tbody tr:nth-child(even) td { background: #f8fafc; }
        .number { text-align: right; width: 19%; }
        .masthead { margin-bottom: 4pt; }
        .masthead td { padding: 0; border: 0; background: white; vertical-align: middle; line-height: 1.1; }
        .brand { font-size: 14pt; font-weight: bold; color: #245cda; }
        .issued { text-align: right; font-size: 8pt; color: #64748b; }
        .criteria td { width: 50%; background: #f8fafc; font-size: 8.5pt; line-height: 1.15; padding: 4pt 8pt; }
        .criteria strong { display: block; font-size: 7.5pt; color: #64748b; margin-bottom: 1pt; }
        .cards { page-break-inside: avoid; }
        .cards td { width: 20%; padding: 5pt; background: #edf3fa; border: 2pt solid white; line-height: 1.15; }
        .cards strong { font-size: 19pt; line-height: 1.1; color: #163861; display: block; margin-bottom: 3pt; }
        .cards span { font-size: 8pt; color: #475569; }
        .distributions > tbody > tr > td { padding: 0; border: 0; background: white; }
        .distributions .spacer { width: 4%; }
        .distributions .column { width: 48%; }
        .compact { font-size: 8.5pt; }
        .compact .number { width: 23%; }
        .category { width: 34%; }
        .subcategory { width: 38%; }
        .subcategories .number { width: 14%; }
        .empty { padding: 7pt 9pt; background: #f8fafc; color: #64748b; font-size: 8.5pt; }
        .section-note { page-break-after: avoid; }
        .monthly-chart { margin: 5pt 0; page-break-inside: avoid; page-break-after: avoid; }
        .monthly-chart img { display: block; width: 100%; height: 88pt; }
        .monthly-values { font-size: 8.5pt; margin-bottom: 9pt; page-break-inside: avoid; }
        .monthly-values th, .monthly-values td { padding: 4pt 7pt; }
        .monthly-layout > tbody > tr > td { padding: 0; border: 0; background: white; vertical-align: middle; }
        .monthly-layout .plot { width: 62%; }
        .monthly-layout .spacer { width: 3%; }
        .monthly-layout .values { width: 35%; }
        .monthly-layout img { display: block; width: 100%; height: 70pt; }
        .monthly-layout.single-month img { height: 50pt; }
        .monthly-layout .number { width: 50%; }
    </style>
</head>
<body>
    @php
        $criteria = collect($report->criteria);
        $period = $criteria->firstWhere('label', 'Período analisado');
        $filters = $criteria->reject(fn ($criterion) => in_array($criterion['label'], ['Período analisado', 'Fuso horário']))->values();
        // Only short tables share a row: Dompdf cannot paginate an outer table cell.
        $compactDistributions = count($report->systems) <= 5 && count($report->categories) <= 5
            && collect(array_merge($report->systems, $report->categories))->every(fn ($row) => mb_strlen($row['name']) <= 48);
    @endphp
    <table class="masthead"><tr>
        <td class="brand">SupportFlow</td>
        <td class="issued">Emitido em {{ $report->generatedAt->format('d/m/Y \à\s H:i') }}</td>
    </tr></table>
    <h1>Relatório Estatístico de Atendimentos</h1>
    <p class="muted intro"><strong>Período analisado:</strong> {{ $period['value'] ?? '' }}</p>
    <table class="criteria"><tbody>
        @foreach ($filters->chunk(2) as $pair)
            <tr>
                @foreach ($pair as $criterion)
                    <td><strong>{{ $criterion['label'] }}</strong>{{ $criterion['value'] }}</td>
                @endforeach
                @if ($pair->count() === 1)<td></td>@endif
            </tr>
        @endforeach
    </tbody></table>
    <h2>Resumo geral</h2>
    <table class="cards"><tr>
        @foreach (['total' => 'Total de atendimentos', 'closed' => 'Encerrados', 'not_closed' => 'Não encerrados', 'systems_count' => 'Sistemas com atendimentos', 'unclassified' => 'Sem classificação'] as $key => $label)
            <td><strong>{{ number_format($report->summary[$key], 0, ',', '.') }}</strong><span>{{ $label }}</span></td>
        @endforeach
    </tr></table>
    @if ($report->summary['total'] === 0)
        <p class="empty">Nenhum atendimento encontrado para os filtros selecionados.</p>
    @endif
    @if ($compactDistributions)
        <table class="distributions"><tbody><tr>
            <td class="column">@include('reports.partials.metrics-distribution', ['title' => 'Distribuição por sistema', 'label' => 'Sistema', 'rows' => $report->systems, 'compact' => true])</td>
            <td class="spacer"></td>
            <td class="column">@include('reports.partials.metrics-distribution', ['title' => 'Distribuição por categoria', 'label' => 'Categoria', 'rows' => $report->categories, 'compact' => true])</td>
        </tr></tbody></table>
    @else
        @include('reports.partials.metrics-distribution', ['title' => 'Distribuição por sistema', 'label' => 'Sistema', 'rows' => $report->systems, 'compact' => false])
        @include('reports.partials.metrics-distribution', ['title' => 'Distribuição por categoria', 'label' => 'Categoria', 'rows' => $report->categories, 'compact' => false])
    @endif
    <h2>Distribuição por subcategoria</h2>
    <p class="muted section-note">Participação dentro da respectiva categoria, considerando os filtros aplicados.</p>
    <table class="subcategories"><thead><tr>
        <th class="category">Categoria</th><th class="subcategory">Subcategoria</th><th class="number">Ocorrências</th><th class="number">Na categoria</th>
    </tr></thead><tbody>
        @forelse ($report->categories as $category)
            @foreach ($category['subcategories'] as $row)
                <tr>
                    <td>{{ $category['name'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td class="number">{{ number_format($row['total'], 0, ',', '.') }}</td>
                    <td class="number">{{ number_format($row['percentage'], 2, ',', '.') }}%</td>
                </tr>
            @endforeach
        @empty
            <tr><td colspan="4">Nenhuma categoria ou subcategoria com ocorrências no período.</td></tr>
        @endforelse
    </tbody></table>
    <h2>Evolução temporal</h2>
    <p class="muted section-note">Atendimentos por mês de abertura. Períodos limitados incluem meses zerados; todo o histórico mostra os meses com registros.</p>
    @forelse ($charts as $chart)
        @if (count($chart['rows']) <= 4)
            <table @class(['monthly-layout', 'single-month' => count($chart['rows']) === 1])><tbody><tr>
                <td class="plot"><img src="{{ $chart['image'] }}" alt="Evolução mensal dos atendimentos" /></td>
                <td class="spacer"></td>
                <td class="values">@include('reports.partials.metrics-monthly-values', ['rows' => $chart['rows'], 'columns' => 1])</td>
            </tr></tbody></table>
        @else
            <div class="monthly-chart"><img src="{{ $chart['image'] }}" alt="Evolução mensal dos atendimentos" /></div>
            @include('reports.partials.metrics-monthly-values', ['rows' => $chart['rows'], 'columns' => 3])
        @endif
    @empty
        <p class="empty">Sem meses com registros para os filtros selecionados.</p>
    @endforelse
</body>
</html>
