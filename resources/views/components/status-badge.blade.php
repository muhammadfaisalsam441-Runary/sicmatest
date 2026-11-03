@props(['variant' => 'neutral'])

{{-- Badge status berbentuk pil: success = Tersedia/Selesai, danger = Digunakan/masalah, primary = info, neutral = Belum pasti. --}}
<span {{ $attributes->class([
    'inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium whitespace-nowrap',
    'bg-success-soft text-success' => $variant === 'success',
    'bg-danger-soft text-danger' => $variant === 'danger',
    'bg-primary-soft text-primary' => $variant === 'primary',
    'bg-page text-muted ring-1 ring-border' => $variant === 'neutral',
    'bg-amber-50 text-amber-800' => $variant === 'warning',
]) }}>{{ $slot }}</span>
