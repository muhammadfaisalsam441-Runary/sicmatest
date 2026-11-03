{{-- Tab Sekarang · My Class · Filter di halaman Cari Ruangan & My Class. --}}
@props(['active'])
<x-tabs :items="[
    ['label' => 'Sekarang', 'href' => route('keti.search'), 'active' => $active === 'sekarang'],
    ['label' => 'My Class', 'href' => route('keti.my-class'), 'active' => $active === 'my-class'],
    ['label' => 'Filter', 'href' => route('keti.search', [...request()->except('tab'), 'tab' => 'filter']), 'active' => $active === 'filter'],
]" />
