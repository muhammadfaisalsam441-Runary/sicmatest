@use('App\Support\Nav')
<section class="flex flex-col items-start justify-between gap-4 rounded-card border border-border bg-surface p-5 sm:flex-row sm:items-center sm:gap-6 sm:px-6 sm:py-6">
    <div class="flex min-w-0 flex-col gap-2">
        <h2 class="text-lg font-semibold text-ink">Belum punya akun? Daftar sebagai Keti.</h2>
        <p class="text-sm text-ink">Admin JTIK membantu aktivasi akun Keti dan kendala akses.</p>
    </div>
    <x-button variant="secondary" :href="Nav::url('register')" animated class="w-full shrink-0 sm:w-auto">Daftar sebagai Keti</x-button>
</section>
