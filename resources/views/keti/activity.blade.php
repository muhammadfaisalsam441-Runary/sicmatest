@use('App\Support\Wita')
@extends('layouts.keti')

@section('title', 'Aktivitas ruangan')

@section('content')
    <div class="flex flex-col gap-4 sm:gap-6">
        <x-page-header title="Aktivitas ruangan" />

        <div class="flex flex-col gap-2.5 sm:flex-row sm:items-center sm:justify-between">
            <x-tabs :items="[
                ['label' => 'Semua pengguna', 'href' => route('keti.activity', ['tanggal' => $date->toDateString()]), 'active' => $scope === 'semua'],
                ['label' => 'Saya', 'count' => $myCount, 'href' => route('keti.activity', ['cakupan' => 'saya', 'tanggal' => $date->toDateString()]), 'active' => $scope === 'saya'],
            ]" scrollable />
            <search>
                <form method="GET" action="{{ route('keti.activity') }}" class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <input type="hidden" name="cakupan" value="{{ $scope }}">
                    <span class="text-xs text-muted sm:text-sm">{{ $date->isToday() ? 'Hari ini, ' : '' }}{{ Wita::longDate($date) }}</span>
                    <x-input type="date" name="tanggal" :value="$date->toDateString()" class="w-full py-2 sm:w-44 sm:py-3" aria-label="Tanggal" onchange="this.form.requestSubmit()" />
                </form>
            </search>
        </div>

        @if ($reservations->isEmpty())
            <x-empty-state title="Belum ada aktivitas" message="Belum ada catatan pemakaian pada tanggal ini." icon="clipboard-list">
                <x-button variant="soft" :href="route('keti.search')">Cari ruangan</x-button>
            </x-empty-state>
        @else
            <x-card padding="p-4 sm:p-6" class="flex flex-col gap-3">
                <h2 class="text-lg font-semibold">Riwayat pemakaian ruang</h2>
                <x-data-table :headers="['Pengguna', 'Ruangan', 'Kegiatan / kelas', 'Waktu', 'Status']" class="border-0">
                    @foreach ($reservations as $item)
                        @php
                            $canView = $item->class_group_id === $classGroup->id || ($item->sessionReschedule && in_array((string) $classGroup->id, explode(',', $item->sessionReschedule->participant_key), true));
                            $detailUrl = $canView ? route('keti.reservations.show', $item) : null;
                        @endphp
                        <tr class="transition-colors hover:bg-page/70">
                            <td class="px-3.5 py-3 sm:px-5">
                                @if (config('sicma.privacy.show_booker_name_to_keti') || $item->class_group_id === $classGroup->id)
                                    {{ $item->creator->name }}
                                @else
                                    Keti {{ $item->classGroup->name }}
                                @endif
                            </td>
                            <td class="px-3.5 py-3 font-medium whitespace-nowrap sm:px-5">
                                @if ($detailUrl)
                                    <a href="{{ $detailUrl }}" class="inline-flex min-h-11 items-center rounded hover:text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:min-h-0">{{ $item->room->name }}</a>
                                @else
                                    {{ $item->room->name }}
                                @endif
                            </td>
                            <td class="px-3.5 py-3 sm:px-5">{{ $item->purpose }} ({{ $item->classGroup->name }})</td>
                            <td class="px-3.5 py-3 whitespace-nowrap sm:px-5">
                                @if ($item->status->value === 'in_use')
                                    Mulai {{ Wita::clock($item->actual_start_at, false) }} (berjalan)
                                @else
                                    {{ Wita::range($item->actual_start_at ?? $item->start_at, $item->actual_end_at ?? $item->end_at) }}
                                @endif
                            </td>
                            <td class="px-3.5 py-3 whitespace-nowrap sm:px-5"><x-status-badge :variant="$item->status->badgeVariant()">{{ $item->status->label() }}</x-status-badge></td>
                        </tr>
                    @endforeach
                </x-data-table>
            </x-card>
        @endif
    </div>
@endsection
