<div class="field">
    <p class="field-label">{{ $field['label'] }}</p>
    @if (is_array($field['value']))
        <ul class="field-list">
            @foreach ($field['value'] as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
    @elseif ($field['value'] !== null)
        <p class="field-value">{{ $field['value'] }}</p>
    @else
        <p class="field-value absence">{{ $field['absence'] }}</p>
    @endif
</div>
