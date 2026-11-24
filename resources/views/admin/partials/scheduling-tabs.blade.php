@use('App\Support\Nav')
@props([
    'version' => null,
    'isDraft' => false,
    'reviewStatus' => null,
])

@php
    $showBackToWorkspace = Nav::active(
        'admin.drafts.show',
        'admin.drafts.inspect',
        'admin.drafts.inspections',
        'admin.imports.*',
        'admin.scheduler.show',
        'admin.scheduler.history'
    );
@endphp

<div class="flex flex-col gap-2">
    @if ($showBackToWorkspace)
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.scheduler.create') }}"
               class="inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-ink shadow-2xs transition hover:border-primary/40 hover:bg-primary-soft/40 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <i data-lucide="arrow-left" class="size-3.5" aria-hidden="true"></i>
                <span>Kembali ke Penyusunan &amp; Draf</span>
            </a>
        </div>
    @endif

    @if ($version && $isDraft)
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-card border border-primary/20 bg-primary-soft/40 px-3.5 py-2 text-xs">
            <div class="flex items-center gap-2">
                <span class="rounded-md bg-primary px-2 py-0.5 text-xs font-semibold text-white">Draf v{{ $version->version_no }}</span>
                <span class="font-medium text-ink">Mode Kerja Draf:</span>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
                <a href="{{ route('admin.drafts.show', $version) }}"
                   @if (Nav::active('admin.drafts.show')) aria-current="page" @endif
                   @class([
                       'inline-flex items-center gap-1.5 rounded-control px-3 py-1 text-xs font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                       'bg-primary text-white shadow-2xs' => Nav::active('admin.drafts.show'),
                       'border border-border bg-surface text-ink hover:bg-primary-soft' => ! Nav::active('admin.drafts.show'),
                   ])>
                    <i data-lucide="pencil" class="size-3.5" aria-hidden="true"></i>
                    <span>1. Edit & Tinjau Sesi</span>
                </a>
                <a href="{{ route('admin.drafts.inspect', $version) }}"
                   @if (Nav::active('admin.drafts.inspect')) aria-current="page" @endif
                   @class([
                       'inline-flex items-center gap-1.5 rounded-control px-3 py-1 text-xs font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                       'bg-primary text-white shadow-2xs' => Nav::active('admin.drafts.inspect'),
                       'border border-border bg-surface text-ink hover:bg-primary-soft' => ! Nav::active('admin.drafts.inspect'),
                   ])>
                    <i data-lucide="list-checks" class="size-3.5" aria-hidden="true"></i>
                    <span>2. Pemeriksaan Bentrok & Kesiapan</span>
                </a>
                @if ($reviewStatus['ready'] ?? false)
                    <a href="{{ route('admin.schedules.index', ['tab' => 'penetapan', 'draf' => $version->id]) }}"
                       class="inline-flex items-center gap-1.5 rounded-control bg-success px-3 py-1 text-xs font-semibold text-white shadow-2xs transition hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                        <i data-lucide="check-circle" class="size-3.5" aria-hidden="true"></i>
                        <span>3. Lanjut ke Penetapan</span>
                    </a>
                @endif
            </div>
        </div>
    @endif
</div>
