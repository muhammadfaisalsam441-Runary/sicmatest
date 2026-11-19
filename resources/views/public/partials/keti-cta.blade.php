@use('App\Support\Nav')
<section class="flex flex-col items-start justify-between gap-4 rounded-card border border-primary-soft bg-primary-soft p-5 sm:flex-row sm:items-center sm:gap-6 sm:px-6 sm:py-6">
    <div class="flex min-w-0 flex-col gap-2">
        <h2 class="text-lg font-semibold text-ink">Mau menggunakan ruangan untuk kelasmu?</h2>
        <p class="text-sm text-ink">Pemesanan, mulai/selesai, dan riwayat penggunaan tersedia bagi Keti yang terdaftar.</p>
    </div>
    <x-button :href="Nav::url('login')" animated class="w-full shrink-0 sm:w-auto">Masuk sebagai Keti untuk memesan</x-button>
</section>
