@extends('layouts.auth')

@section('title', 'Verifikasi email')

@php
    $errorCode = session('error_code');
    $hasCodeError = $errorCode !== null && str_starts_with($errorCode, 'VERIFICATION_');
@endphp

@section('content')
    <div class="flex flex-col gap-5">
        @if ($hasCodeError)
            <x-auth-heading title="Kode belum sesuai" :subtitle="session('error')" danger />
        @else
            <x-auth-heading title="Verifikasi email" subtitle="Kode verifikasi dikirim ke {{ $email }}." />
        @endif

        <x-info-box title="Email pendaftaran">
            <p>{{ $email }} • Periksa juga folder spam.</p>
        </x-info-box>

        @if (session('error_code') === 'RESEND_TOO_SOON')
            <x-alert variant="danger">{{ session('error') }}</x-alert>
        @endif

        @if (session('dev_verification_code'))
            <x-dev-hint>Email hanya dicatat di log. Kode verifikasi: <strong class="text-ink">{{ session('dev_verification_code') }}</strong></x-dev-hint>
        @endif

        <form method="POST" action="{{ route('verification.verify') }}" class="flex flex-col gap-4" novalidate>
            @csrf
            <x-field label="Kode verifikasi" name="code">
                <x-input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" autofocus required class="tracking-[0.3em]" />
            </x-field>
            <x-button type="submit" class="w-full">Verifikasi email</x-button>
        </form>

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-button type="submit" variant="secondary" class="w-full">Kirim ulang kode</x-button>
        </form>
        <x-button variant="secondary" :href="route('verification.email.edit')" class="w-full">Ubah email pendaftaran</x-button>
    </div>
@endsection
