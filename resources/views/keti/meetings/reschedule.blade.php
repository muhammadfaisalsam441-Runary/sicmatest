@use('App\Support\Wita')
@extends('layouts.keti')

@section('title', 'Pindahkan pertemuan')

@php
    $period = $session->version->period;
    [$openHour, $openMinute] = array_map('intval', explode(':', (string) config('sicma.operating_hours.start')));
    [$closeHour, $closeMinute] = array_map('intval', explode(':', (string) config('sicma.operating_hours.end')));
    $timeOptions = collect(range($openHour * 60 + $openMinute, $closeHour * 60 + $closeMinute - $duration, 10))
        ->mapWithKeys(fn (int $minutes) => [sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60) => sprintf('%02d:%02d WITA', intdiv($minutes, 60), $minutes % 60)]);
    $pickedTime = $filters['jam'] ?? $meeting->start->format('H:i');
    $pickedDate = $filters['ke_tanggal'] ?? null;
@endphp

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Pindahkan pertemuan" subtitle="Pindahkan satu pertemuan ke jam lain yang kosong. Jadwal mingguan dari admin tidak berubah." />

        <x-card class="flex items-start justify-between gap-6">
            <div class="flex flex-col gap-1">
                <h2 class="text-xl font-semibold">{{ $session->title }}</h2>
                <p class="text-sm text-muted">Kelas {{ $session->participants->pluck('name')->implode(' + ') }}</p>
            </div>
            <div class="flex flex-col items-end gap-1 text-sm">
                <span class="text-xs text-muted">{{ $replacing ? 'Jadwal asli (sudah dipindah)' : 'Jadwal asli' }}</span>
                <span class="font-semibold">{{ Wita::longDate($meeting->start) }} • {{ Wita::range($meeting->start, $meeting->end) }}</span>
                <span class="text-muted">{{ $session->room?->name }} • {{ Wita::duration($duration) }}</span>
            </div>
        </x-card>

        <form method="GET" action="{{ route('keti.meetings.reschedule') }}" class="flex flex-col gap-5 rounded-card border border-border bg-surface p-6">
            <input type="hidden" name="sesi" value="{{ $session->id }}">
            <input type="hidden" name="tanggal" value="{{ $meeting->start->toDateString() }}">
            @if ($replacing)
                <input type="hidden" name="pengganti" value="{{ $replacing->reservation_id }}">
            @endif

            <fieldset class="flex flex-col gap-2">
                <legend class="mb-2 text-sm font-semibold">Dosen yang masuk</legend>
                @foreach ($team as $lecturer)
                    <label class="flex items-center gap-3 text-sm">
                        <input type="checkbox" name="dosen[]" value="{{ $lecturer['id'] }}" @checked(in_array($lecturer['id'], $selectedLecturerIds, true))
                               class="size-4 rounded border-border text-primary focus:ring-primary/30">
                        <span class="font-medium">{{ $lecturer['name'] }}</span>
                        <span class="text-muted">{{ $lecturer['role'] }}</span>
                        @if ($lecturer['offTurn'])
                            <span class="text-xs text-muted">• bukan giliran sesuai fase</span>
                        @endif
                    </label>
                @endforeach
                @if ($team === [])
                    <p class="text-sm text-danger">Mata kuliah ini belum punya tim pengajar. Hubungi admin sebelum memindahkan.</p>
                @endif
            </fieldset>

            <div class="grid grid-cols-3 items-end gap-4">
                <x-field label="Tanggal baru" name="ke_tanggal">
                    <x-input type="date" name="ke_tanggal" :value="$pickedDate" :min="now()->toDateString()" :max="$period->end_date->toDateString()" required />
                </x-field>
                <x-field label="Jam mulai" name="jam">
                    <x-select name="jam" :options="$timeOptions" :value="$pickedTime" />
                </x-field>
                <x-button type="submit" icon="search">Cek ruangan</x-button>
            </div>
            <p class="text-xs text-muted">Senin–Jumat di dalam semester, bukan hari libur dan bukan istirahat Jumat 12.00–13.10. Durasi tetap {{ Wita::duration($duration) }}.</p>
        </form>

        @if ($conflict)
            <x-info-box title="Slot ini belum bisa dipakai" variant="danger" role="alert">
                <p>{{ $conflict['message'] }}</p>
            </x-info-box>
        @elseif ($start)
            <div class="flex flex-col gap-3">
                <div class="flex items-end justify-between">
                    <h2 class="text-xl font-semibold">{{ count($rooms) }} ruangan kosong</h2>
                    <span class="text-xs text-muted">{{ Wita::longDate($start) }} • {{ Wita::range($start, $start->addMinutes($duration)) }}</span>
                </div>
                @forelse ($rooms as $room)
                    <div class="flex items-center justify-between gap-4 rounded-card border border-border bg-surface px-5 py-4">
                        <div class="flex flex-col gap-0.5">
                            <span class="font-semibold">{{ $room['name'] }}
                                @if ($room['id'] === $session->room_id)
                                    <span class="text-xs font-normal text-muted">• ruang asli</span>
                                @endif
                            </span>
                            <span class="text-xs text-muted">{{ $room['location'] }} • {{ $room['capacity'] ? $room['capacity'].' kursi' : 'Kapasitas belum didata' }}</span>
                        </div>
                        <form method="POST" action="{{ route('keti.meetings.reschedule.store') }}">
                            @csrf
                            <input type="hidden" name="session_id" value="{{ $session->id }}">
                            <input type="hidden" name="original_date" value="{{ $meeting->start->toDateString() }}">
                            @if ($replacing)
                                <input type="hidden" name="replaces_reservation_id" value="{{ $replacing->reservation_id }}">
                            @endif
                            <input type="hidden" name="start_at" value="{{ $start->format('Y-m-d H:i') }}">
                            <input type="hidden" name="room_id" value="{{ $room['id'] }}">
                            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
                            @foreach ($selectedLecturerIds as $lecturerId)
                                <input type="hidden" name="lecturer_ids[]" value="{{ $lecturerId }}">
                            @endforeach
                            <x-button type="submit">Pindahkan ke sini</x-button>
                        </form>
                    </div>
                @empty
                    <x-empty-state title="Tidak ada ruangan kosong" message="Coba tanggal atau jam lain." icon="calendar" />
                @endforelse
            </div>
        @endif
    </div>
@endsection
