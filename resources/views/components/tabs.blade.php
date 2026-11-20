@props(['items' => [], 'size' => 'md', 'scrollable' => false])

@php
    $itemSizeClasses = $size === 'sm' ? 'min-h-10 sm:min-h-0 px-3.5 py-1.5 text-xs' : 'min-h-11 py-2.5 px-4 text-sm';
    $navClasses = $scrollable
        ? 'flex flex-nowrap items-center gap-2 overflow-x-auto pb-0.5 custom-scrollbar sm:flex-wrap sm:overflow-visible sm:pb-0'
        : 'flex flex-wrap items-center gap-2';
@endphp

{{-- $items: list<array{label: string, href: string, active: bool, count?: int|null}> --}}
<nav {{ $attributes->merge(['class' => $navClasses]) }} aria-label="Tab">
    @foreach ($items as $item)
        <a href="{{ $item['href'] }}"
           @if ($item['active']) aria-current="page" @endif
           @class([
               "inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-control font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {$itemSizeClasses}",
               'bg-primary text-white' => $item['active'],
               'bg-primary-soft text-primary hover:bg-primary-soft/70' => ! $item['active'],
           ])>{{ $item['label'] }}@isset($item['count']) {{ $item['count'] }}@endisset</a>
    @endforeach
</nav>
