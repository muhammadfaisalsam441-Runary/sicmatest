@props(['label', 'value', 'hint' => null, 'tone' => 'ink'])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-1 rounded-card border border-border bg-surface px-6 py-5']) }}>
    <span class="text-xs text-muted">{{ $label }}</span>
    <span @class([
        'text-3xl font-semibold',
        'text-ink' => $tone === 'ink',
        'text-success' => $tone === 'success',
        'text-danger' => $tone === 'danger',
        'text-primary' => $tone === 'primary',
    ])>{{ $value }}</span>
    @if ($hint)
        <span class="text-sm text-muted">{{ $hint }}</span>
    @endif
</div>
