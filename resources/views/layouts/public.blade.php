@use('App\Support\HomeRoute')
@use('App\Support\Nav')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head', ['title' => trim($__env->yieldContent('title')) ?: null])
    </head>
    <body class="relative isolate flex min-h-dvh flex-col" data-portal="public" data-portal-user="{{ auth()->id() }}" data-login-url="{{ route('login') }}" data-validation-errors="{{ $errors->any() ? 'true' : 'false' }}">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-[70] focus:rounded-control focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-lg">
            Lewati ke konten utama
        </a>
        <x-ambient-backdrop variant="public" />

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
                class="sicma-safe-top sticky top-0 z-30 border-b border-border bg-surface/85 backdrop-blur-md">
            <div class="mx-auto flex min-h-16 w-full max-w-[1168px] items-center justify-between gap-2 px-4 sm:gap-4 sm:px-6 md:h-[88px] lg:px-8">
                <a href="{{ route('home') }}" data-prefetch class="inline-flex shrink-0 items-center gap-2 rounded-control text-xl font-bold tracking-tight text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:gap-2.5 sm:text-[28px]">
                    <x-brand-mark size="size-8 sm:size-9" />
                    <span>SICMA</span>
                </a>

                <nav class="hidden items-center gap-6 text-[15px] font-medium md:flex lg:gap-10" aria-label="Navigasi utama">
                    @foreach ($navItems as [$label, $routeName, $pattern])
                        <a href="{{ Nav::url($routeName) }}"
                           data-prefetch
                           @class([
                               'rounded-t border-b-2 px-2 py-2 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                               'border-primary text-primary' => Nav::active($pattern),
                               'border-transparent text-muted hover:text-ink' => ! Nav::active($pattern),
                           ])
                           @if (Nav::active($pattern)) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </nav>

                <div class="flex min-w-0 items-center gap-2 sm:gap-3">
                    @auth
                        <x-button :href="HomeRoute::for(auth()->user())" animated size="sm">Buka SICMA</x-button>
                    @else
                        <x-button :href="Nav::url('login')" animated size="sm">Masuk</x-button>
                    @endauth

                    <button type="button"
                            x-ref="menuToggle"
                            x-on:click="mobileMenuOpen = ! mobileMenuOpen"
                            class="flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-control border border-border bg-surface text-ink transition hover:bg-page focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary md:hidden"
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
                 class="max-h-[calc(100dvh-4.5rem)] overflow-y-auto border-t border-border bg-surface px-4 py-3 sm:px-6 md:hidden">
                <nav class="mx-auto flex max-w-[1168px] flex-col gap-1 text-[15px] font-medium" aria-label="Navigasi mobile">
                    @foreach ($navItems as [$label, $routeName, $pattern])
                        <a href="{{ Nav::url($routeName) }}"
                           x-on:click="mobileMenuOpen = false"
                           @class([
                               'flex min-h-11 items-center rounded-control px-3.5 py-2.5 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
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

        <footer class="sicma-safe-bottom mx-auto flex w-full max-w-[1168px] flex-col items-start justify-between gap-3 px-4 pt-4 pb-8 text-xs text-muted sm:flex-row sm:items-center sm:px-6 sm:pb-10 lg:px-8">
            <span>SICMA • Jurusan Teknik Informatika dan Komputer</span>
            <div class="flex flex-wrap items-center gap-2.5 sm:gap-3">
                <button type="button"
                        data-toggle-bg-motion
                        aria-label="Jeda animasi latar"
                        class="inline-flex min-h-10 cursor-pointer items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-[11px] font-semibold text-muted transition hover:border-primary/40 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    <i data-lucide="sparkles" aria-hidden="true" class="size-3.5 text-primary"></i>
                    <span data-bg-motion-label>Jeda animasi latar</span>
                </button>
                <button type="button"
                        data-replay-splash
                        title="Putar animasi 3D SICMA"
                        class="inline-flex min-h-10 cursor-pointer items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-[11px] font-semibold text-primary transition hover:border-primary/40 hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    <i data-lucide="sparkles" aria-hidden="true" class="size-3.5"></i>
                    <span>Animasi Logo 3D</span>
                </button>
                <span>Ruangan tepat untuk kelasmu.</span>
            </div>
        </footer>

        <x-toast />
        <x-splash-screen />
        @stack('dialogs')
    </body>
</html>
