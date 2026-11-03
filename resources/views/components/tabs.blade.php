@props(['items' => []])

{{-- $items: list<array{label: string, href: string, active: bool, count?: int|null}> --}}
<nav {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-3']) }} aria-label="Tab">
    @foreach ($items as $item)
        <a href="{{ $item['href'] }}" @if ($item['active']) aria-current="page" @endif
           @class([
               'rounded-control px-6 py-3 text-sm font-medium',
               'bg-primary text-white' => $item['active'],
               'bg-primary-soft text-primary hover:bg-primary-soft/70' => ! $item['active'],
           ])>{{ $item['label'] }}@isset($item['count']) {{ $item['count'] }}@endisset</a>
    @endforeach
</nav>
