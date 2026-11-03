@props(['message' => 'Memuat data ruangan…'])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center gap-3 rounded-card border border-border bg-surface px-8 py-14 text-center']) }} role="status" aria-live="polite">
    <span class="size-8 animate-spin rounded-full border-4 border-primary-soft border-t-primary" aria-hidden="true"></span>
    <p class="text-sm text-muted">{{ $message }}</p>
</div>
