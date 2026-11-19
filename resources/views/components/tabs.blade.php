@props(['items' => [], 'size' => 'md'])

@php
    $itemSizeClasses = $size === 'sm' ? 'min-h-10 sm:min-h-0 px-3.5 py-1.5 text-xs' : 'min-h-11 py-2.5 px-4 text-sm';
@endphp

{{-- $items: list<array{label: string, href: string, active: bool, count?: int|null}> --}}
<nav {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2']) }} aria-label="Tab">
    @foreach ($items as $item)
        <a href="{{ $item['href'] }}"
           @if ($item['active']) aria-current="page" @endif
           @class([
               "inline-flex items-center gap-2 rounded-control font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {$itemSizeClasses}",
               'bg-primary text-white' => $item['active'],
               'bg-primary-soft text-primary hover:bg-primary-soft/70' => ! $item['active'],
           ])>{{ $item['label'] }}@isset($item['count']) {{ $item['count'] }}@endisset</a>
    @endforeach
</nav>
