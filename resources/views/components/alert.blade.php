@props(['variant' => 'info', 'title' => null])

@php
    $iconName = match ($variant) {
        'success' => 'circle-check',
        'danger' => 'circle-alert',
        'warning' => 'triangle-alert',
        default => 'info',
    };
@endphp

<div {{ $attributes->class([
    'flex items-start gap-3 rounded-card border px-5 py-4 text-sm',
    'border-success bg-success-soft text-success' => $variant === 'success',
    'border-danger bg-danger-soft text-danger' => $variant === 'danger',
    'border-warning bg-warning-soft text-warning-ink' => $variant === 'warning',
    'border-primary bg-primary-soft text-primary' => ! in_array($variant, ['success', 'danger', 'warning'], true),
]) }} role="{{ $variant === 'danger' ? 'alert' : 'status' }}">
    <i data-lucide="{{ $iconName }}" aria-hidden="true" class="mt-0.5 size-5 shrink-0"></i>
    <div class="flex flex-col gap-1">
        @if ($title)<p class="font-semibold">{{ $title }}</p>@endif
        <div>{{ $slot }}</div>
    </div>
</div>
