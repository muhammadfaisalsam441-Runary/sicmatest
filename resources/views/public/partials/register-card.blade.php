@use('App\Support\Nav')
<section class="flex items-center justify-between gap-6 rounded-card border border-border bg-surface px-6 py-6">
    <div class="flex flex-col gap-2">
        <h2 class="text-lg font-semibold text-ink">Belum punya akun? Daftar sebagai Keti.</h2>
        <p class="text-sm text-ink">Admin JTIK membantu aktivasi akun Keti dan kendala akses.</p>
    </div>
    <x-button variant="secondary" :href="Nav::url('register')" class="shrink-0 px-8">Daftar sebagai Keti</x-button>
</section>
