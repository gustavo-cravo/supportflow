<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>{{ $document['title'] }}</title>
    <style>
        @page { margin: 34pt 38pt 47pt; }
        body { margin: 0; color: #28364a; font: 9.5pt/1.43 'DejaVu Sans', sans-serif; }
        h1, h2, h3, h4, h5 { margin: 0; color: #163861; page-break-after: avoid; page-break-inside: avoid; overflow-wrap: break-word; }
        h1 { font-size: 20pt; line-height: 1.2; margin: 7pt 0 5pt; }
        h2 { font-size: 13pt; margin: 12pt 0 5pt; padding-bottom: 4pt; border-bottom: .65pt solid #dbe4ef; }
        h3 { font-size: 10.5pt; margin: 7pt 0 4pt; }
        h4, h5 { font-size: 9.5pt; margin: 6pt 0 3pt; }
        p { margin: 0 0 5pt; overflow-wrap: break-word; }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        .masthead { border-bottom: 1pt solid #245cda; margin-bottom: 9pt; }
        .masthead td { padding: 0 0 6pt; }
        .brand { color: #245cda; font-size: 15pt; font-weight: bold; }
        .issued { color: #516580; font-size: 8.5pt; text-align: right; }
        .subtitle { color: #475c78; margin-bottom: 8pt; }
        .criteria, .note { font-size: 8.5pt; color: #516580; }
        .criteria span { padding-right: 9pt; }
        .note { margin: 2pt 0 6pt; }
        .empty { padding: 5pt 8pt; background: #f5f8fc; color: #475c78; margin: 3pt 0 6pt; }
        .data { margin: 3pt 0 8pt; }
        .data th, .data td { border-bottom: .5pt solid #e4eaf2; text-align: left; padding: 4pt 6pt; vertical-align: top; overflow-wrap: break-word; }
        .data th { color: #163861; background: #f0f5fc; font-size: 8.5pt; }
        .data th:first-child { width: 58%; }
        .data th:only-child { width: auto; }
        .table-context { display: block; font-size: 9.5pt; margin-bottom: 2pt; }
        .data thead { display: table-header-group; }
        .data thead tr { page-break-after: avoid; }
        .data tr { page-break-inside: avoid; }
        .continued td { border-bottom-color: #f2f5f9; }
        .cards { margin: 3pt 0 8pt; border-collapse: separate; border-spacing: 5pt 0; }
        .cards td { vertical-align: top; border-left: 2pt solid #245cda; padding: 5pt 8pt; background: #f5f8fc; overflow-wrap: break-word; }
        .cards strong { display: block; font-size: 16pt; color: #163861; line-height: 1.2; }
        .cards small { font-size: 8.5pt; }
        .group { margin: 3pt 0 7pt; }
        .label-continuation { margin-bottom: 1pt; }
        .keep, .chart { page-break-inside: avoid; }
        .chart { margin: 4pt 0 7pt; }
        .chart img { display: block; }
        .plot { width: 430pt; }
        .bar-row { padding: 3pt 0; }
        .bar-track { background: #eaf0fa; height: 6pt; margin-top: 3pt; }
        .bar-fill { height: 6pt; background: #245cda; }
        .ring { table-layout: auto; }
        .ring td { vertical-align: middle; }
        .ring .graphic { width: 115pt; padding-right: 10pt; }
        .ring img { width: 93pt; }
        .key { color: #163861; font-weight: bold; }
        .description { padding-left: 8pt; border-left: .7pt solid #dbe4ef; margin-bottom: 6pt; }
        .description ul { margin: 0 0 6pt; padding-left: 16pt; }
        .description li { margin-bottom: 2pt; overflow-wrap: break-word; }
    </style>
</head>
<body>
    <table class="masthead"><tr>
        <td class="brand">SupportFlow</td>
        <td class="issued">Emitido em {{ $document['issued'] }}</td>
    </tr></table>
    <h1>{{ $document['title'] }}</h1>
    @if($document['subtitle'] !== null)
        <p class="subtitle">{{ $document['subtitle'] }}</p>
    @endif
    <p><strong>Período analisado</strong> {{ $document['period'] }}</p>
    @if($document['criteria'] !== [])
        <p class="criteria">
            @foreach($document['criteria'] as $criterion)
                <span><strong>{{ $criterion['label'] }}</strong> {{ $criterion['name'] }}</span>
            @endforeach
        </p>
    @endif
    @if($document['empty'])
        <p class="empty">Nenhum atendimento encontrado para os filtros selecionados.</p>
    @endif
    @foreach($document['sections'] as $section)
        <h2>{{ $section['label'] }}</h2>
        @foreach($section['blocks'] as $block)
            @include('reports.partials.configured-metrics-block', ['block' => $block, 'depth' => 3])
        @endforeach
    @endforeach
</body>
</html>
