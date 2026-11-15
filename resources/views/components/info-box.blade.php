@props(['title', 'variant' => 'primary'])

<div {{ $attributes->class([
    'flex flex-col gap-2 rounded-card px-4 py-4 text-sm',
    'bg-primary-soft' => in_array($variant, ['primary', 'info'], true),
    'bg-danger-soft' => $variant === 'danger',
    'bg-success-soft' => $variant === 'success',
    'bg-warning-soft' => $variant === 'warning',
]) }}>
    <p @class([
        'font-semibold',
        'text-ink' => in_array($variant, ['primary', 'info'], true),
        'text-danger' => $variant === 'danger',
        'text-success' => $variant === 'success',
        'text-warning-ink' => $variant === 'warning',
    ])>{{ $title }}</p>
    <div class="flex flex-col gap-1 text-muted">{{ $slot }}</div>
</div>
