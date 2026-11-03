@use('App\Support\Nav')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head', ['title' => trim($__env->yieldContent('title')) ?: null])
    </head>
    <body class="min-h-screen">
        <div class="grid min-h-screen grid-cols-[240px_1fr]">
            <aside class="sticky top-0 flex h-screen flex-col border-r border-border bg-surface px-4 py-7">
                <a href="{{ Nav::url('admin.dashboard') }}" class="flex flex-col gap-1 px-3">
                    <span class="text-xs font-semibold text-primary">SICMA •</span>
                    <span class="text-xl font-medium text-ink">Panel Admin</span>
                </a>

                <nav class="mt-7 flex flex-col gap-1" aria-label="Menu Panel Admin">
                    @foreach ([
                        ['Dashboard', 'layout-grid', 'admin.dashboard', 'admin.dashboard'],
                        ['Kelola Ruang', 'door-closed', 'admin.rooms.index', 'admin.rooms.*'],
                        ['Kelola Jadwal', 'calendar', 'admin.schedules.index', 'admin.schedules.*'],
                        ['Riwayat Reservasi', 'clipboard-list', 'admin.reservations.index', 'admin.reservations.*'],
                        ['Log Pemakaian', 'clock', 'admin.usage-logs.index', 'admin.usage-logs.*'],
                    ] as [$label, $icon, $routeName, $pattern])
                        <x-sidebar-link :href="Nav::url($routeName)" :icon="$icon" :active="Nav::active($pattern)">{{ $label }}</x-sidebar-link>
                    @endforeach

                    <span class="mt-4 px-3 pb-1 text-xs text-muted">Penyusunan jadwal</span>

                    @foreach ([
                        ['Data Akademik', 'users', 'admin.academic.index', 'admin.academic.*'],
                        ['Data Dosen', 'users', 'admin.lecturers.index', 'admin.lecturers.*'],
                        ['Pembagian SKS', 'chart-no-axes-column', 'admin.teaching.index', 'admin.teaching.*'],
                        ['Susun Jadwal', 'table', 'admin.scheduler.create', 'admin.scheduler.*'],
                    ] as [$label, $icon, $routeName, $pattern])
                        <x-sidebar-link :href="Nav::url($routeName)" :icon="$icon" :active="Nav::active($pattern)">{{ $label }}</x-sidebar-link>
                    @endforeach
                </nav>

                <a href="{{ Nav::url('admin.keti-accounts.index') }}"
                   @class([
                       'mt-6 rounded-control border px-4 py-3 text-center text-sm font-semibold text-primary',
                       'border-primary-soft bg-primary-soft' => Nav::active('admin.keti-accounts.*'),
                       'border-border hover:bg-primary-soft/60' => ! Nav::active('admin.keti-accounts.*'),
                   ])>Akun Keti</a>

                <form method="POST" action="{{ Nav::url('logout') }}" class="mt-auto">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-3 rounded-control border border-border px-4 py-3 text-sm font-medium text-ink hover:bg-danger-soft hover:text-danger">
                        <i data-lucide="log-out" class="size-5"></i>
                        Keluar
                    </button>
                </form>
            </aside>

            <div class="flex min-w-0 flex-col">
                <header class="flex items-start justify-between px-10 pt-4">
                    <div class="flex flex-col gap-1">
                        <span class="text-xs font-semibold text-primary">SICMA •</span>
                        <span class="text-xl font-medium text-ink">@yield('title')</span>
                    </div>

                    <div class="flex items-center gap-3">
                        <a href="{{ Nav::url('admin.notifications') }}" class="relative flex size-11 items-center justify-center rounded-control border border-border bg-surface text-primary hover:bg-primary-soft/60" aria-label="Notifikasi ({{ $unreadNotificationCount }} belum dibaca)">
                            <i data-lucide="bell" class="size-5"></i>
                            @if ($unreadNotificationCount > 0)
                                <span class="absolute -top-1.5 -right-1.5 flex size-5 items-center justify-center rounded-full bg-danger text-[11px] font-bold text-white">{{ $unreadNotificationCount }}</span>
                            @endif
                        </a>
                        <a href="{{ Nav::url('admin.profile') }}" class="flex size-11 items-center justify-center rounded-control border border-border bg-surface text-primary hover:bg-primary-soft/60" aria-label="Profil admin">
                            <i data-lucide="user" class="size-5"></i>
                        </a>
                    </div>
                </header>

                <div class="flex items-center justify-between px-10 pt-6">
                    <nav class="text-sm text-muted" aria-label="Breadcrumb">
                        Admin
                        @foreach (array_filter(explode('|', trim($__env->yieldContent('breadcrumb')) ?: trim($__env->yieldContent('title')))) as $crumb)
                            <span aria-hidden="true">›</span> {{ $crumb }}
                        @endforeach
                    </nav>
                    <div class="flex items-center gap-3">
                        @yield('breadcrumb-actions')
                    </div>
                </div>

                <main class="flex-1 px-10 py-6">
                    @yield('content')
                </main>
            </div>
        </div>

        <x-toast />
        @stack('dialogs')
    </body>
</html>
