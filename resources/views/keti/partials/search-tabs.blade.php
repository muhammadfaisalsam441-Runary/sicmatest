{{-- Navigasi pencarian, kalender ruang, dan jadwal kelas. --}}
@props(['active'])
<x-tabs :items="[
    ['label' => 'Sekarang', 'href' => route('keti.search'), 'active' => $active === 'sekarang'],
    ['label' => 'Booking Ruangan', 'href' => route('keti.rooms.calendar'), 'active' => $active === 'kalender'],
    ['label' => 'My Class', 'href' => route('keti.my-class'), 'active' => $active === 'my-class'],
]" />
