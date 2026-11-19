<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head', ['title' => trim($__env->yieldContent('title')) ?: null])
    </head>
    <body class="min-h-dvh" data-portal="auth">
        <div class="grid min-h-dvh grid-cols-1 lg:grid-cols-[41%_1fr] grid-rows-[auto_1fr] lg:grid-rows-none">
            <aside class="relative flex flex-col justify-between gap-4 overflow-hidden bg-primary px-5 py-5 text-white sm:px-8 sm:py-7 lg:gap-0 lg:px-14 lg:py-14 sicma-safe-top">
                <x-ambient-backdrop variant="auth" />

                <div class="relative z-10 flex items-center justify-between gap-3">
                    <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center gap-3 rounded-control text-xl font-bold tracking-tight sm:text-2xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                        <x-brand-mark size="size-9" class="ring-1 ring-white/20" />
                        <span>SICMA</span>
                    </a>
                </div>

                <div class="relative z-10 flex max-w-md flex-col gap-2 sm:gap-3 lg:my-auto lg:gap-8">
                    <h1 class="text-[clamp(1.5rem,1.1rem+2vw,2.75rem)] leading-tight font-bold">Satu akses untuk ruang kelas JTIK.</h1>
                    <p class="text-sm leading-relaxed text-white/90 sm:text-[15px]">Lihat ruangan tanpa akun. Masuk untuk menggunakan ruangan atau mengelola layanan SICMA.</p>
                    <p class="hidden text-sm font-medium sm:block lg:text-lg">Publik • Keti • Admin</p>
                </div>

                <div class="relative z-10 flex flex-wrap items-center justify-between gap-3 pt-2 text-xs text-white/75 lg:pt-6">
                    <span>JTIK • Universitas Negeri Makassar</span>
                    <button type="button"
                            data-toggle-bg-motion
                            aria-label="Jeda animasi latar"
                            class="inline-flex min-h-11 cursor-pointer items-center gap-1.5 rounded-control border border-white/20 bg-white/10 px-3 py-1.5 text-[11px] font-semibold text-white/90 transition hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white lg:min-h-0 lg:px-2.5 lg:py-1">
                        <i data-lucide="sparkles" aria-hidden="true" class="size-3.5"></i>
                        <span data-bg-motion-label>Jeda animasi latar</span>
                    </button>
                </div>
            </aside>

            <main class="relative isolate flex flex-1 items-start justify-center overflow-hidden bg-page px-4 py-6 sm:items-center sm:px-8 sm:py-10 lg:px-12 lg:py-14 sicma-safe-bottom">
                <x-ambient-backdrop variant="portal" />
                <div class="sicma-auth-card relative z-10 w-full max-w-[440px] p-5 sm:p-8 lg:p-9">
                    @yield('content')
                </div>
            </main>
        </div>

        <x-toast />
        <x-splash-screen />
        @stack('dialogs')
    </body>
</html>
