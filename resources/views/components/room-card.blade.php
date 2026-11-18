@props([
    'name',
    'badge' => 'Tersedia',
    'location',
    'capacity' => null,
    'facilities' => [],
    'timeLabel' => null,
    'time' => null,
    'remaining' => null,
    'note' => null,
    'href' => null,
    'booking' => null,
    'size' => 'compact',
])

@php
    $isAvailable = $badge === 'Tersedia';
    $isBusy = $badge === 'Digunakan';
    $variant = match ($badge) {
        'Tersedia' => 'success',
        'Digunakan' => 'danger',
        default => 'neutral',
    };
    $capacityText = $capacity === null ? '—' : $capacity.' kursi';
    $visibleFacilities = array_slice($facilities, 0, 3);
    $extraFacilities = max(0, count($facilities) - 3);
@endphp

<article data-room-card
         data-status-tone="{{ $variant }}"
         {{ $attributes->merge(['class' => 'sicma-room-card sicma-card-interactive sicma-card-enter relative flex flex-col gap-3.5 overflow-hidden rounded-card border border-border bg-surface '.($size === 'large' ? 'p-5 sm:p-6' : 'p-4')]) }}>
    <span aria-hidden="true" class="sicma-card-spotlight"></span>
    <span aria-hidden="true" class="sicma-card-accent"></span>
    <div class="flex items-start justify-between gap-3">
        <div class="flex min-w-0 flex-col gap-1">
            <h3 class="sicma-card-title {{ $size === 'large' ? 'text-xl' : 'text-lg' }} font-semibold leading-snug text-ink transition-colors duration-200">{{ $name }}</h3>
            <p class="inline-flex items-center gap-1.5 text-xs text-muted">
                <i data-lucide="map-pin" aria-hidden="true" class="sicma-card-pin size-3.5 shrink-0 transition-colors duration-200"></i>
                <span class="truncate">{{ $location }}</span>
            </p>
        </div>
        <x-status-badge :variant="$variant" :pulse="$isBusy">{{ $badge }}</x-status-badge>
    </div>

    <dl class="sicma-card-meta-box grid grid-cols-2 items-start gap-3 rounded-control border border-transparent bg-page px-3.5 py-2.5 text-sm transition-colors duration-200">
        <div class="flex flex-col gap-0.5">
            <dt class="text-[11px] font-medium text-muted">Kapasitas</dt>
            <dd class="inline-flex items-center gap-1.5 font-semibold text-ink tabular-nums">
                <i data-lucide="users" aria-hidden="true" class="size-3.5 shrink-0 text-muted"></i>
                <span>{{ $capacityText }}</span>
            </dd>
        </div>
        @if ($timeLabel)
            <div class="flex flex-col items-end gap-0.5 text-right">
                <dt class="text-[11px] font-medium text-muted">{{ $timeLabel }}</dt>
                <dd @class([
                    'font-semibold tabular-nums',
                    'text-success' => $isAvailable,
                    'text-ink' => $badge === 'Tutup',
                    'text-primary' => ! $isAvailable && $badge !== 'Tutup',
                ])>{{ $time ?? '—' }}</dd>
                @if ($remaining)
                    <dd class="text-[11px] text-muted tabular-nums">{{ $remaining }}</dd>
                @endif
            </div>
        @endif
    </dl>

    @if ($visibleFacilities !== [])
        <div class="flex flex-wrap gap-1.5">
            @foreach ($visibleFacilities as $facility)
                <span class="inline-flex items-center gap-1 rounded-control border border-border bg-page px-2 py-0.5 text-[11px] font-medium text-ink">
                    <i data-lucide="check" aria-hidden="true" class="size-3 text-primary"></i>
                    {{ $facility }}
                </span>
            @endforeach
            @if ($extraFacilities > 0)
                <span class="inline-flex items-center rounded-control border border-border bg-page px-2 py-0.5 text-[11px] font-medium text-muted">
                    +{{ $extraFacilities }} lainnya
                </span>
            @endif
        </div>
    @endif

    @if ($note)
        <p class="text-xs leading-relaxed text-muted">{{ $note }}</p>
    @endif

    @if ($href || $booking || $slot->isNotEmpty() || ($remaining && ! $timeLabel))
        <div class="mt-auto flex flex-wrap items-center justify-between gap-3 border-t border-border/60 pt-3">
            <span class="text-xs font-medium text-muted">{{ ! $timeLabel ? $remaining : '' }}</span>
            @if ($booking)
                {{-- Portal Keti: pesan langsung dari kartu; detail ruangan sebagai tautan kedua. --}}
                <div class="flex items-center gap-3">
                    @if ($href)
                        <a href="{{ $href }}"
                           aria-label="Detail ruangan {{ $name }}"
                           class="rounded text-sm font-semibold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Detail ruangan</a>
                    @endif
                    <x-button size="sm" x-data x-on:click="$dispatch('open-booking', {{ \Illuminate\Support\Js::from($booking) }})" :aria-label="'Pesan '.$name">Pesan</x-button>
                </div>
            @elseif ($href)
                <x-button size="sm" variant="soft" :href="$href" :aria-label="'Detail ruangan '.$name">Detail ruangan</x-button>
            @endif
            {{ $slot }}
        </div>
    @endif
</article>
