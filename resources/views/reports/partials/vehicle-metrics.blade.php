<h2>Estatísticas veiculares</h2>
@php
    // Dompdf cannot break a single table row across pages. Prefer a word boundary
    // for continuation rows and cap unbroken strings so every row still fits.
    $splitCell = static function ($value) {
        if (!is_string($value)) {
            return [$value];
        }
        preg_match_all('/.{1,300}(?:\s+|$)|.{1,300}/us', $value, $matches);

        return $matches[0];
    };
@endphp
@if ($report->vehicleTables[0]['rows'][0][1] === 0)
    <p class="empty">Nenhum atendimento com veículo para os filtros selecionados.</p>
@endif
@foreach ($report->vehicleTables as $table)
    <h2>{{ $table['title'] }}</h2>
    @if ($table['note'])
        <p class="muted section-note">{{ $table['note'] }}</p>
    @endif
    <table @class(['vehicle-report', 'vehicle-report-wide' => count($table['headers']) > 5])>
        <thead><tr>
            @foreach ($table['headers'] as $column => $header)
                <th style="width: {{ 100 * $table['widths'][$column] / array_sum($table['widths']) }}%">{{ $header }}</th>
            @endforeach
        </tr></thead>
        <tbody>
        @forelse ($table['rows'] as $rowIndex => $row)
            @php
                $cells = array_map($splitCell, $row);
                $parts = max(1, ...array_map(count(...), $cells));
            @endphp
            @for ($part = 0; $part < $parts; $part++)
                <tr>
                    @foreach ($cells as $column => $chunks)
                        @php
                            $value = $chunks[$part] ?? '';
                            $numeric = is_int($value) || is_float($value);
                            $percent = in_array($column + 1, $table['percentages'], true)
                                || in_array($rowIndex, $table['percentageRows'], true);
                        @endphp
                        <td @class(['numeric' => $numeric])>
                            {{ $numeric ? ($percent ? number_format($value * 100, 2, ',', '.').'%' : number_format($value, 0, ',', '.')) : $value }}
                            @if ($numeric && $percent && $table['sheet'] === 'Linhas veiculares')
                                <div class="vehicle-share"><div style="width: {{ $value * 100 }}%"></div></div>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endfor
        @empty
            <tr><td colspan="{{ count($table['headers']) }}">Nenhuma ocorrência para os filtros selecionados.</td></tr>
        @endforelse
        </tbody>
    </table>
@endforeach
