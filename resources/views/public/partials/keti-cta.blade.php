@use('App\Support\Nav')
<section class="flex items-center justify-between gap-6 rounded-card border border-primary-soft bg-primary-soft px-6 py-6">
    <div class="flex flex-col gap-2">
        <h2 class="text-lg font-semibold text-ink">Mau menggunakan ruangan untuk kelasmu?</h2>
        <p class="text-sm text-ink">Pemesanan, mulai/selesai, dan riwayat penggunaan tersedia bagi Keti yang terdaftar.</p>
    </div>
    <x-button :href="Nav::url('login')" class="shrink-0 px-9">Masuk sebagai Keti untuk memesan</x-button>
</section>
