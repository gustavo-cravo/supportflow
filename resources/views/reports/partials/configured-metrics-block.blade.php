@php
    $heading = 'h'.min($depth, 5);
    $labels = isset($block['label']) ? $layout::chunks($block['label']) : [];
@endphp
@switch($block['type'])
    @case('group')
        <div @class(['group', 'keep' => $block['keep'] ?? false])>
            @if($labels !== [])
                <{{ $heading }}>{{ $labels[0] }}</{{ $heading }}>
                @foreach(array_slice($labels, 1) as $part)
                    <p class="label-continuation">{{ $part }}</p>
                @endforeach
            @endif
            @foreach($block['children'] as $child)
                @include('reports.partials.configured-metrics-block', ['block' => $child, 'depth' => $labels === [] ? $depth : $depth + 1])
            @endforeach
        </div>
        @break
    @case('indicator')
        @foreach(array_chunk($block['rows'], 3) as $cards)
            <table class="cards keep"><tr>
                @foreach($cards as $card)
                    <td><strong>{{ $card['value'] }}</strong><small>{{ $card['label'] }}</small></td>
                @endforeach
            </tr></table>
        @endforeach
        @break
    @case('table')
        @if($labels !== []) <{{ $heading }}>{{ $block['label'] }}</{{ $heading }}> @endif
        @include('reports.partials.configured-metrics-table', ['headers' => $block['headers'], 'rows' => $block['rows'], 'tableContext' => $block['table_context'] ?? null])
        @break
    @case('series')
        <{{ $heading }}>{{ $block['label'] }}</{{ $heading }}>
        @include('reports.partials.configured-metrics-series', ['series' => $block])
        @break
    @case('description')
        <div class="description">
            <{{ $heading }}>{{ $block['label'] }} @if($block['text'] !== '') · {{ $block['text'] }} @endif</{{ $heading }}>
            @if($block['items'] !== [])
                <ul>
                    @foreach($block['items'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
        @break
    @default
        @if($labels !== []) <{{ $heading }}>{{ $block['label'] }}</{{ $heading }}> @endif
        @if($block['text'] !== '')
            <p @class(['empty' => $block['type'] === 'empty'])>{{ $block['text'] }}</p>
        @endif
@endswitch
