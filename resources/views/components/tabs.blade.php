@props(['items' => [], 'size' => 'md'])

@php
    $navGap = $size === 'sm' ? 'gap-2' : 'gap-3';
    $itemSizeClasses = $size === 'sm' ? 'px-3.5 py-1.5 text-xs' : 'px-5 py-2.5 text-sm';
@endphp

{{-- $items: list<array{label: string, href: string, active: bool, count?: int|null}> --}}
<nav {{ $attributes->merge(['class' => "flex flex-wrap items-center {$navGap}"]) }} aria-label="Tab">
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
