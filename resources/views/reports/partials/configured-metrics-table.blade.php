<table class="data">
    <thead><tr>
        @foreach($headers as $header)
            <th scope="col">
                @if($loop->first && isset($tableContext))
                    <span class="table-context">{{ $tableContext }}</span>
                @endif
                {{ $header }}
            </th>
        @endforeach
    </tr></thead>
    <tbody>
        @foreach($rows as $row)
            @php
                // Dompdf cannot split a table row. Keep long names in continuation rows.
                $cells = array_map(fn ($cell) => $layout::chunks((string) $cell), $row);
                $count = max(array_map(count(...), $cells));
            @endphp
            @for($part = 0; $part < $count; $part++)
                <tr @class(['continued' => $part > 0])>
                    @foreach($cells as $cell)
                        <td>{{ $cell[$part] ?? '' }}</td>
                    @endforeach
                </tr>
            @endfor
        @endforeach
    </tbody>
</table>
