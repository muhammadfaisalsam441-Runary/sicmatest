@props(['title', 'variant' => 'primary'])

<div {{ $attributes->class([
    'flex flex-col gap-2 rounded-card px-4 py-4 text-sm',
    'bg-primary-soft' => $variant === 'primary',
    'bg-danger-soft' => $variant === 'danger',
    'bg-success-soft' => $variant === 'success',
]) }}>
    <p @class(['font-semibold', 'text-ink' => $variant === 'primary', 'text-danger' => $variant === 'danger', 'text-success' => $variant === 'success'])>{{ $title }}</p>
    <div class="flex flex-col gap-1 text-muted">{{ $slot }}</div>
</div>
