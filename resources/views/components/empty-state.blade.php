@props(['title', 'message' => null, 'icon' => 'search-x'])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center gap-3 rounded-card border border-border bg-surface px-8 py-14 text-center']) }}>
    <span class="flex size-12 items-center justify-center rounded-full bg-primary-soft text-primary">
        <i data-lucide="{{ $icon }}" class="size-5"></i>
    </span>
    <h3 class="text-lg font-semibold text-ink">{{ $title }}</h3>
    @if ($message)
        <p class="max-w-md text-sm text-muted">{{ $message }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-2 flex items-center gap-3">{{ $slot }}</div>
    @endif
</div>
