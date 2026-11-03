@extends('layouts.auth')

@section('title', 'Kata sandi diperbarui')

@section('content')
    <div class="flex flex-col gap-5">
        <x-auth-heading title="Kata sandi diperbarui" subtitle="Silakan masuk kembali menggunakan kata sandi baru." />
        <x-info-box title="Pemulihan berhasil" variant="success">
            <p>Kata sandi baru berlaku untuk web dan APK.</p>
        </x-info-box>
        <x-button :href="route('login')" class="w-full">Masuk kembali</x-button>
    </div>
@endsection
