@props(['title', 'subtitle' => null])

@php
    $hasSubtitle = $subtitle instanceof \Illuminate\View\ComponentSlot
        ? $subtitle->isNotEmpty()
        : filled($subtitle);
    $hasActions = isset($actions) && (
        $actions instanceof \Illuminate\View\ComponentSlot
            ? $actions->isNotEmpty()
            : filled($actions)
    );
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4']) }}>
    <div class="flex flex-col gap-1">
        <h1 class="text-xl font-bold text-ink sm:text-2xl">{{ $title }}</h1>
        @if ($hasSubtitle)
            <p class="text-xs text-muted sm:text-sm">{{ $subtitle }}</p>
        @endif
    </div>
    @if ($hasActions)
        <div class="flex shrink-0 flex-wrap items-center gap-2.5">{{ $actions }}</div>
    @endif
</div>
