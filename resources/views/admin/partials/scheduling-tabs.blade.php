@use('App\Support\Nav')
@props([
    'version' => null,
    'isDraft' => false,
    'reviewStatus' => null,
])

<div class="flex flex-col gap-3">
    <x-tabs :items="[
        [
            'label' => 'Susun Otomatis',
            'href' => route('admin.scheduler.create'),
            'active' => Nav::active('admin.scheduler.create', 'admin.scheduler.show'),
        ],
        [
            'label' => 'Daftar & Pemeriksaan Draf',
            'href' => route('admin.drafts.inspections'),
            'active' => Nav::active('admin.drafts.inspections', 'admin.drafts.inspect', 'admin.drafts.show'),
        ],
        [
            'label' => 'Impor Excel / CSV',
            'href' => route('admin.imports.create'),
            'active' => Nav::active('admin.imports.*'),
        ],
        [
            'label' => 'Riwayat Proses',
            'href' => route('admin.scheduler.history'),
            'active' => Nav::active('admin.scheduler.history'),
        ],
    ]" />

    @if ($version && $isDraft)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-card border border-primary/20 bg-primary-soft/40 px-4 py-3 text-sm">
            <div class="flex items-center gap-2">
                <span class="rounded-md bg-primary px-2.5 py-1 text-xs font-semibold text-white">Draf v{{ $version->version_no }}</span>
                <span class="font-medium text-ink">Mode Kerja Draf:</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.drafts.show', $version) }}"
                   @class([
                       'inline-flex items-center gap-1.5 rounded-control px-3.5 py-1.5 text-xs font-semibold transition',
                       'bg-primary text-white shadow-2xs' => Nav::active('admin.drafts.show'),
                       'border border-border bg-surface text-ink hover:bg-primary-soft' => ! Nav::active('admin.drafts.show'),
                   ])>
                    <i data-lucide="calendar-cog" class="size-3.5"></i>
                    <span>1. Edit & Tinjau Sesi</span>
                </a>
                <a href="{{ route('admin.drafts.inspect', $version) }}"
                   @class([
                       'inline-flex items-center gap-1.5 rounded-control px-3.5 py-1.5 text-xs font-semibold transition',
                       'bg-primary text-white shadow-2xs' => Nav::active('admin.drafts.inspect'),
                       'border border-border bg-surface text-ink hover:bg-primary-soft' => ! Nav::active('admin.drafts.inspect'),
                   ])>
                    <i data-lucide="list-checks" class="size-3.5"></i>
                    <span>2. Pemeriksaan Bentrok & Kesiapan</span>
                </a>
                @if ($reviewStatus['ready'] ?? false)
                    <a href="{{ route('admin.schedules.index', ['tab' => 'penetapan', 'draf' => $version->id]) }}"
                       class="inline-flex items-center gap-1.5 rounded-control bg-success px-3.5 py-1.5 text-xs font-semibold text-white shadow-2xs transition hover:opacity-90">
                        <i data-lucide="check-circle-2" class="size-3.5"></i>
                        <span>3. Lanjut ke Penetapan</span>
                    </a>
                @endif
            </div>
        </div>
    @endif
</div>
