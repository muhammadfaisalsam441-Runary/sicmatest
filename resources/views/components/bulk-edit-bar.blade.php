@props([
    'form',
    'label',
])

<div x-show="editMode"
     x-cloak
     {{ $attributes->merge(['class' => 'flex flex-wrap items-center justify-between gap-3 rounded-xl border border-primary/30 bg-primary-soft/30 p-3.5 animate-in fade-in duration-200']) }}>
    <div class="flex flex-wrap items-center gap-2 text-xs">
        <span class="flex items-center gap-1.5 font-bold text-primary">
            <i data-lucide="pencil" class="size-4" aria-hidden="true"></i> Mode Edit Massal Aktif
        </span>
        <span class="text-muted">| {{ $label }}</span>
        <div class="ml-1 flex flex-wrap items-center gap-1.5">
            {{ $slot }}
        </div>
    </div>
    <div class="flex items-center gap-2">
        <button type="button"
                @click="cancelEditMode()"
                class="cursor-pointer rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-muted transition hover:text-ink">
            Batal
        </button>
        <button type="submit"
                form="{{ $form }}"
                class="inline-flex cursor-pointer items-center gap-1.5 rounded-control bg-primary px-4 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-primary/90">
            <i data-lucide="check" class="size-4" aria-hidden="true"></i>
            Simpan Semua Perubahan
        </button>
    </div>
</div>
