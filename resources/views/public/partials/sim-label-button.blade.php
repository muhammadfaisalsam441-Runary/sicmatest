@use('App\Support\Wita')
{{-- Label "Data simulasi" + waktu; diklik membuka dialog "Pratinjau ketersediaan ruang". --}}
<button type="button" x-data x-on:click="$dispatch('open-modal', 'pratinjau-ketersediaan')" class="flex flex-col items-end gap-2 text-right">
    @if (config('sicma.public_portal.show_sim_label'))
        <x-status-badge variant="primary">Data simulasi</x-status-badge>
    @endif
    <span class="text-xs text-ink">{{ Wita::stamp($checkedAt) }}</span>
</button>
