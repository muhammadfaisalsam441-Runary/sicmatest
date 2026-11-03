@extends('layouts.auth')

@section('title', 'Ubah email pendaftaran')

@section('content')
    <form method="POST" action="{{ route('verification.email.update') }}" class="flex flex-col gap-5" novalidate>
        @csrf
        @method('PUT')
        <x-auth-heading title="Ubah email pendaftaran" subtitle="Kode verifikasi baru akan dikirim ke email yang kamu masukkan." />
        <x-info-box title="Email saat ini"><p>{{ $email }}</p></x-info-box>
        <x-field label="Email baru" name="email">
            <x-input name="email" type="email" autocomplete="email" autofocus required />
        </x-field>
        <x-button type="submit" class="w-full">Simpan dan kirim kode</x-button>
        <x-button variant="secondary" :href="route('verification.notice')" class="w-full">Kembali ke verifikasi</x-button>
    </form>
@endsection
