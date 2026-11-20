@use('App\Support\Nav')
@use('App\Support\Wita')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head', ['title' => trim($__env->yieldContent('title')) ?: null])
    </head>
    <body class="min-h-dvh" data-portal="keti" data-portal-user="{{ auth()->id() }}" data-login-url="{{ route('login') }}" data-validation-errors="{{ $errors->any() ? 'true' : 'false' }}">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-[70] focus:rounded-control focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-lg">
            Lewati ke konten utama
        </a>

        <div class="grid min-h-dvh grid-cols-1 lg:grid-cols-[220px_1fr]">
            <aside id="keti-sidebar"
                   class="hidden h-dvh flex-col overflow-y-auto border-r border-border bg-surface/90 px-5 backdrop-blur-md sicma-safe-top sicma-safe-bottom lg:sticky lg:top-0 lg:flex">
                <div class="flex flex-col gap-10 py-9">
                    <div class="flex flex-col gap-6">
                        <a href="{{ Nav::url('keti.search') }}" class="inline-flex items-center gap-2.5 rounded-control text-[28px] font-bold tracking-tight text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                            <x-brand-mark size="size-8" />
                            <span>SICMA</span>
                        </a>
                        <span class="text-xs text-muted">Ruang kelas JTIK</span>
                    </div>

                    <nav class="flex flex-col gap-3" aria-label="Menu Portal Keti">
                        @foreach ([
                            ['My Class', 'keti.my-class', ['keti.my-class', 'keti.meetings.*']],
                            ['Cari Ruangan', 'keti.search', ['keti.search', 'keti.rooms.calendar']],
                            ['Ruangan JTIK', 'keti.rooms.index', ['keti.rooms.index', 'keti.rooms.show']],
                            ['Favorit', 'keti.favorites.index', ['keti.favorites.*']],
                            ['Aktivitas', 'keti.activity', ['keti.activity', 'keti.reservations.*']]
                        ] as [$label, $routeName, $patterns])
                            <a href="{{ Nav::url($routeName) }}"
                                @class([
                                    'flex min-h-11 items-center justify-center rounded-control border px-4 py-2.5 text-center text-sm font-semibold text-primary transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                                    'border-primary-soft bg-primary-soft' => Nav::active(...$patterns),
                                    'border-border bg-surface hover:bg-primary-soft/60' => ! Nav::active(...$patterns),
                                ])
                                @if (Nav::active(...$patterns)) aria-current="page" @endif>{{ $label }}</a>
                        @endforeach
                    </nav>
                </div>
            </aside>

            <div class="relative isolate flex min-w-0 flex-col">
                <x-ambient-backdrop variant="portal" />
                <header class="sticky top-0 z-30 flex min-h-[calc(4rem+env(safe-area-inset-top,0px))] shrink-0 items-center justify-between gap-2 border-b border-border bg-surface/85 px-4 shadow-xs backdrop-blur-md sicma-safe-top sm:gap-4 sm:px-6 lg:px-8">
                    {{-- Sisi Kiri: Beranda Mobile & Breadcrumb / Halaman Aktif --}}
                    <div class="flex min-w-0 items-center gap-2 sm:gap-3">
                        <a href="{{ Nav::url('keti.search') }}" class="inline-flex size-10 shrink-0 items-center justify-center rounded-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary lg:hidden" aria-label="Beranda Keti SICMA"><x-brand-mark size="size-8" /></a>

                        <nav class="flex min-w-0 items-center gap-1.5 text-sm text-muted sm:gap-2" aria-label="Breadcrumb">
                            <a href="{{ Nav::url('keti.my-class') }}" class="inline-flex min-h-11 shrink-0 items-center rounded font-medium text-muted transition hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary lg:min-h-0">Keti</a>
                            @php($headerCrumbs = array_values(array_filter(explode('|', trim($__env->yieldContent('breadcrumb')) ?: trim($__env->yieldContent('title'))))))
                            @foreach ($headerCrumbs as $crumb)
                                <span class="shrink-0 text-border" aria-hidden="true">›</span>
                                <span @class(['truncate', 'font-semibold text-ink' => $loop->last]) @if ($loop->last) aria-current="page" @endif>{{ $crumb }}</span>
                            @endforeach
                        </nav>
                    </div>

                    {{-- Sisi Kanan: Aksi Pintasan, Notifikasi & Profil Keti --}}
                    <div class="flex shrink-0 items-center gap-2 sm:gap-3">
                        @if (trim($__env->yieldContent('breadcrumb-actions')))
                            <div class="hidden items-center gap-3 sm:flex">
                                <div class="flex items-center gap-2">
                                    @yield('breadcrumb-actions')
                                </div>
                                <div class="h-5 w-px bg-border" aria-hidden="true"></div>
                            </div>
                        @endif

                        <x-portal-header-actions portal="keti" :unread-notification-count="$unreadNotificationCount" :portal-user="$portalUser" :portal-class-group="$portalClassGroup" />
                    </div>
                </header>

                <main id="main-content" tabindex="-1" class="flex-1 px-4 py-5 outline-none sm:px-6 sm:py-6 lg:px-8">
                    @yield('content')
                </main>

                <footer class="flex flex-wrap items-center justify-between gap-4 px-4 pb-24 text-xs text-muted sm:px-6 lg:px-8 lg:pb-6">
                    <span>JTIK • SICMA</span>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <button type="button"
                                data-toggle-bg-motion
                                aria-label="Jeda animasi latar"
                                class="inline-flex min-h-11 cursor-pointer items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-[11px] font-semibold text-muted transition hover:border-primary/40 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary lg:min-h-0 lg:px-2.5 lg:py-1">
                            <i data-lucide="sparkles" aria-hidden="true" class="size-3.5 text-primary"></i>
                            <span data-bg-motion-label>Jeda animasi latar</span>
                        </button>
                        <button type="button"
                                data-replay-splash
                                title="Putar animasi 3D SICMA"
                                class="inline-flex min-h-11 cursor-pointer items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-[11px] font-semibold text-primary transition hover:border-primary/40 hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary lg:min-h-0 lg:px-2.5 lg:py-1">
                            <i data-lucide="sparkles" aria-hidden="true" class="size-3.5"></i>
                            <span>Animasi Logo 3D</span>
                        </button>
                    </div>
                </footer>

                <nav aria-label="Navigasi cepat mobile" class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-5 border-t border-border bg-surface/95 shadow-[0_-4px_20px_-6px_rgba(16,34,60,0.12)] backdrop-blur-md lg:hidden sicma-safe-bottom">
                    @foreach ([
                        ['My Class', 'calendar-days', 'keti.my-class', ['keti.my-class', 'keti.meetings.*']],
                        ['Cari', 'search', 'keti.search', ['keti.search', 'keti.rooms.calendar']],
                        ['Ruangan', 'building-2', 'keti.rooms.index', ['keti.rooms.index', 'keti.rooms.show']],
                        ['Favorit', 'heart', 'keti.favorites.index', ['keti.favorites.*']],
                        ['Aktivitas', 'history', 'keti.activity', ['keti.activity', 'keti.reservations.*']],
                    ] as [$label, $icon, $routeName, $patterns])
                        @php($isActive = Nav::active(...$patterns))
                        <a href="{{ Nav::url($routeName) }}"
                           @class([
                               'flex min-h-12 flex-col items-center justify-center gap-0.5 px-1 py-1.5 text-[11px] font-semibold transition focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary',
                               'text-primary' => $isActive,
                               'text-muted hover:text-primary' => ! $isActive,
                           ])
                           @if ($isActive) aria-current="page" @endif>
                            <span class="{{ $isActive ? 'bg-primary-soft text-primary' : '' }} inline-flex items-center justify-center rounded-full px-3 py-0.5 transition">
                                <i data-lucide="{{ $icon }}" aria-hidden="true" class="size-4 shrink-0"></i>
                            </span>
                            <span class="max-w-full truncate">{{ $label }}</span>
                        </a>
                    @endforeach
                </nav>
            </div>
        </div>

        <x-toast fallback-error />
        <x-splash-screen />
        @stack('dialogs')
    </body>
</html>
