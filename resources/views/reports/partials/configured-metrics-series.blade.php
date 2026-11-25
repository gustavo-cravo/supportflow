@php
    $rows = $series['rows'];
    $unit = $series['unit'];
    $format = $series['format'];
    $values = array_column($rows, 'value');
    $extra = isset($rows[0]['vehicles']);
    $valueLabel = fn ($value) => $layout::formatValue($value, $unit);
    $maximum = max([1, ...$values]);
    $headers = ['Grupo', $unit, ...($extra ? ['Veículos diferentes'] : [])];
    $tableRow = fn ($row) => [$row['name'], $valueLabel($row['value']), ...($extra ? [$layout::formatValue($row['vehicles'], '')] : [])];
@endphp

@if($series['note'])
    <p class="note">{{ $series['note'] }}</p>
@endif
@if($rows === [])
    <p class="empty">{{ $series['empty'] }}</p>
@elseif($format === 'table')
    @if($extra && array_sum($values) === 0)<p class="empty">{{ $series['empty'] }}</p>@endif
    @include('reports.partials.configured-metrics-table', ['headers' => $headers, 'rows' => array_map($tableRow, $rows), 'tableContext' => $series['table_context'] ?? null])
@elseif($format === 'doughnut')
    @php($total = array_sum($values))
    <div class="chart">
        @if($total === 0)
            <p class="empty">{{ $series['empty'] }} Percentual não aplicável.</p>
        @endif
        <table class="ring"><tr>
            @if($total > 0)
                <td class="graphic"><img src="{{ $layout->doughnut($values) }}" alt="Distribuição proporcional por linha veicular"></td>
            @endif
            <td>
                @foreach($rows as $index => $row)
                    <p><strong style="color: {{ $layout->doughnutColor($index) }}">{{ $index + 1 }} · {{ $row['name'] }}</strong><br>
                        {{ $valueLabel($row['value']) }} atendimentos ·
                        {{ $layout::formatValue($row['vehicles'], '') }} veículos diferentes
                        @if($total > 0) · {{ $layout::formatValue($row['value'] * 100 / $total, '%') }} dos atendimentos com veículo @endif
                    </p>
                @endforeach
            </td>
        </tr></table>
    </div>
@elseif(array_sum($values) === 0 && $extra)
    <p class="empty">{{ $series['empty'] }}</p>
    @foreach($rows as $row)
        <p>{{ $row['name'] }} · {{ $valueLabel($row['value']) }} atendimentos · {{ $layout::formatValue($row['vehicles'], '') }} veículos diferentes</p>
    @endforeach
@elseif($format === 'horizontal_bar' && max(array_map(fn ($row) => mb_strlen($row['name']), $rows)) <= 180)
    @foreach(array_chunk($rows, 6) as $chunk)
        <div class="chart">
            <p class="note">Barras horizontais · {{ $unit }} · Escala de 0 a {{ $valueLabel($maximum) }}</p>
            @foreach($chunk as $row)
                <div class="bar-row">
                    <strong>{{ $row['name'] }}</strong> · {{ $valueLabel($row['value']) }}
                    @if($extra) <span class="note"> · {{ $layout::formatValue($row['vehicles'], '') }} veículos diferentes</span> @endif
                    <div class="bar-track"><div class="bar-fill" style="width: {{ 100 * $row['value'] / $maximum }}%"></div></div>
                </div>
            @endforeach
        </div>
    @endforeach
@else
    @foreach(array_chunk($rows, 6) as $chunkIndex => $chunk)
        @php($offset = $chunkIndex * 6)
        <div @class(['keep' => max(array_map(fn ($row) => mb_strlen($row['name']), $chunk)) <= 125])>
            <div class="chart">
                <p class="note">{{ $format === 'vertical_bar' ? 'Barras verticais' : 'Barras horizontais' }} · {{ $unit }} · Escala de 0 a {{ $valueLabel($maximum) }}</p>
                <img class="plot" src="{{ $layout->bars(array_column($chunk, 'value'), $maximum, $format === 'horizontal_bar', $offset) }}" alt="Gráfico de barras com chaves numéricas para a legenda">
            </div>
            @include('reports.partials.configured-metrics-table', [
                'headers' => ['Chave / '.$headers[0], ...array_slice($headers, 1)],
                'rows' => array_map(fn ($row, $index) => [$offset + $index + 1 .' · '.$row['name'], ...array_slice($tableRow($row), 1)], $chunk, array_keys($chunk)),
            ])
        </div>
    @endforeach
@endif
