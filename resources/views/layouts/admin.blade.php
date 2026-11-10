@use('App\Support\Nav')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head', ['title' => trim($__env->yieldContent('title')) ?: null])
    </head>
    <body class="min-h-screen" data-portal="admin" data-portal-user="{{ auth()->id() }}" data-login-url="{{ route('login') }}" data-validation-errors="{{ $errors->any() ? 'true' : 'false' }}">
        <div class="grid min-h-screen grid-cols-[240px_1fr]">
            <aside class="sticky top-0 flex h-screen flex-col overflow-y-auto border-r border-border bg-surface px-4 py-7">
                <a href="{{ Nav::url('admin.dashboard') }}" class="flex flex-col gap-1 px-3">
                    <span class="text-xs font-semibold text-primary">SICMA •</span>
                    <span class="text-xl font-medium text-ink">Panel Admin</span>
                </a>

                <nav class="mt-7 flex flex-col gap-1" aria-label="Menu Panel Admin">
                    @foreach ([
                        ['Dashboard', 'layout-grid', 'admin.dashboard', 'admin.dashboard'],
                        ['Kelola Ruang', 'door-closed', 'admin.rooms.index', 'admin.rooms.*'],
                        ['Riwayat Reservasi', 'clipboard-list', 'admin.reservations.index', 'admin.reservations.*'],
                        ['Log Pemakaian', 'clock', 'admin.usage-logs.index', 'admin.usage-logs.*'],
                    ] as [$label, $icon, $routeName, $pattern])
                        <x-sidebar-link :href="Nav::url($routeName)" :icon="$icon" :active="Nav::active($pattern)">{{ $label }}</x-sidebar-link>
                    @endforeach

                    <span class="mt-4 px-3 pb-1 text-xs text-muted">Penyusunan jadwal</span>

                    @foreach ([
                        ['Data Akademik', 'book-open', 'admin.academic.index', 'admin.academic.*'],
                        ['Data Dosen', 'user', 'admin.lecturers.index', 'admin.lecturers.*'],
                        ['Pembagian SKS', 'chart-no-axes-column', 'admin.teaching.index', 'admin.teaching.*'],
                        ['Penyusunan & Draf', 'calendar-days', 'admin.scheduler.create', 'admin.scheduler.*'],
                        ['Jadwal Resmi', 'calendar-check', 'admin.schedules.index', 'admin.schedules.*'],
                    ] as [$label, $icon, $routeName, $pattern])
                        <x-sidebar-link :href="Nav::url($routeName)" :icon="$icon" :active="Nav::active($pattern) || ($routeName === 'admin.scheduler.create' && Nav::active('admin.drafts.show', 'admin.drafts.inspect*', 'admin.imports.*')) || ($routeName === 'admin.schedules.index' && Nav::active('admin.drafts.published'))">{{ $label }}</x-sidebar-link>
                    @endforeach
                </nav>

                <a href="{{ Nav::url('admin.keti-accounts.index') }}"
                   @class([
                       'mt-6 flex items-center justify-center gap-3 rounded-control border px-4 py-3 text-center text-sm font-semibold text-primary',
                       'border-primary-soft bg-primary-soft' => Nav::active('admin.keti-accounts.*'),
                       'border-border hover:bg-primary-soft/60' => ! Nav::active('admin.keti-accounts.*'),
                   ])><i data-lucide="users" aria-hidden="true" class="size-5 shrink-0"></i><span>Akun Keti</span></a>

                <form method="POST" action="{{ Nav::url('logout') }}" class="mt-auto" data-native>
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-3 rounded-control border border-border px-4 py-3 text-sm font-medium text-ink hover:bg-danger-soft hover:text-danger">
                        <i data-lucide="log-out" class="size-5"></i>
                        Keluar
                    </button>
                </form>
            </aside>

            <div class="flex min-w-0 flex-col">
                <header class="sticky top-0 z-30 flex h-16 shrink-0 items-center justify-between border-b border-border bg-surface px-8 shadow-xs">
                    {{-- Sisi Kiri: Breadcrumb / Halaman Aktif --}}
                    <div class="flex items-center gap-3">
                        <nav class="flex items-center gap-2 text-sm text-muted" aria-label="Breadcrumb">
                            <a href="{{ Nav::url('admin.dashboard') }}" class="font-medium text-muted hover:text-primary transition">Admin</a>
                            @foreach (array_filter(explode('|', trim($__env->yieldContent('breadcrumb')) ?: trim($__env->yieldContent('title')))) as $crumb)
                                <span class="text-border" aria-hidden="true">›</span>
                                <span class="font-semibold text-ink">{{ $crumb }}</span>
                            @endforeach
                        </nav>
                    </div>

                    {{-- Sisi Kanan: Aksi Pintasan, Notifikasi & Profil Admin --}}
                    <div class="flex items-center gap-3">
                        @if (trim($__env->yieldContent('breadcrumb-actions')))
                            <div class="hidden items-center gap-2 sm:flex">
                                @yield('breadcrumb-actions')
                            </div>
                            <div class="h-5 w-px bg-border"></div>
                        @endif

                        {{-- Tombol Notifikasi --}}
                        <a href="{{ Nav::url('admin.notifications') }}"
                           class="relative flex size-10 items-center justify-center rounded-control border border-border bg-surface text-muted hover:text-primary hover:bg-page transition"
                           aria-label="Notifikasi ({{ $unreadNotificationCount }} belum dibaca)"
                           title="Notifikasi">
                            <i data-lucide="bell" class="size-5"></i>
                            @if ($unreadNotificationCount > 0)
                                <span class="absolute -top-1 -right-1 flex size-4.5 items-center justify-center rounded-full bg-danger text-[10px] font-bold text-white shadow-xs">
                                    {{ $unreadNotificationCount }}
                                </span>
                            @endif
                        </a>

                        {{-- Profil Pengguna Admin dengan Dropdown --}}
                        @if ($portalUser)
                            <div x-data="{ open: false }" class="relative">
                                <button type="button"
                                        x-on:click="open = ! open"
                                        x-on:click.outside="open = false"
                                        class="flex items-center gap-2.5 rounded-control border border-border bg-surface px-2.5 py-1.5 text-left hover:bg-page transition cursor-pointer"
                                        aria-haspopup="menu"
                                        :aria-expanded="open.toString()">
                                    <span class="flex size-8 items-center justify-center rounded-full bg-primary-soft text-xs font-bold text-primary">
                                        {{ $portalUser->initials() }}
                                    </span>
                                    <span class="hidden flex-col sm:flex">
                                        <span class="text-xs font-bold text-ink leading-tight">{{ $portalUser->name }}</span>
                                        <span class="text-[10px] text-muted leading-tight font-medium">Administrator</span>
                                    </span>
                                    <i data-lucide="chevron-down" class="size-3.5 text-muted transition" :class="{ 'rotate-180': open }"></i>
                                </button>

                                <div x-show="open"
                                     x-cloak
                                     x-transition:enter="transition ease-out duration-100"
                                     x-transition:enter-start="opacity-0 scale-95"
                                     x-transition:enter-end="opacity-100 scale-100"
                                     x-transition:leave="transition ease-in duration-75"
                                     x-transition:leave-start="opacity-100 scale-100"
                                     x-transition:leave-end="opacity-0 scale-95"
                                     role="menu"
                                     class="absolute right-0 z-40 mt-1.5 w-52 origin-top-right overflow-hidden rounded-card border border-border bg-surface py-1.5 shadow-lg">
                                    <div class="border-b border-border px-3.5 py-2">
                                        <p class="text-xs font-bold text-ink">{{ $portalUser->name }}</p>
                                        <p class="truncate text-[11px] text-muted">{{ $portalUser->email }}</p>
                                    </div>
                                    <a href="{{ Nav::url('admin.profile') }}" class="flex items-center gap-2 px-3.5 py-2 text-xs font-medium text-ink hover:bg-primary-soft/60 hover:text-primary transition" role="menuitem">
                                        <i data-lucide="user" class="size-3.5"></i>
                                        Profil Admin
                                    </a>
                                    <a href="{{ Nav::url('admin.notifications') }}" class="flex items-center justify-between px-3.5 py-2 text-xs font-medium text-ink hover:bg-primary-soft/60 hover:text-primary transition" role="menuitem">
                                        <span class="flex items-center gap-2">
                                            <i data-lucide="bell" class="size-3.5"></i>
                                            Notifikasi
                                        </span>
                                        @if ($unreadNotificationCount > 0)
                                            <span class="rounded-full bg-danger-soft px-1.5 py-0.5 text-[10px] font-bold text-danger">{{ $unreadNotificationCount }}</span>
                                        @endif
                                    </a>
                                    <div class="my-1 border-t border-border"></div>
                                    <form method="POST" action="{{ Nav::url('logout') }}" data-native>
                                        @csrf
                                        <button type="submit" class="flex w-full items-center gap-2 px-3.5 py-2 text-left text-xs font-semibold text-danger hover:bg-danger-soft transition cursor-pointer" role="menuitem">
                                            <i data-lucide="log-out" class="size-3.5"></i>
                                            Keluar
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @else
                            <a href="{{ Nav::url('admin.profile') }}" class="flex size-10 items-center justify-center rounded-control border border-border bg-surface text-primary hover:bg-primary-soft/60" aria-label="Profil admin">
                                <i data-lucide="user" class="size-4.5"></i>
                            </a>
                        @endif
                    </div>
                </header>

                <main class="flex-1 px-8 py-6 sm:px-10">
                    @if (session('error'))
                        <x-alert variant="danger" class="mb-6">{{ session('error') }}</x-alert>
                    @endif
                    @yield('content')
                </main>
            </div>
        </div>

        <x-toast />
        @stack('dialogs')
    </body>
</html>
