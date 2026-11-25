<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>{{ $document['title'] }}</title>
    <style>
        @page { margin: 48pt 38pt 48pt; }
        @page :first { margin-top: 34pt; }
        body { margin: 0; color: #28364a; font: 10pt/1.38 'DejaVu Sans', sans-serif; }
        h1, h2, h3, p, ul { margin: 0; }
        h1, h2, h3, .group-heading, .field-label {
            color: #163861; page-break-after: avoid; overflow-wrap: break-word;
        }
        h1, h2, h3, .field-label { page-break-inside: avoid; }
        h1 { font-size: 20pt; line-height: 1.2; margin-bottom: 5pt; }
        h2 { font-size: 12pt; line-height: 1.28; margin-bottom: 4pt; }
        h3 { font-size: 10pt; margin-bottom: 3pt; }
        .masthead { width: 100%; border-collapse: collapse; border-bottom: 1pt solid #245cda; margin-bottom: 10pt; }
        .masthead td { padding: 0 0 6pt; }
        .brand { color: #245cda; font-weight: bold; font-size: 15pt; }
        .issued { color: #516580; font-size: 8.5pt; text-align: right; }
        .subtitle { color: #516580; margin-bottom: 7pt; white-space: pre-wrap; overflow-wrap: break-word; }
        .scope { font-size: 9pt; margin: 0 0 11pt; }
        .criteria { color: #516580; margin-top: 3pt; overflow-wrap: break-word; }
        .criteria span { margin-right: 10pt; }
        .group-heading {
            padding: 4pt 6pt; margin: 7pt 0 4pt; background: #f0f5fc;
            font-size: 10pt; font-weight: bold; white-space: pre-wrap;
        }
        .ticket { border-top: .65pt solid #dbe4ef; padding: 7pt 0 8pt; }
        .group-heading + .ticket { border-top: 0; }
        .ticket-number { color: #245cda; font-size: 9pt; }
        .ticket-title { display: block; white-space: pre-wrap; }
        .section { margin-top: 7pt; }
        .field { margin-bottom: 4pt; }
        .field-label { font-size: 8.5pt; font-weight: bold; }
        .field-value { white-space: pre-wrap; overflow-wrap: break-word; orphans: 2; widows: 2; }
        .field-list { padding-left: 13pt; }
        .field-list li { white-space: pre-wrap; overflow-wrap: break-word; }
        .absence { color: #516580; font-style: italic; }
        .pair { table-layout: fixed; border-collapse: collapse; width: 100%; margin-bottom: 4pt; }
        .pair td { width: 50%; vertical-align: top; padding: 0 8pt 0 0; }
        .pair td + td { padding: 0 0 0 8pt; }
        .pair .field { margin: 0; }
        .keep { page-break-inside: avoid; }
        .page-start { page-break-before: always; }
        .compact .ticket { padding-top: 4pt; padding-bottom: 4pt; }
        .compact .section { margin-top: 4pt; }
        .compact .field, .compact .pair { margin-bottom: 2pt; }
        .expanded .ticket { padding-top: 12pt; padding-bottom: 13pt; }
        .expanded .section { margin-top: 11pt; }
        .expanded .field, .expanded .pair { margin-bottom: 7pt; }
        .compact .pair .field, .expanded .pair .field { margin: 0; }
    </style>
</head>
<body class="{{ $document['layout']['spacing'] }}">
    <table class="masthead"><tr>
        <td class="brand">SupportFlow</td>
        <td class="issued">Emitido em {{ $issued }} · Horário de Brasília</td>
    </tr></table>
    <h1>{{ $document['title'] }}</h1>
    @if ($document['subtitle'] !== null)
        <p class="subtitle">{{ $document['subtitle'] }}</p>
    @endif
    <div class="scope">
        <p><strong>Período analisado</strong> {{ $document['period'] }}</p>
        @if ($document['criteria'] !== [])
            <p class="criteria">
                @foreach ($document['criteria'] as $label => $value)
                    <span><strong>{{ $label }}</strong> {{ $value }}</span>
                @endforeach
            </p>
        @endif
    </div>

    @php
        $sequence = 0;
        $mode = $document['layout']['pagination'];
        $groupLabel = match ($document['grouping']) {
            'system' => 'Sistema', 'category' => 'Categoria', 'brand' => 'Marca', 'model' => 'Marca e modelo', default => '',
        };
    @endphp
    @foreach ($document['groups'] as $groupIndex => $group)
        @if ($group['label'] !== null)
            <div @class(['group-heading', 'page-start' => $mode === 'individual' && $sequence > 0])>{{ $groupLabel }}: {{ $group['label'] }}</div>
        @endif
        @foreach ($group['tickets'] as $ticket)
            @php
                $entry = $layout->ticket($ticket, $document['layout']['columns']);
                $sequence++;
                $number = str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
                $newPage = $mode === 'individual' && $sequence > 1 && (! $loop->first || $group['label'] === null);
            @endphp
            <article data-ticket-number="{{ $number }}" data-group-index="{{ $groupIndex }}" @class(['ticket', 'page-start' => $newPage, 'keep' => $mode === 'automatic' && $entry['keep']])>
                <h2 data-ticket-start><span class="ticket-number">Atendimento {{ $number }}</span>@if ($entry['title'] !== null)<span class="ticket-title">{{ $entry['title'] }}</span>@endif</h2>
                @forelse ($entry['sections'] as $section)
                    <section class="section">
                        <h3>{{ $section['label'] }}</h3>
                        @if ($section['absence'] !== null)
                            <p class="absence">{{ $section['absence'] }}</p>
                        @endif
                        @foreach ($section['rows'] as $row)
                            @if (count($row) === 2)
                                <table class="pair"><tr>
                                    @foreach ($row as $field)
                                        <td>@include('reports.partials.analytical-ticket-field', ['field' => $field])</td>
                                    @endforeach
                                </tr></table>
                            @else
                                @include('reports.partials.analytical-ticket-field', ['field' => $row[0]])
                            @endif
                        @endforeach
                    </section>
                @empty
                    @if ($entry['title'] === null)
                        <p class="absence">Sem informações nos campos selecionados.</p>
                    @endif
                @endforelse
            </article>
        @endforeach
    @endforeach
</body>
</html>
