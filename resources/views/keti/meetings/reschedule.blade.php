@use('App\Support\Wita')
@extends('layouts.keti')

@section('title', 'Pindahkan pertemuan')

@php
    $period = $session->version->period;
    [$openHour, $openMinute] = array_map('intval', explode(':', (string) config('sicma.operating_hours.start')));
    [$closeHour, $closeMinute] = array_map('intval', explode(':', (string) config('sicma.operating_hours.end')));
    $timeOptions = collect(range($openHour * 60 + $openMinute, $closeHour * 60 + $closeMinute - $duration, 10))
        ->mapWithKeys(fn (int $minutes) => [sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60) => sprintf('%02d:%02d WITA', intdiv($minutes, 60), $minutes % 60)]);
    $pickedTime = $filters['jam'] ?? '';
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

            <div class="grid grid-cols-1 md:grid-cols-3 items-end gap-4">
                <x-field label="Tanggal baru" name="ke_tanggal">
                    <x-input type="date" name="ke_tanggal" :value="$pickedDate" :min="now()->toDateString()" :max="$period->end_date->toDateString()" onchange="const j = this.form.querySelector('[name=jam]'); if (j) j.value = ''; this.form.requestSubmit();" required />
                </x-field>
                <x-field label="Pilih jam tertentu (opsional)" name="jam">
                    <x-select name="jam" :options="$timeOptions" :value="$pickedTime" placeholder="Semua slot rekomendasi (Otomatis)" onchange="this.form.requestSubmit()" />
                </x-field>
                <x-button type="submit" icon="search">Cari Rekomendasi</x-button>
            </div>
            <p class="text-xs text-muted">Durasi perkuliahan: {{ Wita::duration($duration) }} (1 SKS = 50 menit). Rekomendasi otomatis memindai slot waktu bebas bentrok dosen & kelas serta ruangan yang kosong.</p>
        </form>

        @if ($conflict)
            <x-info-box title="Slot ini belum bisa dipakai" variant="danger" role="alert">
                <p>{{ $conflict['message'] }}</p>
            </x-info-box>
            @if ($recommendations->isNotEmpty())
                <div class="flex flex-col gap-2 rounded-card border border-border bg-surface p-4">
                    <span class="text-sm font-semibold text-foreground">Rekomendasi slot lain yang kosong pada tanggal ini:</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($recommendations as $rec)
                            <a href="{{ route('keti.meetings.reschedule', array_filter(['sesi' => $session->id, 'tanggal' => $meeting->start->toDateString(), 'ke_tanggal' => $pickedDate, 'jam' => $rec['start']->format('H:i'), 'pengganti' => $replacing?->reservation_id, 'dosen' => $selectedLecturerIds])) }}"
                               class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-subtle px-3 py-1.5 text-xs font-medium text-foreground hover:bg-border/60 transition-colors">
                                <span>{{ $rec['slot'] }}</span>
                                <span class="rounded bg-primary/10 px-1.5 py-0.5 text-primary text-[10px] font-semibold">{{ $rec['rooms_count'] }} ruang</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
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
        @elseif ($recommendations->isNotEmpty())
            <div class="flex flex-col gap-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-semibold">Rekomendasi Waktu dan Ruangan Kosong</h2>
                        <p class="text-sm text-muted">Ditemukan {{ $recommendations->count() }} pilihan waktu kuliah bebas bentrok dengan durasi {{ Wita::duration($duration) }} pada {{ Wita::longDate($targetDate) }}.</p>
                    </div>
                </div>

                <div class="flex flex-col gap-5">
                    @foreach ($recommendations as $rec)
                        <div class="flex flex-col gap-3 rounded-card border border-border bg-surface p-5">
                            <div class="flex items-center justify-between border-b border-border/60 pb-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex size-8 items-center justify-center rounded-lg bg-primary-soft text-primary font-bold text-sm">
                                        <i data-lucide="clock" class="size-4"></i>
                                    </span>
                                    <div>
                                        <h3 class="font-semibold text-base text-foreground">{{ $rec['slot'] }}</h3>
                                        <p class="text-xs text-muted">{{ Wita::range($rec['start'], $rec['end']) }} • Durasi {{ Wita::duration($duration) }}</p>
                                    </div>
                                </div>
                                <span class="rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                    {{ $rec['rooms_count'] }} ruangan kosong
                                </span>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                @foreach ($rec['rooms'] as $room)
                                    <div class="flex flex-col justify-between gap-3 rounded-lg border border-border/80 bg-subtle p-3.5 hover:border-primary/50 transition-colors">
                                        <div class="flex items-start justify-between gap-2">
                                            <div>
                                                <div class="font-semibold text-sm text-foreground flex items-center gap-1.5">
                                                    <span>{{ $room['name'] }}</span>
                                                    @if ($room['id'] === $session->room_id)
                                                        <span class="rounded bg-primary-soft px-1.5 py-0.5 text-[10px] font-medium text-primary">Ruang Asli</span>
                                                    @endif
                                                </div>
                                                <div class="text-xs text-muted mt-0.5">
                                                    {{ $room['location'] }} • {{ $room['capacity'] ? $room['capacity'].' kursi' : 'Kapasitas belum didata' }}
                                                </div>
                                            </div>
                                        </div>
                                        <form method="POST" action="{{ route('keti.meetings.reschedule.store') }}" class="w-full">
                                            @csrf
                                            <input type="hidden" name="session_id" value="{{ $session->id }}">
                                            <input type="hidden" name="original_date" value="{{ $meeting->start->toDateString() }}">
                                            @if ($replacing)
                                                <input type="hidden" name="replaces_reservation_id" value="{{ $replacing->reservation_id }}">
                                            @endif
                                            <input type="hidden" name="start_at" value="{{ $rec['start']->format('Y-m-d H:i') }}">
                                            <input type="hidden" name="room_id" value="{{ $room['id'] }}">
                                            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
                                            @foreach ($selectedLecturerIds as $lecturerId)
                                                <input type="hidden" name="lecturer_ids[]" value="{{ $lecturerId }}">
                                            @endforeach
                                            <x-button type="submit" size="sm" class="w-full justify-center">Pindahkan ke sini</x-button>
                                        </form>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif ($pickedDate)
            <x-empty-state title="Tidak ada waktu dan ruangan yang cocok" message="Semua slot pada tanggal ini bentrok dengan jadwal dosen/kelas atau ruangan penuh. Coba pilih tanggal lain." icon="calendar" />
        @else
            <x-empty-state title="Pilih Tanggal Pemindahan" message="Pilih tanggal baru di atas untuk memindai otomatis rekomendasi waktu dan ruangan kosong yang cocok dengan durasi perkuliahan ({{ Wita::duration($duration) }})." icon="calendar" />
        @endif
    </div>
@endsection
