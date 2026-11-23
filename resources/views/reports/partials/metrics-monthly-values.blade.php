<table class="monthly-values"><thead><tr>
    @for ($column = 0; $column < $columns; $column++)
        <th>Mês</th><th class="number">Atendimentos</th>
    @endfor
</tr></thead><tbody>
    @foreach (array_chunk($rows, $columns) as $months)
        <tr>
            @foreach ($months as $row)
                <td>{{ substr($row['month'], 5, 2) }}/{{ substr($row['month'], 0, 4) }}</td>
                <td class="number">{{ number_format($row['total'], 0, ',', '.') }}</td>
            @endforeach
            @for ($column = count($months); $column < $columns; $column++)<td></td><td></td>@endfor
        </tr>
    @endforeach
</tbody></table>
