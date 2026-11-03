@props(['name', 'title' => null, 'width' => 'max-w-lg', 'show' => false])

{{-- Buka dengan $dispatch('open-modal', 'nama'), tutup dengan $dispatch('close-modal', 'nama'). --}}
<div x-data="{ open: @js($show) }"
     x-on:open-modal.window="if ($event.detail === @js($name)) open = true"
     x-on:close-modal.window="if ($event.detail === @js($name)) open = false"
     x-on:keydown.escape.window="open = false"
     x-show="open" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center p-8"
     role="dialog" aria-modal="true" @if ($title) aria-label="{{ $title }}" @endif>
    <div class="absolute inset-0 bg-ink/80" x-on:click="open = false"></div>
    <div x-show="open" x-transition class="relative w-full {{ $width }} rounded-2xl bg-surface p-8 shadow-xl">
        @if ($title)
            <div class="mb-5 flex items-start justify-between gap-4">
                <h2 class="text-xl font-semibold text-ink">{{ $title }}</h2>
                <button type="button" x-on:click="open = false" class="text-muted hover:text-ink" aria-label="Tutup">
                    <i data-lucide="x" class="size-5"></i>
                </button>
            </div>
        @endif
        {{ $slot }}
    </div>
</div>
