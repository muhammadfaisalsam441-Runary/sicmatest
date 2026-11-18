@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
    'icon' => null,
    'size' => 'md',
    'animated' => false,
])

@php
    if ($animated) {
        $animatedSizeClass = match ($size) {
            'sm' => 'sicma-animated-btn--sm',
            'lg' => 'sicma-animated-btn--lg',
            default => '',
        };

        $animatedVariantClass = match ($variant) {
            'secondary' => 'sicma-animated-btn--secondary',
            'danger' => 'sicma-animated-btn--danger',
            default => 'sicma-animated-btn--primary',
        };

        $classes = "sicma-animated-btn {$animatedVariantClass} {$animatedSizeClass} focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50";
    } else {
        $sizeClasses = $variant === 'ghost'
            ? 'px-0 py-0 text-sm'
            : match ($size) {
                'sm' => 'px-3.5 py-2 text-xs',
                'lg' => 'px-6 py-3.5 text-base',
                default => 'px-5 py-3 text-sm',
            };

        $variantClasses = match ($variant) {
            'secondary' => 'sicma-btn sicma-btn-outline sicma-btn-secondary border border-border bg-surface text-primary hover:bg-primary-soft/60',
            'soft' => 'sicma-btn sicma-btn-soft bg-primary-soft text-primary hover:bg-primary-soft/80',
            'danger' => 'sicma-btn sicma-btn-solid sicma-btn-danger bg-danger text-white hover:bg-danger/95',
            'danger-outline' => 'sicma-btn sicma-btn-outline sicma-btn-danger-outline border border-danger bg-surface text-danger hover:bg-danger-soft',
            'ghost' => 'sicma-btn sicma-btn-ghost text-primary',
            default => 'sicma-btn sicma-btn-solid sicma-btn-primary bg-primary text-white hover:bg-primary/95',
        };

        $iconMotionClass = match ($icon) {
            'arrow-right', 'chevron-right', 'move-right' => 'sicma-btn-icon--next',
            'arrow-left', 'chevron-left', 'move-left' => 'sicma-btn-icon--prev',
            default => '',
        };

        $classes = "inline-flex cursor-pointer items-center justify-center gap-2 rounded-control font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50 {$sizeClasses} {$variantClasses}";
    }
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($animated)
            <svg viewBox="0 0 24 24" class="sicma-animated-btn__arr sicma-animated-btn__arr--2" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                <path d="M16.1716 10.9999L10.8076 5.63589L12.2218 4.22168L20 11.9999L12.2218 19.778L10.8076 18.3638L16.1716 12.9999H4V10.9999H16.1716Z" />
            </svg>
            <span class="sicma-animated-btn__text">{{ $slot }}</span>
            <span class="sicma-animated-btn__circle" aria-hidden="true"></span>
            <svg viewBox="0 0 24 24" class="sicma-animated-btn__arr sicma-animated-btn__arr--1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                <path d="M16.1716 10.9999L10.8076 5.63589L12.2218 4.22168L20 11.9999L12.2218 19.778L10.8076 18.3638L16.1716 12.9999H4V10.9999H16.1716Z" />
            </svg>
        @else
            @if ($icon)<i data-lucide="{{ $icon }}" aria-hidden="true" class="sicma-btn-icon {{ $iconMotionClass }} size-4 shrink-0"></i>@endif
            {{ $slot }}
        @endif
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($animated)
            <svg viewBox="0 0 24 24" class="sicma-animated-btn__arr sicma-animated-btn__arr--2" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                <path d="M16.1716 10.9999L10.8076 5.63589L12.2218 4.22168L20 11.9999L12.2218 19.778L10.8076 18.3638L16.1716 12.9999H4V10.9999H16.1716Z" />
            </svg>
            <span class="sicma-animated-btn__text">{{ $slot }}</span>
            <span class="sicma-animated-btn__circle" aria-hidden="true"></span>
            <svg viewBox="0 0 24 24" class="sicma-animated-btn__arr sicma-animated-btn__arr--1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                <path d="M16.1716 10.9999L10.8076 5.63589L12.2218 4.22168L20 11.9999L12.2218 19.778L10.8076 18.3638L16.1716 12.9999H4V10.9999H16.1716Z" />
            </svg>
        @else
            @if ($icon)<i data-lucide="{{ $icon }}" aria-hidden="true" class="sicma-btn-icon {{ $iconMotionClass }} size-4 shrink-0"></i>@endif
            {{ $slot }}
        @endif
    </button>
@endif
