@use('App\Support\HomeRoute')
@use('App\Support\Nav')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head', ['title' => trim($__env->yieldContent('title')) ?: null])
    </head>
    <body class="flex min-h-screen flex-col" data-portal="public" data-portal-user="{{ auth()->id() }}" data-login-url="{{ route('login') }}" data-validation-errors="{{ $errors->any() ? 'true' : 'false' }}">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-[70] focus:rounded-control focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-lg">
            Lewati ke konten utama
        </a>

        @php
            $navItems = [
                ['Beranda', 'home', 'home'],
                ['Ruangan', 'public.rooms', 'public.rooms'],
                ['Panduan', 'public.guide', 'public.guide'],
                ['Bantuan', 'public.help', 'public.help'],
            ];
        @endphp

        <header x-data="{ mobileMenuOpen: false }"
                x-on:keydown.escape.window="if (mobileMenuOpen) { mobileMenuOpen = false; $nextTick(() => $refs.menuToggle?.focus()); }"
                @sicma:navigated.document="mobileMenuOpen = false"
                class="border-b border-border bg-surface">
            <div class="mx-auto flex min-h-16 w-full max-w-[1168px] items-center justify-between gap-4 px-4 sm:px-6 md:h-[88px] lg:px-8">
                <a href="{{ route('home') }}" class="rounded text-2xl font-bold tracking-tight text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:text-[28px]">SICMA</a>

                <nav class="hidden items-center gap-6 text-[15px] font-medium md:flex lg:gap-10" aria-label="Navigasi utama">
                    @foreach ($navItems as [$label, $routeName, $pattern])
                        <a href="{{ Nav::url($routeName) }}"
                           @class([
                               'rounded-t border-b-2 px-2 py-2 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                               'border-primary text-primary' => Nav::active($pattern),
                               'border-transparent text-muted hover:text-ink' => ! Nav::active($pattern),
                           ])
                           @if (Nav::active($pattern)) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </nav>

                <div class="flex items-center gap-2.5 sm:gap-3">
                    @auth
                        <x-button :href="HomeRoute::for(auth()->user())" class="whitespace-nowrap">Buka SICMA</x-button>
                    @else
                        <x-button :href="Nav::url('login')" class="whitespace-nowrap sm:w-28">Masuk</x-button>
                    @endauth

                    <button type="button"
                            x-ref="menuToggle"
                            x-on:click="mobileMenuOpen = ! mobileMenuOpen"
                            class="flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-control border border-border bg-surface text-ink transition hover:bg-page focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary md:hidden"
                            :aria-label="mobileMenuOpen ? 'Tutup menu navigasi' : 'Buka menu navigasi'"
                            :aria-expanded="mobileMenuOpen.toString()"
                            aria-controls="public-mobile-menu">
                        <span x-show="! mobileMenuOpen" aria-hidden="true"><i data-lucide="menu" class="size-5"></i></span>
                        <span x-show="mobileMenuOpen" x-cloak aria-hidden="true"><i data-lucide="x" class="size-5"></i></span>
                    </button>
                </div>
            </div>

            <div id="public-mobile-menu"
                 x-show="mobileMenuOpen"
                 x-cloak
                 x-transition
                 class="border-t border-border bg-surface px-4 py-3 sm:px-6 md:hidden">
                <nav class="mx-auto flex max-w-[1168px] flex-col gap-1 text-[15px] font-medium" aria-label="Navigasi mobile">
                    @foreach ($navItems as [$label, $routeName, $pattern])
                        <a href="{{ Nav::url($routeName) }}"
                           x-on:click="mobileMenuOpen = false"
                           @class([
                               'rounded-control px-3 py-2.5 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                               'bg-primary-soft font-semibold text-primary' => Nav::active($pattern),
                               'text-muted hover:bg-page hover:text-ink' => ! Nav::active($pattern),
                           ])
                           @if (Nav::active($pattern)) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </nav>
            </div>
        </header>

        <main id="main-content" tabindex="-1" class="mx-auto w-full max-w-[1168px] flex-1 px-4 py-6 outline-none sm:px-6 sm:py-8 lg:px-8">
            @yield('content')
        </main>

        <footer class="mx-auto flex w-full max-w-[1168px] flex-col items-start justify-between gap-2 px-4 pt-4 pb-8 text-xs text-muted sm:flex-row sm:items-center sm:px-6 sm:pb-10 lg:px-8">
            <span>SICMA • Jurusan Teknik Informatika dan Komputer</span>
            <span>Ruangan tepat untuk kelasmu.</span>
        </footer>

        <x-toast />
        @stack('dialogs')
    </body>
</html>
