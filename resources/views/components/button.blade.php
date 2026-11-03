@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
    'icon' => null,
])

@php
    $classes = 'inline-flex items-center justify-center gap-2 rounded-control px-5 py-3 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50 '.match ($variant) {
        'primary' => 'bg-primary text-white hover:bg-primary/90',
        'secondary' => 'border border-border bg-surface text-primary hover:bg-primary-soft/60',
        'soft' => 'bg-primary-soft text-primary hover:bg-primary-soft/70',
        'danger' => 'bg-danger text-white hover:bg-danger/90',
        'danger-outline' => 'border border-danger bg-surface text-danger hover:bg-danger-soft',
        'ghost' => 'text-primary hover:underline px-0 py-0',
    };
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<i data-lucide="{{ $icon }}" class="size-4"></i>@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<i data-lucide="{{ $icon }}" class="size-4"></i>@endif
        {{ $slot }}
    </button>
@endif
