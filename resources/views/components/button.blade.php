@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
    'icon' => null,
    'size' => 'md',
])

@php
    $sizeClasses = $variant === 'ghost'
        ? 'px-0 py-0 text-sm'
        : match ($size) {
            'sm' => 'px-3.5 py-2 text-xs',
            'lg' => 'px-6 py-3.5 text-base',
            default => 'px-5 py-3 text-sm',
        };

    $variantClasses = match ($variant) {
        'secondary' => 'border border-border bg-surface text-primary hover:bg-primary-soft/60',
        'soft' => 'bg-primary-soft text-primary hover:bg-primary-soft/70',
        'danger' => 'bg-danger text-white hover:bg-danger/90',
        'danger-outline' => 'border border-danger bg-surface text-danger hover:bg-danger-soft',
        'ghost' => 'text-primary hover:underline',
        default => 'bg-primary text-white hover:bg-primary/90',
    };

    $classes = "inline-flex cursor-pointer items-center justify-center gap-2 rounded-control font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50 {$sizeClasses} {$variantClasses}";
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<i data-lucide="{{ $icon }}" aria-hidden="true" class="size-4"></i>@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<i data-lucide="{{ $icon }}" aria-hidden="true" class="size-4"></i>@endif
        {{ $slot }}
    </button>
@endif
