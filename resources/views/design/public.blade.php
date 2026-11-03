@extends('layouts.public')

@section('title', 'Desain komponen')

@section('content')
    <div class="flex flex-col gap-8">
        <x-page-header title="Halaman desain SICMA" subtitle="Hanya tersedia di lingkungan lokal. Bandingkan dengan docs/design/SICMA_JTIK.pdf.">
            <x-slot:actions>
                <x-button variant="secondary" href="{{ route('design.auth') }}">Layout auth</x-button>
                <x-button variant="secondary" href="{{ route('design.keti') }}">Layout Keti</x-button>
                <x-button variant="secondary" href="{{ route('design.admin') }}">Layout admin</x-button>
            </x-slot:actions>
        </x-page-header>
        @include('design.components')
    </div>
@endsection
