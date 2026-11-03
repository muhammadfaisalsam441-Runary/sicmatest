@props(['title', 'subtitle' => null])

<div {{ $attributes->merge(['class' => 'flex items-start justify-between gap-6']) }}>
    <div class="flex flex-col gap-2">
        <h1 class="text-3xl font-semibold text-ink">{{ $title }}</h1>
        @if ($subtitle)
            <p class="text-sm text-muted">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex shrink-0 items-center gap-3">{{ $actions }}</div>
    @endisset
</div>
