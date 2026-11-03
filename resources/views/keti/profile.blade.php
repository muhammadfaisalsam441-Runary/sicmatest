@extends('layouts.keti')

@section('title', 'Profil Pengguna')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Profil Pengguna" subtitle="Identitas untuk pemesanan dan riwayat ruangan." />
        <div class="grid grid-cols-[1fr_340px] items-start gap-6">
            <x-card class="flex flex-col gap-6">
                <div class="flex items-center gap-4">
                    <span class="flex size-14 items-center justify-center rounded-full bg-primary-soft text-lg font-bold text-primary">{{ $user->initials() }}</span>
                    <div class="flex flex-col">
                        <span class="text-xl font-semibold">{{ $user->name }}</span>
                        <span class="text-sm text-muted">Ketua tingkat • {{ $classGroup->study_program }}</span>
                    </div>
                </div>
                <dl class="grid grid-cols-2 gap-5 text-sm">
                    @foreach ([['Nama lengkap', $user->name], ['NIM', $user->nim ?? '—'], ['Program studi', $classGroup->study_program], ['Kelas', $classGroup->name], ['Email', $user->email]] as [$label, $value])
                        <div class="flex flex-col gap-1">
                            <dt class="text-xs text-muted">{{ $label }}</dt>
                            <dd class="font-medium">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>
            <div class="flex flex-col gap-4">
                <div class="grid grid-cols-2 gap-3">
                    <x-stat-card label="Penggunaan hari ini" :value="$usageToday" />
                    <x-stat-card label="Ruangan favorit" :value="$favoriteCount" />
                </div>
                <x-button variant="secondary" :href="route('keti.my-class')">Buka My Class</x-button>
                <x-button variant="secondary" :href="route('keti.activity', ['cakupan' => 'saya'])">Lihat aktivitas</x-button>
                <x-button variant="secondary" :href="route('keti.notifications')">Notifikasi</x-button>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-button type="submit" variant="danger-outline" class="w-full">Keluar akun</x-button>
                </form>
            </div>
        </div>
    </div>
@endsection
