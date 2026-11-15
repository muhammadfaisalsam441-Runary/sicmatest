<button type="button"
        x-data="tableDensityToggle()"
        @click="toggle()"
        :aria-pressed="(density === 'compact').toString()"
        :aria-label="density === 'compact' ? 'Ubah tampilan tabel ke normal' : 'Ubah tampilan tabel ke rapat'"
        :title="density === 'compact' ? 'Tampilan rapat aktif (klik untuk normal)' : 'Tampilan normal aktif (klik untuk rapat)'"
        {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-muted hover:bg-page hover:text-ink transition cursor-pointer']) }}>
    <i x-show="density !== 'compact'" data-lucide="rows-3" class="size-3.5 shrink-0" aria-hidden="true"></i>
    <i x-show="density === 'compact'" x-cloak data-lucide="rows-4" class="size-3.5 shrink-0 text-primary" aria-hidden="true"></i>
    <span x-text="density === 'compact' ? 'Rapat' : 'Normal'">Normal</span>
</button>
