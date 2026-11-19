@extends('layouts.auth')

@section('title', 'Lupa kata sandi')

@section('content')
    <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-5" novalidate>
        @csrf
        <x-auth-heading title="Lupa kata sandi" subtitle="Masukkan email terdaftar untuk menerima tautan atur ulang." />
        <x-field label="Email terdaftar" name="identity" hint="Kamu juga bisa memakai NIM.">
            <x-input name="identity" autocomplete="username" autocorrect="off" autocapitalize="none" spellcheck="false" enterkeyhint="send" autofocus required />
        </x-field>
        <x-button type="submit" class="min-h-11 w-full">Kirim tautan atur ulang</x-button>
        <x-button variant="secondary" :href="route('login')" class="min-h-11 w-full">Kembali ke halaman masuk</x-button>
    </form>
@endsection
