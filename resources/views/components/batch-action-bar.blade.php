@props([
    'unit',
    'show' => 'selectedIds.length > 0',
])

<div x-show="{{ $show }}"
     x-cloak
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0 translate-y-4"
     x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100 translate-y-0"
     x-transition:leave-end="opacity-0 translate-y-4"
     {{ $attributes->merge(['class' => 'fixed bottom-6 left-1/2 z-40 flex w-max max-w-[calc(100vw-2rem)] -translate-x-1/2 flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-ink px-4 py-3 text-surface shadow-2xl sm:px-6 sm:py-3.5']) }}>
    <div class="flex items-center gap-2">
        <i data-lucide="circle-check" class="size-5 shrink-0 text-success" aria-hidden="true"></i>
        <span class="text-sm font-semibold"><span x-text="selectedIds.length"></span> {{ $unit }} dipilih</span>
        <button type="button"
                x-show="!isAllSelected()"
                @click="toggleSelectAll(true)"
                class="ml-1 cursor-pointer rounded-md border border-surface/20 bg-surface/10 px-2 py-0.5 text-[11px] font-semibold text-surface transition hover:bg-surface/20">
            Pilih Semua Tampil
        </button>
    </div>
    <div class="hidden h-4 w-px bg-surface/20 sm:block" aria-hidden="true"></div>

    {{ $slot }}

    <button type="button"
            @click="selectedIds = []"
            class="cursor-pointer text-xs font-medium text-surface/70 transition hover:text-white sm:ml-2">
        Batalkan Pilihan
    </button>
</div>
