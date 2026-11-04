@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Navigasi Halaman" class="flex flex-col sm:flex-row items-center justify-between gap-4 py-1">
        {{-- Info Jumlah Data & Halaman --}}
        <div class="text-xs text-muted">
            @if ($paginator->firstItem())
                Menampilkan <span class="font-semibold text-ink">{{ $paginator->firstItem() }}</span>
                sampai <span class="font-semibold text-ink">{{ $paginator->lastItem() }}</span>
                dari <span class="font-semibold text-ink">{{ number_format($paginator->total()) }}</span> penawaran
                <span class="text-muted/60">•</span>
                Halaman <span class="font-semibold text-primary">{{ $paginator->currentPage() }}</span> dari <span class="font-semibold text-ink">{{ $paginator->lastPage() }}</span>
            @else
                Total <span class="font-semibold text-ink">{{ number_format($paginator->total()) }}</span> penawaran
            @endif
        </div>

        {{-- Tombol Paginasi Lengkap dengan Nomor Halaman --}}
        <div class="flex items-center gap-1.5 flex-wrap">
            {{-- Tombol Sebelumnya --}}
            @if ($paginator->onFirstPage())
                <span class="inline-flex items-center gap-1 rounded-control border border-border bg-page px-3 py-1.5 text-xs font-medium text-muted/60 cursor-not-allowed">
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                    <span>Sebelumnya</span>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
                   class="inline-flex items-center gap-1 rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-ink hover:bg-primary-soft hover:text-primary transition shadow-xs">
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                    <span>Sebelumnya</span>
                </a>
            @endif

            {{-- Angka-Angka Halaman --}}
            @foreach ($elements as $element)
                {{-- Separator titik-titik (...) --}}
                @if (is_string($element))
                    <span class="inline-flex items-center justify-center px-2 py-1 text-xs text-muted select-none">
                        {{ $element }}
                    </span>
                @endif

                {{-- Array Halaman --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="inline-flex items-center justify-center min-w-[32px] h-8 rounded-control bg-primary text-white text-xs font-bold shadow-xs">
                                {{ $page }}
                            </span>
                        @else
                            <a href="{{ $url }}"
                               class="inline-flex items-center justify-center min-w-[32px] h-8 rounded-control border border-border bg-surface text-ink text-xs font-medium hover:bg-primary-soft hover:text-primary hover:border-primary-soft transition">
                                {{ $page }}
                            </a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Tombol Berikutnya --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next"
                   class="inline-flex items-center gap-1 rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-ink hover:bg-primary-soft hover:text-primary transition shadow-xs">
                    <span>Berikutnya</span>
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                </a>
            @else
                <span class="inline-flex items-center gap-1 rounded-control border border-border bg-page px-3 py-1.5 text-xs font-medium text-muted/60 cursor-not-allowed">
                    <span>Berikutnya</span>
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                </span>
            @endif
        </div>
    </nav>
@endif
