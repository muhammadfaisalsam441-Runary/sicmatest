@use('App\Support\Nav')
@use('App\Support\Wita')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head', ['title' => trim($__env->yieldContent('title')) ?: null])
    </head>
    <body class="min-h-screen" data-portal="keti" data-portal-user="{{ auth()->id() }}" data-login-url="{{ route('login') }}" data-validation-errors="{{ $errors->any() ? 'true' : 'false' }}">
        <div class="grid min-h-screen grid-cols-[212px_1fr]">
            <aside class="flex flex-col gap-10 border-r border-border bg-surface px-5 py-9">
                <div class="flex flex-col gap-6">
                    <a href="{{ Nav::url('keti.search') }}" class="text-[28px] font-bold tracking-tight text-primary">SICMA</a>
                    <span class="text-xs text-muted">Ruang kelas JTIK</span>
                </div>

                <nav class="flex flex-col gap-3" aria-label="Menu Portal Keti">
                    @foreach ([['Cari Ruangan', 'keti.search', ['keti.search', 'keti.my-class', 'keti.rooms.calendar']], ['Ruangan JTIK', 'keti.rooms.index', ['keti.rooms.index', 'keti.rooms.show']], ['Favorit', 'keti.favorites.index', ['keti.favorites.*']], ['Aktivitas', 'keti.activity', ['keti.activity', 'keti.reservations.*']]] as [$label, $routeName, $patterns])
                        <a href="{{ Nav::url($routeName) }}"
                            @class([
                                'rounded-control border px-4 py-3 text-center text-sm font-semibold text-primary',
                                'border-primary-soft bg-primary-soft' => Nav::active(...$patterns),
                                'border-border bg-surface hover:bg-primary-soft/60' => ! Nav::active(...$patterns),
                            ])
                            @if (Nav::active(...$patterns)) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </nav>
            </aside>

            <div class="flex min-w-0 flex-col">
                <header class="flex items-center justify-between border-b border-border bg-surface px-8 py-4">
                    <div class="flex flex-col gap-1">
                        <span class="text-xs text-muted">SICMA / Portal Keti</span>
                        <span class="text-lg font-semibold text-ink">@yield('title')</span>
                    </div>

                    <div class="flex items-center gap-4">
                        <a href="{{ Nav::url('keti.notifications') }}" class="rounded-control border border-border px-7 py-3 text-sm font-semibold text-primary hover:bg-primary-soft/60">
                            Notifikasi • {{ $unreadNotificationCount }}
                        </a>

                        @if ($portalUser)
                            <div x-data="{ open: false }" class="relative">
                                <button type="button" x-on:click="open = ! open" x-on:click.outside="open = false"
                                        class="flex items-center gap-3 rounded-control border border-border px-3 py-2 text-left hover:bg-primary-soft/40"
                                        aria-haspopup="menu" :aria-expanded="open.toString()">
                                    <span class="flex size-9 items-center justify-center rounded-full bg-primary-soft text-xs font-bold text-primary">{{ $portalUser->initials() }}</span>
                                    <span class="flex flex-col">
                                        <span class="text-sm font-semibold text-ink">{{ $portalUser->name }}</span>
                                        <span class="text-xs text-muted">Keti • {{ $portalClassGroup?->name ?? 'Kelas belum terhubung' }}</span>
                                    </span>
                                    <i data-lucide="chevron-down" class="size-4 text-muted"></i>
                                </button>

                                <div x-show="open" x-cloak x-transition.opacity role="menu"
                                    class="absolute right-0 z-40 mt-2 w-56 overflow-hidden rounded-card border border-border bg-surface py-2 shadow-lg">
                                    <a href="{{ Nav::url('keti.profile') }}" class="block px-4 py-2 text-sm text-ink hover:bg-primary-soft/60" role="menuitem">Profil</a>
                                    <a href="{{ Nav::url('keti.activity', ['cakupan' => 'saya']) }}" class="block px-4 py-2 text-sm text-ink hover:bg-primary-soft/60" role="menuitem">Aktivitas saya</a>
                                    <form method="POST" action="{{ Nav::url('logout') }}" data-native>
                                        @csrf
                                        <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-danger hover:bg-danger-soft" role="menuitem">Keluar</button>
                                    </form>
                                </div>
                            </div>
                        @endif
                    </div>
                </header>

                <main class="flex-1 px-10 py-10">
                    @yield('content')
                </main>

                <footer class="flex items-center gap-24 px-10 pb-6 text-xs text-muted">
                    <span>JTIK • SICMA</span>
                </footer>
            </div>
        </div>

        <x-toast />
        @stack('dialogs')
    </body>
</html>
