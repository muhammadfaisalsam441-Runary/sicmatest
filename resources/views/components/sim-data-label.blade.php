@use('App\Support\Wita')
@props(['at' => null])

{{-- Label data (bawaan "Data simulasi", lihat config sicma.public_portal) + waktu pembaruan di dekat data status. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-end gap-2']) }}>
    @if (config('sicma.public_portal.show_sim_label'))
        <x-status-badge variant="primary">{{ config('sicma.public_portal.sim_label') }}</x-status-badge>
    @endif
    <span class="text-xs text-ink">{{ Wita::stamp($at ?? now()) }}</span>
</div>
