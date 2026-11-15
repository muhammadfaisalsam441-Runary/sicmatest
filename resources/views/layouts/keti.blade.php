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
                        <a href="{{ Nav::url('keti.search') }}" class="rounded-control text-[28px] font-bold tracking-tight text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">SICMA</a>
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

                        {{-- Tombol Notifikasi --}}
                        <a href="{{ Nav::url('keti.notifications') }}"
                           class="relative flex size-10 items-center justify-center rounded-control border border-border bg-surface text-muted transition hover:bg-page hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                           aria-label="Notifikasi ({{ $unreadNotificationCount }} belum dibaca)"
                           title="Notifikasi">
                            <i data-lucide="bell" aria-hidden="true" class="size-5"></i>
                            @if ($unreadNotificationCount > 0)
                                <span class="absolute -top-1 -right-1 flex size-4.5 items-center justify-center rounded-full bg-danger text-[10px] font-bold text-white shadow-xs">
                                    {{ $unreadNotificationCount }}
                                </span>
                            @endif
                        </a>

                        {{-- Profil Pengguna Keti dengan Dropdown --}}
                        @if ($portalUser)
                            <div x-data="{
                                     open: false,
                                     close() {
                                         if (! this.open) return;
                                         this.open = false;
                                         this.$nextTick(() => this.$refs.profileTrigger?.focus());
                                     },
                                     moveFocus(step) {
                                         const items = Array.from(this.$refs.profileMenu?.querySelectorAll('[role=menuitem]') || []);
                                         if (! items.length) return;
                                         const idx = items.indexOf(document.activeElement);
                                         const next = idx === -1 ? (step > 0 ? 0 : items.length - 1) : (idx + step + items.length) % items.length;
                                         items[next].focus();
                                     }
                                 }"
                                 x-on:keydown.escape.window="close()"
                                 class="relative">
                                <button type="button"
                                        x-ref="profileTrigger"
                                        x-on:click="open = ! open"
                                        x-on:click.outside="open = false"
                                        x-on:keydown.arrow-down.prevent="open = true; $nextTick(() => moveFocus(1))"
                                        x-on:keydown.arrow-up.prevent="open = true; $nextTick(() => moveFocus(-1))"
                                        class="flex cursor-pointer items-center gap-2.5 rounded-control border border-border bg-surface px-2.5 py-1.5 text-left transition hover:bg-page focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                        aria-haspopup="menu"
                                        :aria-expanded="open.toString()">
                                    <span class="flex size-8 items-center justify-center rounded-full bg-primary-soft text-xs font-bold text-primary">
                                        {{ $portalUser->initials() }}
                                    </span>
                                    <span class="hidden flex-col sm:flex">
                                        <span class="text-xs leading-tight font-bold text-ink">{{ $portalUser->name }}</span>
                                        <span class="text-[10px] leading-tight font-medium text-muted">Keti • {{ $portalClassGroup?->name ?? 'Kelas belum terhubung' }}</span>
                                    </span>
                                    <i data-lucide="chevron-down" aria-hidden="true" class="size-3.5 text-muted transition" :class="{ 'rotate-180': open }"></i>
                                </button>

                                <div x-show="open"
                                     x-ref="profileMenu"
                                     x-cloak
                                     x-on:keydown.arrow-down.prevent="moveFocus(1)"
                                     x-on:keydown.arrow-up.prevent="moveFocus(-1)"
                                     x-transition:enter="transition ease-out duration-100"
                                     x-transition:enter-start="opacity-0 scale-95"
                                     x-transition:enter-end="opacity-100 scale-100"
                                     x-transition:leave="transition ease-in duration-75"
                                     x-transition:leave-start="opacity-100 scale-100"
                                     x-transition:leave-end="opacity-0 scale-95"
                                     role="menu"
                                     class="absolute right-0 z-40 mt-1.5 w-56 origin-top-right overflow-hidden rounded-card border border-border bg-surface py-1.5 shadow-lg">
                                    <div class="border-b border-border px-3.5 py-2">
                                        <p class="text-xs font-bold text-ink">{{ $portalUser->name }}</p>
                                        <p class="truncate text-[11px] text-muted">{{ $portalUser->email }}</p>
                                        @if ($portalClassGroup)
                                            <span class="mt-1 inline-block rounded-full bg-primary-soft px-2 py-0.5 text-[10px] font-semibold text-primary">
                                                {{ $portalClassGroup->name }}
                                            </span>
                                        @endif
                                    </div>
                                    <a href="{{ Nav::url('keti.profile') }}" class="flex items-center gap-2 px-3.5 py-2 text-xs font-medium text-ink transition hover:bg-primary-soft/60 hover:text-primary focus-visible:bg-primary-soft/60 focus-visible:text-primary focus-visible:outline-none" role="menuitem">
                                        <i data-lucide="user" aria-hidden="true" class="size-3.5"></i>
                                        Profil Keti
                                    </a>
                                    <a href="{{ Nav::url('keti.activity', ['cakupan' => 'saya']) }}" class="flex items-center gap-2 px-3.5 py-2 text-xs font-medium text-ink transition hover:bg-primary-soft/60 hover:text-primary focus-visible:bg-primary-soft/60 focus-visible:text-primary focus-visible:outline-none" role="menuitem">
                                        <i data-lucide="history" aria-hidden="true" class="size-3.5"></i>
                                        Aktivitas Saya
                                    </a>
                                    <a href="{{ Nav::url('keti.notifications') }}" class="flex items-center justify-between px-3.5 py-2 text-xs font-medium text-ink transition hover:bg-primary-soft/60 hover:text-primary focus-visible:bg-primary-soft/60 focus-visible:text-primary focus-visible:outline-none" role="menuitem">
                                        <span class="flex items-center gap-2">
                                            <i data-lucide="bell" aria-hidden="true" class="size-3.5"></i>
                                            Notifikasi
                                        </span>
                                        @if ($unreadNotificationCount > 0)
                                            <span class="rounded-full bg-danger-soft px-1.5 py-0.5 text-[10px] font-bold text-danger">{{ $unreadNotificationCount }}</span>
                                        @endif
                                    </a>
                                    <div class="my-1 border-t border-border"></div>
                                    <form method="POST" action="{{ Nav::url('logout') }}" data-native>
                                        @csrf
                                        <button type="submit" class="flex w-full cursor-pointer items-center gap-2 px-3.5 py-2 text-left text-xs font-semibold text-danger transition hover:bg-danger-soft focus-visible:bg-danger-soft focus-visible:outline-none" role="menuitem">
                                            <i data-lucide="log-out" aria-hidden="true" class="size-3.5"></i>
                                            Keluar
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @else
                            <a href="{{ Nav::url('keti.profile') }}" class="flex size-10 items-center justify-center rounded-control border border-border bg-surface text-primary transition hover:bg-primary-soft/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" aria-label="Profil keti">
                                <i data-lucide="user" aria-hidden="true" class="size-4.5"></i>
                            </a>
                        @endif
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
        @stack('dialogs')
    </body>
</html>
