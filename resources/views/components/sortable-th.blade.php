@props([
    'column',
    'sort' => null,
    'dir' => 'asc',
    'align' => 'left',
])

@php
    $isActive = $sort === $column;
    $nextDir = ($isActive && $dir === 'asc') ? 'desc' : 'asc';
    $url = request()->fullUrlWithQuery(['sort' => $column, 'dir' => $nextDir, 'page' => 1]);
    $alignClass = match ($align) {
        'center' => 'justify-center text-center',
        'right' => 'justify-end text-right',
        default => 'justify-start text-left',
    };
@endphp

<th scope="col" {{ $attributes }}>
    <a href="{{ $url }}"
       class="group inline-flex w-full items-center gap-1 rounded px-1 py-0.5 transition hover:text-primary {{ $alignClass }}"
       title="Urutkan berdasarkan kolom ini">
        <span @class(['text-primary' => $isActive])>{{ $slot }}</span>
        @if ($isActive && $dir === 'asc')
            <i data-lucide="arrow-up" class="size-3.5 shrink-0 text-primary" aria-hidden="true"></i>
        @elseif ($isActive && $dir === 'desc')
            <i data-lucide="arrow-down" class="size-3.5 shrink-0 text-primary" aria-hidden="true"></i>
        @else
            <i data-lucide="arrow-up-down" class="size-3 shrink-0 opacity-40 transition group-hover:opacity-100" aria-hidden="true"></i>
        @endif
    </a>
</th>
