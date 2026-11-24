@use('App\Support\Nav')
@props([
    'portal' => 'admin',
    'unreadNotificationCount' => 0,
    'portalUser' => null,
    'portalClassGroup' => null,
])

@php
    $isKeti = $portal === 'keti';
    $notificationsUrl = Nav::url($isKeti ? 'keti.notifications' : 'admin.notifications');
    $profileUrl = Nav::url($isKeti ? 'keti.profile' : 'admin.profile');
    $badgeText = ! $isKeti && $unreadNotificationCount > 99 ? '99+' : (string) $unreadNotificationCount;
@endphp

{{-- Tombol Notifikasi --}}
<a href="{{ $notificationsUrl }}"
   class="relative flex size-10 items-center justify-center rounded-control border border-border bg-surface text-muted transition hover:bg-page hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
   aria-label="Notifikasi ({{ $unreadNotificationCount }} belum dibaca)"
   title="Notifikasi">
    <i data-lucide="bell" aria-hidden="true" class="size-5"></i>
    @if ($unreadNotificationCount > 0)
        <span class="absolute -top-1 -right-1 flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-bold text-white shadow-xs">
            {{ $badgeText }}
        </span>
    @endif
</a>

{{-- Profil Pengguna dengan Dropdown --}}
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
                <span class="text-[10px] leading-tight font-medium text-muted">
                    {{ $isKeti ? 'Keti • '.($portalClassGroup?->name ?? 'Kelas belum terhubung') : 'Administrator' }}
                </span>
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
             class="absolute right-0 z-40 mt-1.5 {{ $isKeti ? 'w-56' : 'w-52' }} origin-top-right overflow-hidden rounded-card border border-border bg-surface py-1.5 shadow-lg">
            <div class="border-b border-border px-3.5 py-2">
                <p class="text-xs font-bold text-ink">{{ $portalUser->name }}</p>
                <p class="truncate text-[11px] text-muted">{{ $portalUser->email }}</p>
                @if ($isKeti && $portalClassGroup)
                    <span class="mt-1 inline-block rounded-full bg-primary-soft px-2 py-0.5 text-[10px] font-semibold text-primary">
                        {{ $portalClassGroup->name }}
                    </span>
                @endif
            </div>
            <a href="{{ $profileUrl }}" class="flex min-h-11 items-center gap-2 px-3.5 py-2.5 text-xs font-medium text-ink transition hover:bg-primary-soft/60 hover:text-primary focus-visible:bg-primary-soft/60 focus-visible:text-primary focus-visible:outline-none sm:min-h-0 sm:py-2" role="menuitem">
                <i data-lucide="user" aria-hidden="true" class="size-3.5"></i>
                {{ $isKeti ? 'Profil Keti' : 'Profil Admin' }}
            </a>
            @if ($isKeti)
                <a href="{{ Nav::url('keti.activity', ['cakupan' => 'saya']) }}" class="flex min-h-11 items-center gap-2 px-3.5 py-2.5 text-xs font-medium text-ink transition hover:bg-primary-soft/60 hover:text-primary focus-visible:bg-primary-soft/60 focus-visible:text-primary focus-visible:outline-none sm:min-h-0 sm:py-2" role="menuitem">
                    <i data-lucide="history" aria-hidden="true" class="size-3.5"></i>
                    Aktivitas Saya
                </a>
            @endif
            <a href="{{ $notificationsUrl }}" class="flex min-h-11 items-center justify-between px-3.5 py-2.5 text-xs font-medium text-ink transition hover:bg-primary-soft/60 hover:text-primary focus-visible:bg-primary-soft/60 focus-visible:text-primary focus-visible:outline-none sm:min-h-0 sm:py-2" role="menuitem">
                <span class="flex items-center gap-2">
                    <i data-lucide="bell" aria-hidden="true" class="size-3.5"></i>
                    Notifikasi
                </span>
                @if ($unreadNotificationCount > 0)
                    <span class="rounded-full bg-danger-soft px-1.5 py-0.5 text-[10px] font-bold text-danger">{{ $badgeText }}</span>
                @endif
            </a>
            <div class="my-1 border-t border-border"></div>
            <form method="POST" action="{{ Nav::url('logout') }}" data-native>
                @csrf
                <button type="submit" class="flex min-h-11 w-full cursor-pointer items-center gap-2 px-3.5 py-2.5 text-left text-xs font-semibold text-danger transition hover:bg-danger-soft focus-visible:bg-danger-soft focus-visible:outline-none sm:min-h-0 sm:py-2" role="menuitem">
                    <i data-lucide="log-out" aria-hidden="true" class="size-3.5"></i>
                    Keluar
                </button>
            </form>
        </div>
    </div>
@else
    <a href="{{ $profileUrl }}" class="flex size-10 items-center justify-center rounded-control border border-border bg-surface text-primary transition hover:bg-primary-soft/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" aria-label="{{ $isKeti ? 'Profil keti' : 'Profil admin' }}">
        <i data-lucide="user" aria-hidden="true" class="size-4.5"></i>
    </a>
@endif
