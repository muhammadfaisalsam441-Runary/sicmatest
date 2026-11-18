@use('App\Support\Nav')
@use('App\Support\Wita')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head', ['title' => trim($__env->yieldContent('title')) ?: null])
    </head>
    <body class="min-h-screen" data-portal="keti" data-portal-user="{{ auth()->id() }}" data-login-url="{{ route('login') }}" data-validation-errors="{{ $errors->any() ? 'true' : 'false' }}">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-[70] focus:rounded-control focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-lg">
            Lewati ke konten utama
        </a>

        <div x-data="{ sidebarOpen: false }"
             x-on:keydown.escape.window="if (sidebarOpen) { sidebarOpen = false; $nextTick(() => $refs.sidebarToggle?.focus()); }"
             @sicma:navigated.document="sidebarOpen = false"
             class="grid min-h-screen grid-cols-1 lg:grid-cols-[220px_1fr]">
            <div x-show="sidebarOpen"
                 x-cloak
                 x-transition.opacity
                 x-on:click="sidebarOpen = false"
                 class="fixed inset-0 z-40 bg-ink/60 lg:hidden"
                 aria-hidden="true"></div>

            <aside id="keti-sidebar"
                   :class="{ 'translate-x-0': sidebarOpen, '-translate-x-full': ! sidebarOpen }"
                   class="fixed inset-y-0 left-0 z-50 flex h-screen w-[220px] -translate-x-full flex-col gap-10 overflow-y-auto border-r border-border bg-surface px-5 py-9 transition-transform duration-200 lg:sticky lg:top-0 lg:z-auto lg:w-auto lg:translate-x-0">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex flex-col gap-6">
                        <a href="{{ Nav::url('keti.search') }}" class="inline-flex items-center gap-2.5 rounded-control text-[28px] font-bold tracking-tight text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                            <x-brand-mark size="size-8" />
                            <span>SICMA</span>
                        </a>
                        <span class="text-xs text-muted">Ruang kelas JTIK</span>
                    </div>
                    <button type="button"
                            x-on:click="sidebarOpen = false; $nextTick(() => $refs.sidebarToggle?.focus())"
                            class="cursor-pointer rounded-control p-1 text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary lg:hidden"
                            aria-label="Tutup menu navigasi">
                        <i data-lucide="x" aria-hidden="true" class="size-5"></i>
                    </button>
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
                                'rounded-control border px-4 py-3 text-center text-sm font-semibold text-primary transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                                'border-primary-soft bg-primary-soft' => Nav::active(...$patterns),
                                'border-border bg-surface hover:bg-primary-soft/60' => ! Nav::active(...$patterns),
                            ])
                            @if (Nav::active(...$patterns)) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </nav>
            </aside>

            <div class="flex min-w-0 flex-col">
                <header class="sticky top-0 z-30 flex h-16 shrink-0 items-center justify-between gap-4 border-b border-border bg-surface px-4 shadow-xs sm:px-6 lg:px-8">
                    {{-- Sisi Kiri: Toggle Mobile & Breadcrumb / Halaman Aktif --}}
                    <div class="flex min-w-0 items-center gap-3">
                        <button type="button"
                                x-ref="sidebarToggle"
                                x-on:click="sidebarOpen = ! sidebarOpen"
                                class="flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-control border border-border bg-surface text-ink transition hover:bg-page focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary lg:hidden"
                                aria-label="Buka menu navigasi"
                                :aria-expanded="sidebarOpen.toString()"
                                aria-controls="keti-sidebar">
                            <i data-lucide="menu" aria-hidden="true" class="size-5"></i>
                        </button>

                        <nav class="flex min-w-0 items-center gap-2 text-sm text-muted" aria-label="Breadcrumb">
                            <a href="{{ Nav::url('keti.my-class') }}" class="shrink-0 rounded font-medium text-muted transition hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Keti</a>
                            @php($headerCrumbs = array_values(array_filter(explode('|', trim($__env->yieldContent('breadcrumb')) ?: trim($__env->yieldContent('title'))))))
                            @foreach ($headerCrumbs as $crumb)
                                <span class="shrink-0 text-border" aria-hidden="true">›</span>
                                <span @class(['truncate', 'font-semibold text-ink' => $loop->last]) @if ($loop->last) aria-current="page" @endif>{{ $crumb }}</span>
                            @endforeach
                        </nav>
                    </div>

                    {{-- Sisi Kanan: Aksi Pintasan, Notifikasi & Profil Keti --}}
                    <div class="flex shrink-0 items-center gap-3">
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

                <footer class="flex items-center gap-6 px-4 pb-6 text-xs text-muted sm:gap-24 sm:px-6 lg:px-8">
                    <span>JTIK • SICMA</span>
                </footer>
            </div>
        </div>

        <x-toast fallback-error />
        <x-splash-screen />
        @stack('dialogs')
    </body>
</html>
