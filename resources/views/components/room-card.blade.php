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
    'size' => 'compact',
])

@php
    $isAvailable = $badge === 'Tersedia';
    $variant = match ($badge) {
        'Tersedia' => 'success',
        'Digunakan' => 'danger',
        default => 'neutral',
    };
    $capacityText = $capacity === null ? '—' : $capacity.' kursi';
    if ($facilities !== []) {
        $capacityText .= ' • '.implode(' • ', array_slice($facilities, 0, 2));
    }
@endphp

<article {{ $attributes->merge(['class' => 'flex flex-col gap-4 rounded-card border border-border bg-surface '.($size === 'large' ? 'p-6' : 'p-4')]) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="flex flex-col gap-2">
            <h3 class="{{ $size === 'large' ? 'text-xl' : 'text-lg' }} font-medium text-ink">{{ $name }}</h3>
            <p class="text-xs text-muted">{{ $location }}</p>
        </div>
        <x-status-badge :variant="$variant">{{ $badge }}</x-status-badge>
    </div>

    <dl class="flex items-end justify-between gap-4 text-sm">
        <div class="flex flex-col gap-1">
            <dt class="text-xs text-muted">Kapasitas</dt>
            <dd class="text-ink">{{ $capacityText }}</dd>
        </div>
        @if ($timeLabel)
            <div class="flex flex-col items-end gap-1 text-right">
                <dt class="text-xs text-muted">{{ $timeLabel }}</dt>
                <dd @class(['font-semibold', 'text-success' => $isAvailable, 'text-primary' => ! $isAvailable])>{{ $time ?? '—' }}</dd>
            </div>
        @endif
    </dl>

    @if ($note)
        <p class="text-xs text-muted">{{ $note }}</p>
    @endif

    @if ($remaining || $href || $slot->isNotEmpty())
        <div class="flex items-center justify-between gap-4">
            <span class="text-sm text-muted">{{ $remaining }}</span>
            @if ($href)
                <x-button variant="soft" :href="$href">Detail ruangan</x-button>
            @endif
            {{ $slot }}
        </div>
    @endif
</article>
