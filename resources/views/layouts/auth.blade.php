<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head', ['title' => trim($__env->yieldContent('title')) ?: null])
    </head>
    <body class="min-h-screen">
        <div class="grid min-h-screen grid-cols-[41%_1fr]">
            <aside class="flex flex-col bg-primary px-14 py-14 text-white">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 self-start rounded-control text-2xl font-bold tracking-tight focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                    <x-brand-mark size="size-9" class="ring-1 ring-white/20" />
                    <span>SICMA</span>
                </a>

                <div class="my-auto flex max-w-md flex-col gap-8">
                    <h1 class="text-[44px] leading-tight font-bold">Satu akses untuk ruang kelas JTIK.</h1>
                    <p class="text-[15px] leading-relaxed text-white/90">Lihat ruangan tanpa akun. Masuk untuk menggunakan ruangan atau mengelola layanan SICMA.</p>
                    <p class="text-lg font-medium">Publik • Keti • Admin</p>
                </div>
            </aside>

            <main class="flex items-center justify-center bg-page px-12 py-14">
                <div class="w-full max-w-[480px]">
                    @yield('content')
                </div>
            </main>
        </div>

        <x-toast />
        <x-splash-screen />
        @stack('dialogs')
    </body>
</html>
