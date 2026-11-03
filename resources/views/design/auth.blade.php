@extends('layouts.auth')

@section('title', 'Desain layout auth')

@section('content')
    <div class="flex flex-col gap-5">
        <div class="flex flex-col gap-3">
            <h2 class="text-3xl font-semibold text-ink">Masuk ke SICMA</h2>
            <p class="text-sm text-muted">Gunakan email atau NIM dan kata sandi yang terdaftar.</p>
        </div>
        <x-field label="Email atau NIM" name="identity"><x-input name="identity" /></x-field>
        <x-field label="Kata sandi" name="password"><x-input name="password" type="password" /></x-field>
        <x-button class="w-full">Masuk</x-button>
        <x-button variant="secondary" class="w-full">Lupa kata sandi?</x-button>
        <x-button variant="secondary" class="w-full" :href="route('design')">Kembali ke halaman desain</x-button>
    </div>
@endsection
