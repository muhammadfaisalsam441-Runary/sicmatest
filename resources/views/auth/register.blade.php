@extends('layouts.auth')

@section('title', 'Daftar sebagai Keti')

@php
    $isDuplicate = in_array('NIM sudah terdaftar.', $errors->get('nim'), true) || in_array('Email sudah terdaftar.', $errors->get('email'), true);
@endphp

@section('content')
    <div class="flex flex-col gap-5">
        @if ($isDuplicate)
            <x-auth-heading title="Periksa data pendaftaran" subtitle="NIM atau email sudah terdaftar. Gunakan akun yang ada atau perbaiki isian." />
            <x-info-box title="Akun mungkin sudah ada" variant="danger">
                <p>Gunakan pemulihan kata sandi jika kamu sudah memiliki akun.</p>
            </x-info-box>
            <div class="grid grid-cols-2 gap-3">
                <x-button variant="secondary" href="#form-daftar">Perbaiki data</x-button>
                <x-button variant="secondary" :href="route('password.request')">Lupa kata sandi</x-button>
            </div>
        @else
            <x-auth-heading title="Daftar sebagai Keti" subtitle="Lengkapi data diri dan kelas yang kamu wakili." />
        @endif

        <form id="form-daftar" method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-4" novalidate>
            @csrf
            <x-field label="Nama lengkap" name="name">
                <x-input name="name" autocomplete="name" required />
            </x-field>
            <x-field label="NIM" name="nim">
                <x-input name="nim" inputmode="numeric" autocomplete="username" required />
            </x-field>
            <x-field label="Email" name="email">
                <x-input name="email" type="email" autocomplete="email" required />
            </x-field>
            <x-field label="Kelas yang diwakili" name="class_group_id">
                <x-select name="class_group_id" :options="$classGroups" placeholder="Pilih kelas" required />
            </x-field>
            <x-field label="Kata sandi" name="password" hint="Minimal 8 karakter.">
                <x-input name="password" type="password" autocomplete="new-password" required />
            </x-field>

            <p class="text-xs text-muted">Kelas yang dipilih akan diverifikasi admin sebelum akun dapat memesan ruang.</p>

            <x-button type="submit" class="w-full">Daftar dan verifikasi email</x-button>
            <x-button variant="secondary" :href="route('login')" class="w-full">Sudah punya akun? Masuk</x-button>
        </form>
    </div>
@endsection
