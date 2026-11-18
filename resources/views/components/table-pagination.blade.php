@props([
    'paginator',
    'form',
    'perPage',
    'options' => [15, 30, 50, 100],
    'unit' => 'data',
    'selectId' => 'per-page',
    'ariaLabel' => 'Baris per halaman',
    'unitSuffix' => '',
])

@if ($paginator->total() > 0)
    <div {{ $attributes->merge(['class' => 'flex flex-col gap-3 border-t border-border pt-3 sm:flex-row sm:items-center sm:justify-between']) }}>
        <div class="min-w-0 flex-1">
            @if ($paginator->hasPages())
                {{ $paginator->links() }}
            @else
                <div class="flex flex-wrap items-center gap-3 py-1 text-xs text-muted">
                    <span>
                        Menampilkan <span class="font-semibold text-ink">1</span> sampai <span class="font-semibold text-ink">{{ $paginator->total() }}</span> dari <span class="font-semibold text-ink">{{ number_format($paginator->total()) }}</span> {{ $unit }}
                    </span>
                    <span class="inline-flex h-7 min-w-[32px] items-center justify-center rounded-control border border-border bg-surface px-2.5 text-xs font-semibold text-ink">
                        Halaman 1 dari 1
                    </span>
                </div>
            @endif
        </div>

        <div class="flex shrink-0 items-center gap-1.5 text-xs text-muted">
            <label for="{{ $selectId }}" class="whitespace-nowrap">Baris:</label>
            <select id="{{ $selectId }}"
                    name="per_page"
                    form="{{ $form }}"
                    aria-label="{{ $ariaLabel }}"
                    @change="$el.form.requestSubmit()"
                    class="rounded-control border border-border bg-surface px-2.5 py-1.5 text-xs text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                @foreach ($options as $n)
                    <option value="{{ $n }}" @selected((int) $perPage === (int) $n)>{{ $n }}{{ $unitSuffix }}</option>
                @endforeach
            </select>
        </div>
    </div>
@endif
