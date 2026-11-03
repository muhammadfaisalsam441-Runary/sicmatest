@use('App\Support\Nav')
@use('App\Support\Wita')
<x-modal name="pratinjau-ketersediaan" width="max-w-md">
    <div class="flex flex-col gap-4">
        <x-status-badge variant="primary" class="self-start">Data simulasi</x-status-badge>
        <h2 class="text-xl font-semibold text-ink">Pratinjau ketersediaan ruang</h2>
        <p class="text-sm leading-relaxed text-muted">Tersedia berarti ruangan dapat digunakan. Digunakan berarti ruangan sedang dipakai.</p>
        <p class="text-sm text-ink">Pembaruan: {{ Wita::stamp($checkedAt) }}</p>
        <x-button x-on:click="$dispatch('close-modal', 'pratinjau-ketersediaan')" class="w-full">Mengerti</x-button>
    </div>
</x-modal>

<x-modal name="bantuan-akun-keti" width="max-w-md">
    <div class="flex flex-col gap-4">
        <h2 class="text-xl font-semibold text-ink">Bantuan akun Keti</h2>
        <p class="text-sm leading-relaxed text-muted">Daftar sebagai Keti dan verifikasi email. Admin akan memeriksa identitas serta kelas yang diwakili.</p>
        <div class="grid grid-cols-2 gap-3">
            <x-button :href="Nav::url('login')">Ke halaman masuk</x-button>
            <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'bantuan-akun-keti')">Tutup</x-button>
        </div>
    </div>
</x-modal>
