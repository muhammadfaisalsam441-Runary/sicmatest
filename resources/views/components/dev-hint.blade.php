{{-- Petunjuk khusus mode pengembangan (APP_ENV=local); tidak pernah tampil di produksi. --}}
@if (app()->isLocal())
    <div {{ $attributes->merge(['class' => 'rounded-card border border-dashed border-primary/40 bg-surface px-4 py-3 text-xs text-muted']) }}>
        <span class="font-semibold text-primary">Mode pengembangan</span> • {{ $slot }}
    </div>
@endif
