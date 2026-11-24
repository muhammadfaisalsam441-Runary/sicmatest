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

        <x-card padding="p-4 sm:p-6" class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start sm:gap-6">
            <div class="flex min-w-0 flex-col gap-1">
                <h2 class="text-xl font-semibold">{{ $session->title }}</h2>
                <p class="text-sm text-muted">Kelas {{ $session->participants->pluck('name')->implode(' + ') }}</p>
            </div>
            <div class="flex flex-col gap-1 text-sm sm:items-end sm:text-right">
                <span class="text-xs text-muted">{{ $replacing ? ($replacing->isOnline() ? 'Jadwal asli (sedang Online)' : 'Jadwal asli (sudah dipindah)') : 'Jadwal asli' }}</span>
                <span class="font-semibold">{{ Wita::longDate($meeting->start) }} • {{ Wita::range($meeting->start, $meeting->end) }}</span>
                <span class="text-muted">{{ $session->room?->name }} • {{ Wita::duration($duration) }}</span>
            </div>
        </x-card>

        <form method="GET" action="{{ route('keti.meetings.reschedule') }}" class="flex flex-col gap-5 rounded-card border border-border bg-surface p-4 sm:p-6">
            <input type="hidden" name="sesi" value="{{ $session->id }}">
            <input type="hidden" name="tanggal" value="{{ $meeting->start->toDateString() }}">
            @if ($replacing)
                @if ($replacing->isOnline())
                    <input type="hidden" name="pengganti_online" value="{{ $replacing->id }}">
                @else
                    <input type="hidden" name="pengganti" value="{{ $replacing->reservation_id }}">
                @endif
            @endif

            <fieldset class="flex flex-col gap-1.5">
                <legend class="mb-2 text-sm font-semibold">Dosen yang masuk</legend>
                @foreach ($team as $lecturer)
                    <label class="flex min-h-11 cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 py-1.5 text-sm">
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

            <div class="grid grid-cols-1 items-end gap-4 sm:grid-cols-2 md:grid-cols-3">
                <x-field label="Tanggal baru" name="ke_tanggal">
                    <x-input type="date" name="ke_tanggal" :value="$pickedDate" :min="now()->toDateString()" :max="$period->end_date->toDateString()" onchange="const j = this.form.querySelector('[name=jam]'); if (j) j.value = ''; this.form.requestSubmit();" required />
                </x-field>
                <x-field label="Pilih jam tertentu (opsional)" name="jam">
                    <x-select name="jam" :options="$timeOptions" :value="$pickedTime" placeholder="Semua slot rekomendasi (Otomatis)" onchange="this.form.requestSubmit()" />
                </x-field>
                <x-button type="submit" icon="search" class="min-h-11 w-full sm:col-span-2 md:col-span-1">Cari Rekomendasi</x-button>
            </div>
            <p class="text-xs text-muted">Durasi perkuliahan: {{ Wita::duration($duration) }} (1 SKS = 50 menit). Rekomendasi otomatis memindai slot waktu bebas bentrok dosen & kelas serta ruangan yang kosong.</p>
        </form>

        @if ($conflict)
            <x-info-box title="Slot ini belum bisa dipakai" variant="danger" role="alert">
                <p>{{ $conflict['message'] }}</p>
            </x-info-box>
            @if ($recommendations->isNotEmpty())
                <div class="flex flex-col gap-2 rounded-card border border-border bg-surface p-4 sm:p-5">
                    <span class="text-sm font-semibold text-foreground">Rekomendasi slot lain yang kosong pada tanggal ini:</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($recommendations as $rec)
                            <a href="{{ route('keti.meetings.reschedule', array_filter([
                                    'sesi' => $session->id,
                                    'tanggal' => $meeting->start->toDateString(),
                                    'ke_tanggal' => $pickedDate,
                                    'jam' => $rec['start']->format('H:i'),
                                    'pengganti' => $replacing && ! $replacing->isOnline() ? $replacing->reservation_id : null,
                                    'pengganti_online' => $replacing?->isOnline() ? $replacing->id : null,
                                    'dosen' => $selectedLecturerIds,
                                ])) }}"
                               class="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-border bg-subtle px-3 py-2 text-xs font-medium text-foreground transition-colors hover:bg-border/60">
                                <span>{{ $rec['slot'] }}</span>
                                <span class="rounded bg-primary/10 px-1.5 py-0.5 text-primary text-[10px] font-semibold">
                                    {{ $rec['rooms_count'] > 0 ? $rec['rooms_count'].' ruang' : 'Online' }}
                                </span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        @elseif ($start)
            <div class="flex flex-col gap-4">
                <div class="flex flex-col justify-between gap-4 rounded-card border border-primary/40 bg-primary-soft/30 p-4 sm:flex-row sm:items-center sm:px-5">
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <span class="font-semibold text-ink">Kuliah Online (Tanpa Ruangan)</span>
                        <span class="text-xs text-muted">{{ Wita::longDate($start) }} • {{ Wita::range($start, $start->addMinutes($duration)) }} • Ruang asli dilepas untuk kelas lain</span>
                    </div>
                    <form method="POST" action="{{ route('keti.meetings.online.store') }}" class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                        @csrf
                        <input type="hidden" name="session_id" value="{{ $session->id }}">
                        <input type="hidden" name="original_date" value="{{ $meeting->start->toDateString() }}">
                        @if ($replacing)
                            @if ($replacing->isOnline())
                                <input type="hidden" name="replaces_reschedule_id" value="{{ $replacing->id }}">
                            @else
                                <input type="hidden" name="replaces_reservation_id" value="{{ $replacing->reservation_id }}">
                            @endif
                        @endif
                        <input type="hidden" name="start_at" value="{{ $start->format('Y-m-d H:i') }}">
                        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
                        @foreach ($selectedLecturerIds as $lecturerId)
                            <input type="hidden" name="lecturer_ids[]" value="{{ $lecturerId }}">
                        @endforeach
                        <x-button type="submit" variant="soft" class="min-h-11 w-full sm:w-auto">Pilih Kuliah Online</x-button>
                    </form>
                </div>

                <div class="flex flex-wrap items-end justify-between gap-2">
                    <h2 class="text-xl font-semibold">{{ count($rooms) }} ruangan kosong</h2>
                    <span class="text-xs text-muted">{{ Wita::longDate($start) }} • {{ Wita::range($start, $start->addMinutes($duration)) }}</span>
                </div>
                @forelse ($rooms as $room)
                    <div class="flex flex-col justify-between gap-4 rounded-card border border-border bg-surface p-4 sm:flex-row sm:items-center sm:px-5">
                        <div class="flex min-w-0 flex-col gap-0.5">
                            <span class="font-semibold">{{ $room['name'] }}
                                @if ($room['id'] === $session->room_id)
                                    <span class="text-xs font-normal text-muted">• ruang asli</span>
                                @endif
                            </span>
                            <span class="text-xs text-muted">{{ $room['location'] }} • {{ $room['capacity'] ? $room['capacity'].' kursi' : 'Kapasitas belum didata' }}</span>
                        </div>
                        <form method="POST" action="{{ route('keti.meetings.reschedule.store') }}" class="w-full sm:w-auto">
                            @csrf
                            <input type="hidden" name="session_id" value="{{ $session->id }}">
                            <input type="hidden" name="original_date" value="{{ $meeting->start->toDateString() }}">
                            @if ($replacing)
                                @if ($replacing->isOnline())
                                    <input type="hidden" name="replaces_reschedule_id" value="{{ $replacing->id }}">
                                @else
                                    <input type="hidden" name="replaces_reservation_id" value="{{ $replacing->reservation_id }}">
                                @endif
                            @endif
                            <input type="hidden" name="start_at" value="{{ $start->format('Y-m-d H:i') }}">
                            <input type="hidden" name="room_id" value="{{ $room['id'] }}">
                            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
                            @foreach ($selectedLecturerIds as $lecturerId)
                                <input type="hidden" name="lecturer_ids[]" value="{{ $lecturerId }}">
                            @endforeach
                            <x-button type="submit" class="min-h-11 w-full sm:w-auto">Pindahkan ke sini</x-button>
                        </form>
                    </div>
                @empty
                    <x-empty-state title="Tidak ada ruangan kosong" message="Semua ruang fisik terpakai di jam ini. Kamu tetap bisa memilih Kuliah Online (Tanpa Ruangan) di atas atau coba tanggal/jam lain." icon="calendar" />
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
                        <div class="sicma-card-enter flex flex-col gap-3 rounded-card border border-border bg-surface p-4 sm:p-5" style="--stagger: {{ $loop->index }}">
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 pb-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary font-bold text-sm">
                                        <i data-lucide="clock" aria-hidden="true" class="size-4"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <h3 class="font-semibold text-base text-foreground">{{ $rec['slot'] }}</h3>
                                        <p class="text-xs text-muted tabular-nums">{{ Wita::range($rec['start'], $rec['end']) }} • Durasi {{ Wita::duration($duration) }}</p>
                                    </div>
                                </div>
                                @if ($rec['rooms_count'] > 0)
                                    <x-status-badge variant="success">{{ $rec['rooms_count'] }} ruangan kosong</x-status-badge>
                                @else
                                    <x-status-badge variant="primary" class="!whitespace-normal text-left">Ruangan fisik penuh • Tersedia Online</x-status-badge>
                                @endif
                            </div>

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                <div class="sicma-card-interactive flex flex-col justify-between gap-3 rounded-lg border border-primary/40 bg-primary-soft/30 p-3.5 transition-colors hover:border-primary/70">
                                    <div>
                                        <div class="flex items-center gap-1.5 text-sm font-semibold text-ink">
                                            <i data-lucide="globe" aria-hidden="true" class="size-4 text-primary"></i>
                                            <span>Kuliah Online (Tanpa Ruangan)</span>
                                        </div>
                                        <div class="mt-1 text-xs text-muted">Bebas ruangan • Ruang asli dilepas</div>
                                    </div>
                                    <form method="POST" action="{{ route('keti.meetings.online.store') }}" class="w-full">
                                        @csrf
                                        <input type="hidden" name="session_id" value="{{ $session->id }}">
                                        <input type="hidden" name="original_date" value="{{ $meeting->start->toDateString() }}">
                                        @if ($replacing)
                                            @if ($replacing->isOnline())
                                                <input type="hidden" name="replaces_reschedule_id" value="{{ $replacing->id }}">
                                            @else
                                                <input type="hidden" name="replaces_reservation_id" value="{{ $replacing->reservation_id }}">
                                            @endif
                                        @endif
                                        <input type="hidden" name="start_at" value="{{ $rec['start']->format('Y-m-d H:i') }}">
                                        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
                                        @foreach ($selectedLecturerIds as $lecturerId)
                                            <input type="hidden" name="lecturer_ids[]" value="{{ $lecturerId }}">
                                        @endforeach
                                        <x-button type="submit" variant="soft" size="sm" class="min-h-11 w-full justify-center sm:min-h-0">Pilih Kuliah Online</x-button>
                                    </form>
                                </div>
                                @foreach ($rec['rooms'] as $room)
                                    <div class="sicma-card-interactive flex flex-col justify-between gap-3 rounded-lg border border-border/80 bg-page p-3.5 hover:border-primary/50">
                                        <div>
                                            <div class="flex flex-wrap items-center gap-1.5 text-sm font-semibold text-ink">
                                                <i data-lucide="map-pin" aria-hidden="true" class="size-3.5 text-muted"></i>
                                                <span>{{ $room['name'] }}</span>
                                                @if ($room['id'] === $session->room_id)
                                                    <span class="rounded bg-primary-soft px-1.5 py-0.5 text-[10px] font-medium text-primary">Ruang Asli</span>
                                                @endif
                                            </div>
                                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted">
                                                <span>{{ $room['location'] }}</span>
                                                <span>•</span>
                                                <span class="inline-flex items-center gap-1 font-medium text-ink tabular-nums">
                                                    <i data-lucide="users" aria-hidden="true" class="size-3 text-muted"></i>
                                                    {{ $room['capacity'] ? $room['capacity'].' kursi' : 'Kapasitas belum didata' }}
                                                </span>
                                            </div>
                                        </div>
                                        <form method="POST" action="{{ route('keti.meetings.reschedule.store') }}" class="w-full">
                                            @csrf
                                            <input type="hidden" name="session_id" value="{{ $session->id }}">
                                            <input type="hidden" name="original_date" value="{{ $meeting->start->toDateString() }}">
                                            @if ($replacing)
                                                @if ($replacing->isOnline())
                                                    <input type="hidden" name="replaces_reschedule_id" value="{{ $replacing->id }}">
                                                @else
                                                    <input type="hidden" name="replaces_reservation_id" value="{{ $replacing->reservation_id }}">
                                                @endif
                                            @endif
                                            <input type="hidden" name="start_at" value="{{ $rec['start']->format('Y-m-d H:i') }}">
                                            <input type="hidden" name="room_id" value="{{ $room['id'] }}">
                                            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
                                            @foreach ($selectedLecturerIds as $lecturerId)
                                                <input type="hidden" name="lecturer_ids[]" value="{{ $lecturerId }}">
                                            @endforeach
                                            <x-button type="submit" size="sm" class="min-h-11 w-full justify-center sm:min-h-0">Pindahkan ke sini</x-button>
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
