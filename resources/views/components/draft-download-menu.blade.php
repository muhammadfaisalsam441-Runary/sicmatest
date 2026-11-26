@props(['version', 'size' => 'sm'])

@php
    $popoverId = 'draft-download-'.$version->id;
    $sizeClasses = match ($size) {
        'sm' => 'px-3.5 py-2 text-xs',
        'lg' => 'px-6 py-3.5 text-base',
        default => 'px-5 py-3 text-sm',
    };
    $views = [
        'kelas' => 'Per Kelas',
        'dosen' => 'Per Dosen',
        'ruang' => 'Per Ruang',
    ];
@endphp

<div class="inline-flex">
    <button type="button"
            popovertarget="{{ $popoverId }}"
            {{ $attributes->merge(['class' => "sicma-btn sicma-btn-outline sicma-btn-secondary inline-flex cursor-pointer items-center justify-center gap-2 rounded-control border border-border bg-surface font-semibold text-primary transition hover:bg-primary-soft/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50 {$sizeClasses}"]) }}>
        <i data-lucide="download" aria-hidden="true" class="sicma-btn-icon size-4 shrink-0"></i>
        <span>Unduh</span>
        <i data-lucide="chevron-down" aria-hidden="true" class="size-3.5 shrink-0 text-muted"></i>
    </button><div id="{{ $popoverId }}" popover="auto" class="sicma-draft-download-popover">
        <div class="flex flex-col gap-3">
            <div class="flex flex-col gap-1">
                <p class="px-2 pt-0.5 text-[11px] font-semibold tracking-wider text-muted uppercase">Excel (.xlsx)</p>
                @foreach ($views as $key => $label)
                    <a href="{{ route('admin.drafts.excel', [$version, 'tinjau' => $key]) }}"
                       data-native
                       class="flex items-center justify-between gap-2 rounded-control px-2.5 py-1.5 text-xs font-medium text-ink transition hover:bg-primary-soft/60 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                        <span class="inline-flex items-center gap-2">
                            <i data-lucide="file-spreadsheet" aria-hidden="true" class="size-3.5 shrink-0 text-success"></i>
                            <span>{{ $label }}</span>
                        </span>
                        <i data-lucide="download" aria-hidden="true" class="size-3 shrink-0 text-muted"></i>
                    </a>
                @endforeach
            </div>

            <div class="border-t border-border pt-2 flex flex-col gap-1">
                <p class="px-2 text-[11px] font-semibold tracking-wider text-muted uppercase">PDF (.pdf)</p>
                @foreach ($views as $key => $label)
                    <a href="{{ route('admin.drafts.pdf', [$version, 'tinjau' => $key]) }}"
                       data-native
                       class="flex items-center justify-between gap-2 rounded-control px-2.5 py-1.5 text-xs font-medium text-ink transition hover:bg-primary-soft/60 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                        <span class="inline-flex items-center gap-2">
                            <i data-lucide="download" aria-hidden="true" class="size-3.5 shrink-0 text-primary"></i>
                            <span>{{ $label }}</span>
                        </span>
                        <i data-lucide="download" aria-hidden="true" class="size-3 shrink-0 text-muted"></i>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</div>
