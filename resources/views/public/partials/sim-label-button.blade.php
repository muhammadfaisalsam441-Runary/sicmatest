@use('App\Support\Wita')
{{-- Label "Data simulasi" + waktu; diklik membuka dialog "Pratinjau ketersediaan ruang". --}}
<button type="button" x-data x-on:click="$dispatch('open-modal', 'pratinjau-ketersediaan')" class="flex min-h-11 max-w-full cursor-pointer flex-col items-start justify-center gap-1.5 rounded-control text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:shrink-0 sm:items-end sm:gap-2 sm:text-right">
    @if (config('sicma.public_portal.show_sim_label'))
        <x-status-badge variant="primary">{{ config('sicma.public_portal.sim_label') }}</x-status-badge>
    @endif
    <span class="text-xs text-ink">{{ Wita::stamp($checkedAt) }}</span>
</button>
