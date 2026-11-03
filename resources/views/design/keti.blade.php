@extends('layouts.keti')

@section('title', 'Cari Ruangan')

@section('content')
    <div class="flex flex-col gap-8">
        <x-page-header title="Cari Ruangan" subtitle="Temukan ruangan kosong yang sesuai untuk kelasmu." />
        @include('design.components')
    </div>
@endsection
