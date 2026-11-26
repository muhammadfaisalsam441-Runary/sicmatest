@extends('layouts.keti')

@section('title', 'Profil Pengguna')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Profil Pengguna" />
        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[1fr_340px]">
            <x-card padding="p-4 sm:p-6" class="flex flex-col gap-6">
                <div class="flex items-center gap-4">
                    <span class="flex size-14 shrink-0 items-center justify-center rounded-full bg-primary-soft text-lg font-bold text-primary">{{ $user->initials() }}</span>
                    <div class="flex min-w-0 flex-col">
                        <span class="break-words text-lg font-semibold sm:text-xl">{{ $user->name }}</span>
                        <span class="text-sm text-muted">Ketua tingkat {{ $classGroup->study_program }}</span>
                    </div>
                </div>
                <dl class="grid grid-cols-1 gap-5 text-sm sm:grid-cols-2">
                    @foreach ([['Nama lengkap', $user->name], ['NIM', $user->nim ?? '—'], ['Program studi', $classGroup->study_program], ['Kelas', $classGroup->name], ['Email', $user->email]] as [$label, $value])
                        <div class="flex min-w-0 flex-col gap-1">
                            <dt class="text-xs text-muted">{{ $label }}</dt>
                            <dd class="break-words font-medium">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>
            <div class="flex flex-col gap-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <x-stat-card label="Penggunaan hari ini" :value="$usageToday" />
                    <x-stat-card label="Ruangan favorit" :value="$favoriteCount" />
                </div>
                <x-card padding="p-4 sm:p-5" class="flex flex-col gap-2.5">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-muted">Akses cepat</h2>
                    <x-button variant="secondary" :href="route('keti.my-class')" class="min-h-11 w-full justify-start">Buka My Class</x-button>
                    <x-button variant="secondary" :href="route('keti.activity', ['cakupan' => 'saya'])" class="min-h-11 w-full justify-start">Lihat aktivitas</x-button>
                    <x-button variant="secondary" :href="route('keti.notifications')" class="min-h-11 w-full justify-start">Notifikasi</x-button>
                    <hr class="my-1 border-border">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-button type="submit" variant="danger-outline" class="min-h-11 w-full">Keluar akun</x-button>
                    </form>
                </x-card>
            </div>
        </div>
    </div>
@endsection
