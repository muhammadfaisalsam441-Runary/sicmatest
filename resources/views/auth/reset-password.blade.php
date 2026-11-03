@extends('layouts.auth')

@section('title', 'Buat kata sandi baru')

@php
    $mismatch = in_array('Kata sandi baru dan konfirmasinya belum sama.', $errors->get('password'), true);
@endphp

@section('content')
    <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-5" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ old('email', $email) }}">

        @if ($mismatch)
            <x-auth-heading title="Kata sandi belum cocok" subtitle="Pastikan kata sandi baru dan konfirmasinya sama." danger />
        @else
            <x-auth-heading title="Buat kata sandi baru" subtitle="Gunakan kata sandi baru untuk akun SICMA yang sama." />
        @endif

        <x-field label="Kata sandi baru" name="password">
            <x-input name="password" type="password" autocomplete="new-password" autofocus required />
        </x-field>
        <x-field label="Konfirmasi kata sandi" name="password_confirmation">
            <x-input name="password_confirmation" type="password" autocomplete="new-password" required />
        </x-field>
        @error('email')<x-alert variant="danger">{{ $message }}</x-alert>@enderror

        <p class="text-xs text-muted">Gunakan kata sandi panjang yang berbeda dari kata sandi lama.</p>
        <x-button type="submit" class="w-full">Simpan kata sandi</x-button>
        <x-button variant="secondary" :href="route('login')" class="w-full">Kembali ke halaman masuk</x-button>
    </form>
@endsection
