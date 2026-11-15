@props([
    'maxHeight' => 'max-h-[70vh]',
    'title' => null,
    'headerActions' => null,
])

@php
    $hasTitle = $title instanceof \Illuminate\View\ComponentSlot
        ? $title->isNotEmpty()
        : ! empty($title);
    $hasHeaderActions = $headerActions instanceof \Illuminate\View\ComponentSlot
        ? $headerActions->isNotEmpty()
        : ! empty($headerActions);
@endphp

<div
    x-data="tableScrollBox"
    @keydown.escape.window="closeFullscreen()"
    {{ $attributes->except(['class']) }}
>
    <div
        x-show="fullscreen"
        x-cloak
        x-transition.opacity.duration.150ms
        @click="closeFullscreen()"
        class="fixed inset-0 z-[35] bg-ink/40 backdrop-blur-[2px]"
        aria-hidden="true"
    ></div>

    <div
        x-ref="box"
        @mouseleave="resetAutoFit()"
        :data-table-fullscreen="fullscreen ? 'true' : 'false'"
        :data-editing="fullscreen ? 'true' : 'false'"
        :class="{
            'fixed inset-3 sm:inset-5 z-40 flex flex-col bg-surface shadow-2xl border-primary/30': fullscreen,
            'ring-2 ring-primary/25 shadow-sm border-primary/30': !fullscreen && activeScroll,
        }"
        {{ $attributes->only('class')->merge(['class' => 'overflow-hidden rounded-lg border border-border bg-surface transition-shadow duration-200']) }}
    >
        <div class="flex min-h-[34px] shrink-0 flex-wrap items-center justify-between gap-2 border-b border-border bg-page/80 px-3 py-1">
            <div class="flex min-w-0 items-center gap-2 text-xs text-muted">
                @if ($hasTitle)
                    @if ($title instanceof \Illuminate\View\ComponentSlot)
                        <div class="flex min-w-0 flex-wrap items-center gap-2 font-semibold text-ink">{{ $title }}</div>
                    @else
                        <span class="truncate font-semibold text-ink">{{ $title }}</span>
                    @endif
                @endif
            </div>

            <div class="flex shrink-0 items-center gap-2">
                @if ($hasHeaderActions)
                    {{ $headerActions }}
                @endif
                <button
                    type="button"
                    @click="toggleFullscreen()"
                    :title="fullscreen ? 'Perkecil tampilan tabel (Esc)' : 'Perluas tabel ke layar penuh'"
                    :aria-expanded="fullscreen.toString()"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-control border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-muted transition hover:bg-page hover:text-ink"
                >
                    <i x-show="!fullscreen" data-lucide="maximize-2" class="size-3.5" aria-hidden="true"></i>
                    <i x-show="fullscreen" x-cloak data-lucide="minimize-2" class="size-3.5 text-primary" aria-hidden="true"></i>
                    <span x-text="fullscreen ? 'Perkecil (Esc)' : 'Perluas'">Perluas</span>
                </button>
            </div>
        </div>

        <div
            x-ref="scroller"
            @scroll.passive="onTableScroll($event)"
            :class="fullscreen ? 'flex-1 min-h-0 max-h-none' : '{{ $maxHeight }}'"
            class="overflow-x-auto overflow-y-auto custom-scrollbar"
        >
            {{ $slot }}
        </div>
    </div>
</div>
