@props(['variant' => 'neutral', 'pulse' => false])

{{-- Badge status berbentuk pil: success = Tersedia/Selesai, danger = Digunakan/masalah, primary = info, warning = Peringatan, neutral = Belum pasti. --}}
<span {{ $attributes->class([
    'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium whitespace-nowrap',
    'bg-success-soft text-success' => $variant === 'success',
    'bg-danger-soft text-danger' => $variant === 'danger',
    'bg-primary-soft text-primary' => $variant === 'primary',
    'bg-page text-muted ring-1 ring-border' => $variant === 'neutral',
    'bg-warning-soft text-warning-ink' => $variant === 'warning',
]) }}>
    @if ($pulse)
        <span class="sicma-live-dot size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
