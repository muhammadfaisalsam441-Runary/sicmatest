@extends('errors.layout')

@section('code', '403')
@section('icon', 'lock')
@section('title', 'Akses ditolak')
@section('message', 'Akunmu tidak punya izin untuk membuka halaman ini. Portal Keti hanya untuk Keti yang disetujui, dan Panel Admin hanya untuk admin.')
@section('actions')
    <a href="{{ url('/masuk') }}" class="inline-flex items-center justify-center rounded-control bg-primary px-5 py-3 text-sm font-semibold text-white hover:bg-primary/90">Masuk dengan akun lain</a>
@endsection
