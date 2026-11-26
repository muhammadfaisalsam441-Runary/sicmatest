@use('App\Support\Nav')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head', ['title' => trim($__env->yieldContent('title')) ?: null])
    </head>
    <body class="min-h-dvh" data-portal="admin" data-portal-user="{{ auth()->id() }}" data-login-url="{{ route('login') }}" data-validation-errors="{{ $errors->any() ? 'true' : 'false' }}">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-[70] focus:rounded-control focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-lg">
            Lewati ke konten utama
        </a>

        <div x-data="{ sidebarOpen: false }"
             x-on:keydown.escape.window="if (sidebarOpen) { sidebarOpen = false; $nextTick(() => $refs.sidebarToggle?.focus()); }"
             @sicma:navigated.document="sidebarOpen = false"
             class="grid min-h-dvh grid-cols-1 lg:grid-cols-[240px_1fr]">
            <div x-show="sidebarOpen"
                 x-cloak
                 x-transition.opacity
                 x-on:click="sidebarOpen = false"
                 class="fixed inset-0 z-40 bg-ink/60 lg:hidden"
                 aria-hidden="true"></div>

            <aside id="admin-sidebar"
                   :class="{ 'translate-x-0': sidebarOpen, '-translate-x-full': ! sidebarOpen }"
                   class="fixed inset-y-0 left-0 z-50 flex h-dvh w-[240px] -translate-x-full flex-col overflow-y-auto overscroll-contain border-r border-border bg-surface px-4 pt-7 transition-transform duration-200 sicma-safe-bottom lg:sticky lg:top-0 lg:z-auto lg:w-auto lg:translate-x-0">
                <div class="flex items-start justify-between gap-2 px-3">
                    <a href="{{ Nav::url('admin.dashboard') }}" class="flex items-center gap-2.5 rounded-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                        <x-brand-mark size="size-8" />
                        <span class="flex flex-col gap-0.5">
                            <span class="text-xs font-semibold text-primary">SICMA</span>
                            <span class="text-xl font-medium text-ink">Panel Admin</span>
                        </span>
                    </a>
                    <button type="button"
                            x-on:click="sidebarOpen = false"
                            class="inline-flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-control text-muted transition hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary lg:hidden"
                            aria-label="Tutup menu navigasi">
                        <i data-lucide="x" aria-hidden="true" class="size-5"></i>
                    </button>
                </div>

                <nav class="mt-7 flex flex-col gap-1" aria-label="Menu Panel Admin">
                    <x-sidebar-link :href="Nav::url('admin.dashboard')" icon="layout-grid" :active="Nav::active('admin.dashboard')">Dashboard</x-sidebar-link>

                    @foreach ([
                        [
                            'Data Master & Akademik',
                            [
                                ['Data Akademik', 'book-open', 'admin.academic.index', ['admin.academic.*']],
                                ['Data Dosen', 'user', 'admin.lecturers.index', ['admin.lecturers.*']],
                                ['Kelola Ruang', 'door-closed', 'admin.rooms.index', ['admin.rooms.*']],
                            ],
                        ],
                        [
                            'Penyusunan Jadwal',
                            [
                                ['Pembagian SKS', 'chart-no-axes-column', 'admin.teaching.index', ['admin.teaching.*']],
                                ['Penyusunan & Draf', 'calendar-days', 'admin.scheduler.create', ['admin.scheduler.*', 'admin.drafts.show', 'admin.drafts.inspect*', 'admin.imports.*']],
                                ['Jadwal Resmi', 'calendar-check', 'admin.schedules.index', ['admin.schedules.*', 'admin.drafts.published']],
                            ],
                        ],
                        [
                            'Operasional & Pemantauan',
                            [
                                ['Akun Keti', 'users', 'admin.keti-accounts.index', ['admin.keti-accounts.*']],
                                ['Riwayat Reservasi', 'clipboard-list', 'admin.reservations.index', ['admin.reservations.*']],
                                ['Log Pemakaian', 'clock', 'admin.usage-logs.index', ['admin.usage-logs.*']],
                            ],
                        ],
                    ] as [$groupLabel, $items])
                        <div class="mt-3 border-t border-border pt-3"><span class="px-3 pb-1 block text-[11px] font-semibold uppercase tracking-wider text-muted">{{ $groupLabel }}</span></div>
                        @foreach ($items as [$label, $icon, $routeName, $patterns])
                            <x-sidebar-link :href="Nav::url($routeName)" :icon="$icon" :active="Nav::active(...$patterns)">{{ $label }}</x-sidebar-link>
                        @endforeach
                    @endforeach
                </nav>

                <form method="POST" action="{{ Nav::url('logout') }}" class="mt-auto pt-4 pb-4" data-native>
                    @csrf
                    <button type="submit" class="flex w-full cursor-pointer items-center gap-3 rounded-control border border-border px-4 py-3 text-sm font-medium text-ink transition hover:bg-danger-soft hover:text-danger focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                        <i data-lucide="log-out" aria-hidden="true" class="size-5"></i>
                        Keluar
                    </button>
                </form>
            </aside>

            <div class="relative isolate flex min-w-0 flex-col">
                <x-ambient-backdrop variant="portal" />
                <header class="sticky top-0 z-30 flex h-14 shrink-0 items-center justify-between gap-2 border-b border-border bg-surface px-4 shadow-xs sm:gap-4 sm:px-6 lg:px-8">
                    {{-- Sisi Kiri: Toggle Mobile & Breadcrumb / Halaman Aktif --}}
                    <div class="flex min-w-0 items-center gap-2 sm:gap-3">
                        <button type="button"
                                x-ref="sidebarToggle"
                                x-on:click="sidebarOpen = ! sidebarOpen"
                                class="flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-control border border-border bg-surface text-ink transition hover:bg-page focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary lg:hidden"
                                aria-label="Buka menu navigasi"
                                :aria-expanded="sidebarOpen.toString()"
                                aria-controls="admin-sidebar">
                            <i data-lucide="menu" aria-hidden="true" class="size-5"></i>
                        </button>

                        <nav class="flex min-w-0 items-center gap-1.5 text-sm text-muted sm:gap-2" aria-label="Breadcrumb">
                            <a href="{{ Nav::url('admin.dashboard') }}" class="shrink-0 rounded font-medium text-muted transition hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Admin</a>
                            @php($headerCrumbs = array_values(array_filter(explode('|', trim($__env->yieldContent('breadcrumb')) ?: trim($__env->yieldContent('title'))))))
                            @foreach ($headerCrumbs as $crumb)
                                <span @class(['shrink-0 text-muted/60', 'hidden sm:inline' => ! $loop->last]) aria-hidden="true">›</span>
                                <span @class([
                                    'truncate',
                                    'hidden sm:inline' => ! $loop->last,
                                    'font-semibold text-ink' => $loop->last,
                                ]) @if ($loop->last) aria-current="page" @endif>{{ $crumb }}</span>
                            @endforeach
                        </nav>
                    </div>

                    {{-- Sisi Kanan: Aksi Pintasan, Notifikasi & Profil Admin --}}
                    <div class="flex shrink-0 items-center gap-2 sm:gap-3">
                        @if (trim($__env->yieldContent('breadcrumb-actions')))
                            <div class="hidden items-center gap-3 sm:flex">
                                <div class="flex items-center gap-2">
                                    @yield('breadcrumb-actions')
                                </div>
                                <div class="h-5 w-px bg-border" aria-hidden="true"></div>
                            </div>
                        @endif

                        <x-portal-header-actions portal="admin" :unread-notification-count="$unreadNotificationCount" :portal-user="$portalUser" />
                    </div>
                </header>

                <main id="main-content" tabindex="-1" class="flex-1 px-4 py-4 outline-none sm:px-6 sm:py-5 lg:px-10">
                    @yield('content')
                </main>
            </div>
        </div>

        <x-toast fallback-error />
        <x-splash-screen />
        @stack('dialogs')
    </body>
</html>
