@use('App\Support\Nav')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head', ['title' => trim($__env->yieldContent('title')) ?: null])
    </head>
    <body class="flex min-h-screen flex-col">
        <header class="border-b border-border bg-surface">
            <div class="mx-auto flex h-[88px] w-full max-w-[1168px] items-center justify-between px-8">
                <a href="{{ route('home') }}" class="text-[28px] font-bold tracking-tight text-primary">SICMA</a>

                <nav class="flex items-center gap-10 text-[15px] font-medium" aria-label="Navigasi utama">
                    @foreach ([['Beranda', 'home', 'home'], ['Ruangan', 'public.rooms', 'public.rooms'], ['Panduan', 'public.guide', 'public.guide'], ['Bantuan', 'public.help', 'public.help']] as [$label, $routeName, $pattern])
                        <a href="{{ Nav::url($routeName) }}"
                           @class([
                               'border-b-2 px-2 py-2',
                               'border-primary text-primary' => Nav::active($pattern),
                               'border-transparent text-muted hover:text-ink' => ! Nav::active($pattern),
                           ])
                           @if (Nav::active($pattern)) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </nav>

                @auth
                    <x-button :href="auth()->user()->isAdmin() ? Nav::url('admin.dashboard') : Nav::url('keti.search')">Buka SICMA</x-button>
                @else
                    <x-button :href="Nav::url('login')" class="w-28">Masuk</x-button>
                @endauth
            </div>
        </header>

        <main class="mx-auto w-full max-w-[1168px] flex-1 px-8 py-12">
            @yield('content')
        </main>

        <footer class="border-t border-border py-8 text-center text-xs text-muted">
            SICMA • Jurusan Teknik Informatika dan Komputer
        </footer>

        <x-toast />
        @stack('dialogs')
    </body>
</html>
