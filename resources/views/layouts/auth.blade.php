<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head', ['title' => trim($__env->yieldContent('title')) ?: null])
    </head>
    <body class="min-h-screen">
        <div class="grid min-h-screen grid-cols-[41%_1fr]">
            <aside class="relative flex flex-col overflow-hidden bg-primary px-14 py-14 text-white">
                <x-ambient-backdrop variant="auth" />

                <a href="{{ route('home') }}" class="relative z-10 inline-flex items-center gap-3 self-start rounded-control text-2xl font-bold tracking-tight focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                    <x-brand-mark size="size-9" class="ring-1 ring-white/20" />
                    <span>SICMA</span>
                </a>

                <div class="relative z-10 my-auto flex max-w-md flex-col gap-8">
                    <h1 class="text-[44px] leading-tight font-bold">Satu akses untuk ruang kelas JTIK.</h1>
                    <p class="text-[15px] leading-relaxed text-white/90">Lihat ruangan tanpa akun. Masuk untuk menggunakan ruangan atau mengelola layanan SICMA.</p>
                    <p class="text-lg font-medium">Publik • Keti • Admin</p>
                </div>

                <div class="relative z-10 flex items-center justify-between gap-4 pt-6 text-xs text-white/75">
                    <span>JTIK • Universitas Negeri Makassar</span>
                    <button type="button"
                            data-toggle-bg-motion
                            aria-label="Jeda animasi latar"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-control border border-white/20 bg-white/10 px-2.5 py-1 text-[11px] font-semibold text-white/90 transition hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                        <i data-lucide="sparkles" aria-hidden="true" class="size-3.5"></i>
                        <span data-bg-motion-label>Jeda animasi latar</span>
                    </button>
                </div>
            </aside>

            <main class="relative isolate flex items-center justify-center overflow-hidden bg-page px-12 py-14">
                <x-ambient-backdrop variant="portal" />
                <div class="sicma-auth-card relative z-10 w-full max-w-[440px] p-7 sm:p-9">
                    @yield('content')
                </div>
            </main>
        </div>

        <x-toast />
        <x-splash-screen />
        @stack('dialogs')
    </body>
</html>
