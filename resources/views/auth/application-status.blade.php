@use('App\Enums\KetiApplicationStatus')
@extends('layouts.auth')

@section('title', 'Status pengajuan')

@section('content')
    <div class="flex flex-col gap-5">
        @if ($isApproved)
            <x-auth-heading title="Akun Keti sudah aktif" subtitle="Pengajuanmu disetujui. Akun yang sama bisa digunakan di web dan APK SICMA." />
            <x-info-box title="Kelas terhubung" variant="success">
                <p>{{ $activeClassGroup->name }} • {{ $user->name }}</p>
            </x-info-box>
            <x-button :href="route('keti.search')" class="w-full">Masuk ke SICMA</x-button>
        @elseif ($application?->status === KetiApplicationStatus::Pending)
            <x-auth-heading title="Pengajuan sedang diperiksa" subtitle="Email sudah terverifikasi. Admin akan memeriksa hubungan akun dengan kelasmu." />
            <x-info-box title="Menunggu persetujuan">
                <p>{{ $user->name }} • {{ $application->classGroup->name }}</p>
                <p>Kamu tetap bisa melihat ruangan melalui halaman publik.</p>
            </x-info-box>
            <x-button :href="route('keti.application')" class="w-full">Periksa status pengajuan</x-button>
            <x-button variant="secondary" :href="\App\Support\Nav::url('public.rooms')" class="w-full">Lihat ruangan publik</x-button>
        @elseif (in_array($application?->status, [KetiApplicationStatus::NeedsRevision, KetiApplicationStatus::Rejected], true))
            <x-auth-heading :title="$application->status === KetiApplicationStatus::Rejected ? 'Pengajuan ditolak' : 'Pengajuan perlu diperbaiki'"
                            subtitle="Periksa alasan penolakan, lalu perbaiki data pengajuanmu." />
            <x-info-box title="Alasan pengajuan belum disetujui" variant="danger">
                <p>{{ $application->review_note ?? 'Admin belum menuliskan alasan.' }}</p>
            </x-info-box>

            <div x-data="{ editing: {{ $errors->any() ? 'true' : 'false' }} }" class="flex flex-col gap-4">
                <x-button x-show="! editing" x-on:click="editing = true" class="w-full">Perbaiki dan ajukan ulang</x-button>
                <form x-show="editing" x-cloak method="POST" action="{{ route('keti.application.resubmit') }}" class="flex flex-col gap-4">
                    @csrf
                    @method('PUT')
                    <x-field label="Nama lengkap" name="name">
                        <x-input name="name" :value="old('name', auth()->user()->name)" required maxlength="255" />
                    </x-field>
                    <x-field label="NIM" name="nim">
                        <x-input name="nim" :value="old('nim', auth()->user()->nim)" required maxlength="20" />
                    </x-field>
                    <x-field label="Kelas yang diwakili" name="class_group_id">
                        <x-select name="class_group_id" :options="$classGroups" :value="old('class_group_id', $application->class_group_id)" required />
                    </x-field>
                    <x-button type="submit" class="w-full">Ajukan ulang</x-button>
                </form>
            </div>
            <x-button variant="secondary" :href="route('home')" class="w-full">Kembali ke beranda</x-button>
        @else
            <x-auth-heading title="Akun belum terhubung ke kelas" subtitle="Hubungi admin JTIK untuk menghubungkan akunmu ke kelas yang diwakili." />
            <x-button variant="secondary" :href="route('home')" class="w-full">Kembali ke beranda</x-button>
        @endif

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="w-full text-center text-sm font-medium text-muted hover:text-danger">Keluar dari akun</button>
        </form>
    </div>
@endsection
