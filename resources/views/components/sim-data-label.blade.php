@use('App\Support\Wita')
@props(['at' => null])

{{-- Label "Data simulasi" + waktu pembaruan di dekat data status. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-end gap-2']) }}>
    @if (config('sicma.public_portal.show_sim_label'))
        <x-status-badge variant="primary">Data simulasi</x-status-badge>
    @endif
    <span class="text-xs text-ink">{{ Wita::stamp($at ?? now()) }}</span>
</div>
