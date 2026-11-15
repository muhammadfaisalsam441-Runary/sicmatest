@props(['label', 'value', 'hint' => null, 'tone' => 'ink', 'compact' => false])

@if ($compact)
    <div {{ $attributes->merge(['class' => 'flex items-center justify-between gap-3 rounded-card border border-border bg-surface px-3.5 py-2']) }}>
        <div class="flex min-w-0 flex-col">
            <span class="truncate text-xs font-medium text-muted">{{ $label }}</span>
            @if ($hint)
                <span class="truncate text-[11px] text-muted">{{ $hint }}</span>
            @endif
        </div>
        <span @class([
            'shrink-0 text-lg font-bold leading-tight tabular-nums',
            'text-ink' => $tone === 'ink',
            'text-success' => $tone === 'success',
            'text-danger' => $tone === 'danger',
            'text-primary' => $tone === 'primary',
            'text-warning-ink' => $tone === 'warning',
        ])>{{ $value }}</span>
    </div>
@else
    <div {{ $attributes->merge(['class' => 'flex flex-col gap-1 rounded-card border border-border bg-surface px-6 py-5']) }}>
        <span class="text-xs text-muted">{{ $label }}</span>
        <span @class([
            'text-3xl font-semibold',
            'text-ink' => $tone === 'ink',
            'text-success' => $tone === 'success',
            'text-danger' => $tone === 'danger',
            'text-primary' => $tone === 'primary',
            'text-warning-ink' => $tone === 'warning',
        ])>{{ $value }}</span>
        @if ($hint)
            <span class="text-sm text-muted">{{ $hint }}</span>
        @endif
    </div>
@endif
