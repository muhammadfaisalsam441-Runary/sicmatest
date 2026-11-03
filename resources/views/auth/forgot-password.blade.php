@extends('layouts.auth')

@section('title', 'Lupa kata sandi')

@section('content')
    <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-5" novalidate>
        @csrf
        <x-auth-heading title="Lupa kata sandi" subtitle="Masukkan email terdaftar untuk menerima tautan atur ulang." />
        <x-field label="Email terdaftar" name="identity" hint="Kamu juga bisa memakai NIM.">
            <x-input name="identity" autocomplete="username" autofocus required />
        </x-field>
        <x-button type="submit" class="w-full">Kirim tautan atur ulang</x-button>
        <x-button variant="secondary" :href="route('login')" class="w-full">Kembali ke halaman masuk</x-button>
    </form>
@endsection
