@use('App\Support\Wita')
{{-- Label "Data simulasi" + waktu; diklik membuka dialog "Pratinjau ketersediaan ruang". --}}
<button type="button" x-data x-on:click="$dispatch('open-modal', 'pratinjau-ketersediaan')" class="inline-flex min-h-8 max-w-full cursor-pointer flex-wrap items-center gap-2 rounded-control text-right text-xs font-medium text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
    @if (config('sicma.public_portal.show_sim_label'))
        <x-status-badge variant="primary">{{ config('sicma.public_portal.sim_label') }}</x-status-badge>
    @endif
    <span>{{ Wita::stamp($checkedAt) }}</span>
</button>
