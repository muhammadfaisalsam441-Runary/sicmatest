@props(['items' => []])

{{-- $items: list<array{0: string, 1?: string}> — [label, url] --}}
<nav {{ $attributes->merge(['class' => 'flex items-center gap-2 text-sm text-muted']) }} aria-label="Breadcrumb">
    @foreach ($items as $index => $item)
        @if ($index > 0)<span aria-hidden="true">/</span>@endif
        @if (isset($item[1]) && ! $loop->last)
            <a href="{{ $item[1] }}" class="hover:text-primary">{{ $item[0] }}</a>
        @else
            <span @if ($loop->last) aria-current="page" class="text-ink" @endif>{{ $item[0] }}</span>
        @endif
    @endforeach
</nav>
