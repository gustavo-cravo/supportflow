<h2>{{ $title }}</h2>
<table @class(['compact' => $compact])><thead><tr>
    <th>{{ $label }}</th>
    <th class="number">{{ $compact ? 'Qtd.' : 'Ocorrências' }}</th>
    <th class="number">{{ $compact ? 'Part.' : 'Participação' }}</th>
</tr></thead><tbody>
    @forelse ($rows as $row)
        <tr>
            <td>{{ $row['name'] }}</td>
            <td class="number">{{ number_format($row['total'], 0, ',', '.') }}</td>
            <td class="number">{{ number_format($row['percentage'], 2, ',', '.') }}%</td>
        </tr>
    @empty
        <tr><td colspan="3">Sem ocorrências no período.</td></tr>
    @endforelse
</tbody></table>
