@use('App\Support\Wita')
@extends('layouts.keti')

@section('title', 'Aktivitas ruangan')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Aktivitas ruangan" subtitle="Siapa saja yang memesan dan memakai ruangan." />

        <div class="flex items-center justify-between">
            <x-tabs :items="[
                ['label' => 'Semua pengguna', 'href' => route('keti.activity', ['tanggal' => $date->toDateString()]), 'active' => $scope === 'semua'],
                ['label' => 'Saya • '.$myCount, 'href' => route('keti.activity', ['cakupan' => 'saya', 'tanggal' => $date->toDateString()]), 'active' => $scope === 'saya'],
            ]" />
            <form method="GET" action="{{ route('keti.activity') }}" class="flex items-center gap-2">
                <input type="hidden" name="cakupan" value="{{ $scope }}">
                <span class="text-sm text-muted">{{ $date->isToday() ? 'Hari ini • ' : '' }}{{ Wita::longDate($date) }}</span>
                <x-input type="date" name="tanggal" :value="$date->toDateString()" class="w-44" aria-label="Tanggal" onchange="this.form.submit()" />
            </form>
        </div>

        @if ($reservations->isEmpty())
            <x-empty-state title="Belum ada aktivitas" message="Belum ada catatan pemakaian pada tanggal ini." icon="clipboard-list">
                <x-button variant="soft" :href="route('keti.search')">Cari ruangan</x-button>
            </x-empty-state>
        @else
            <x-card class="flex flex-col gap-3">
                <h2 class="text-lg font-semibold">Riwayat pemakaian ruang</h2>
                <x-data-table :headers="['Pengguna', 'Ruangan', 'Kegiatan / kelas', 'Waktu', 'Status']" class="border-0">
                    @foreach ($reservations as $item)
                        @php
                            $canView = $item->class_group_id === $classGroup->id || ($item->sessionReschedule && in_array((string) $classGroup->id, explode(',', $item->sessionReschedule->participant_key), true));
                            $detailUrl = $canView ? route('keti.reservations.show', $item) : null;
                        @endphp
                        <tr @class(['hover:bg-subtle/50 transition-colors', 'cursor-pointer' => $detailUrl !== null])
                            @if ($detailUrl) onclick="window.location='{{ $detailUrl }}'" @endif>
                            <td class="px-5 py-3">
                                @if (config('sicma.privacy.show_booker_name_to_keti') || $item->class_group_id === $classGroup->id)
                                    {{ $item->creator->name }}
                                @else
                                    Keti {{ $item->classGroup->name }}
                                @endif
                            </td>
                            <td class="px-5 py-3 font-medium">
                                @if ($detailUrl)
                                    <a href="{{ $detailUrl }}" class="hover:text-primary hover:underline">{{ $item->room->name }}</a>
                                @else
                                    {{ $item->room->name }}
                                @endif
                            </td>
                            <td class="px-5 py-3">{{ $item->purpose }} • {{ $item->classGroup->name }}</td>
                            <td class="px-5 py-3">
                                @if ($item->status->value === 'in_use')
                                    Mulai {{ Wita::clock($item->actual_start_at, false) }} • Selesai —
                                @else
                                    {{ Wita::range($item->actual_start_at ?? $item->start_at, $item->actual_end_at ?? $item->end_at) }}
                                @endif
                            </td>
                            <td class="px-5 py-3"><x-status-badge :variant="$item->status->badgeVariant()">{{ $item->status->label() }}</x-status-badge></td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-card>
        @endif
    </div>
@endsection
