{{-- Navigasi pencarian ruangan dan kalender booking. --}}
@props(['active'])
<x-tabs :items="[
    ['label' => 'Cari Ruangan', 'href' => route('keti.search'), 'active' => in_array($active, ['sekarang', 'cari-ruangan'], true)],
    ['label' => 'Booking Ruangan', 'href' => route('keti.rooms.calendar'), 'active' => $active === 'kalender'],
]" />
