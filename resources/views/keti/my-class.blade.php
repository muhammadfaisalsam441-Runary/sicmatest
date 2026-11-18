@use('App\Support\Wita')
@use('Carbon\CarbonImmutable')
@extends('layouts.keti')

@section('title', 'My Class')

@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="My Class" :subtitle="'Jadwal dan perkuliahan Kelas '.$classGroup->name.'.'" />

        @if ($errors->any())
            <x-info-box title="Data belum lengkap" variant="danger" role="alert">
                <p>{{ $errors->first() }}</p>
            </x-info-box>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-2">
            <a href="{{ route('keti.my-class', ['tanggal' => $date->subWeek()->toDateString()]) }}" class="inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary-soft/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <i data-lucide="chevron-left" aria-hidden="true" class="size-4"></i>
                <span>Minggu lalu</span>
            </a>
            <span class="text-xs font-semibold text-muted">
                {{ Wita::date($days->first()) }} – {{ Wita::date($days->last()) }}
            </span>
            <a href="{{ route('keti.my-class', ['tanggal' => $date->addWeek()->toDateString()]) }}" class="inline-flex items-center gap-1.5 rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary-soft/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <span>Minggu depan</span>
                <i data-lucide="chevron-right" aria-hidden="true" class="size-4"></i>
            </a>
        </div>

        <nav class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5" aria-label="Pilih hari">
            @foreach ($days as $day)
                <a href="{{ route('keti.my-class', ['tanggal' => $day->toDateString()]) }}"
                   @if ($day->isSameDay($date)) aria-current="date" @endif
                   @class([
                       'flex flex-col items-center justify-center rounded-card border px-4 py-3 text-sm transition-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                       'border-primary bg-primary text-white shadow-sm' => $day->isSameDay($date),
                       'border-border bg-surface text-ink hover:border-primary/50 hover:bg-primary-soft/40' => ! $day->isSameDay($date),
                   ])>
                    <span class="font-semibold text-sm">{{ Wita::dayName($day) }}</span>
                    <span @class(['text-xs mt-0.5', 'text-white/80' => $day->isSameDay($date), 'text-muted' => ! $day->isSameDay($date)])>
                        {{ Wita::date($day) }}
                    </span>
                </a>
            @endforeach
        </nav>

        @if ($meetings->isEmpty() && $replacements->isEmpty() && $onlineReplacements->isEmpty())
            <x-empty-state title="Belum ada jadwal" :message="! in_array($date->dayOfWeekIso, config('sicma.scheduler.days'), true) ? 'Perkuliahan berlangsung Senin–Jumat.' : 'Tidak ada jadwal kelas pada tanggal yang dipilih.'" icon="calendar">
                <x-button variant="soft" :href="route('keti.search')">Cari ruangan kosong</x-button>
            </x-empty-state>
        @else
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-xl font-semibold">{{ $meetings->count() + $replacements->count() + $onlineReplacements->count() }} jadwal</h2>
                <span class="text-xs text-muted">{{ Wita::longDate($date) }}</span>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ($meetings as $meeting)
                    @php
                        $session = $meeting['session'];
                        $reschedule = $meeting['reschedule'];
                        $replacement = $reschedule?->reservation;
                        $movedOnline = $reschedule !== null && $reschedule->isOnline() && $meeting['status'] === 'moved';
                        [$badge, $variant] = match ($meeting['status']) {
                            'online' => ['Online', 'primary'],
                            'switched_online' => ['Online • Ruang dilepas', 'primary'],
                            'moved' => ['Dipindahkan', 'neutral'],
                            'finished' => ($meeting['finishedEarly'] ?? false) ? ['Selesai • Ruang dilepas', 'neutral'] : ['Selesai', 'neutral'],
                            'ongoing' => ['Sedang berlangsung', 'success'],
                            default => ['Akan datang', 'primary'],
                        };
                        $isLive = $meeting['status'] === 'ongoing';
                        $isOnlineCard = in_array($meeting['status'], ['online', 'switched_online'], true);
                        $isDimmed = in_array($meeting['status'], ['moved', 'finished'], true);
                        $cardClasses = 'sicma-card-interactive sicma-card-enter flex flex-col gap-3.5 hover:border-primary/35'.match (true) {
                            $isLive => ' ring-1 ring-primary/15',
                            $isDimmed => ' opacity-75',
                            default => '',
                        };
                        $lecturersText = ($reschedule && $reschedule->lecturers->isNotEmpty() ? $reschedule->lecturers : $session->lecturers)->pluck('name')->implode(', ') ?: 'Perlu verifikasi';
                    @endphp
                    <x-card :class="$cardClasses" style="--stagger: {{ $loop->index }}">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <span class="text-[11px] font-semibold uppercase tracking-wide text-muted">
                                    {{ $meeting['status'] === 'switched_online' ? 'Perkuliahan Online' : ($session->mode->value === 'online' ? 'Perkuliahan Online' : 'Jadwal Kelas') }}
                                </span>
                                <h3 class="text-lg font-semibold leading-snug text-ink">{{ $session->title }}</h3>
                            </div>
                            <x-status-badge :variant="$variant" :pulse="$isLive">{{ $badge }}</x-status-badge>
                        </div>

                        <dl class="grid grid-cols-1 gap-2.5 rounded-control bg-page px-3.5 py-2.5 sm:grid-cols-2 sm:items-center">
                            <div class="flex flex-col gap-0.5">
                                <dt class="text-[11px] font-medium text-muted">{{ $isOnlineCard ? 'Mode kelas' : 'Ruangan' }}</dt>
                                <dd class="inline-flex items-center gap-1.5 text-sm font-semibold text-ink">
                                    <i data-lucide="{{ $isOnlineCard ? 'globe' : 'map-pin' }}" aria-hidden="true" class="size-3.5 shrink-0 text-primary"></i>
                                    <span>{{ $meeting['status'] === 'switched_online' ? 'Kuliah online' : ($session->room?->name ?? 'Kuliah online') }}</span>
                                </dd>
                                <dd class="text-xs text-muted">
                                    @if ($meeting['status'] === 'switched_online')
                                        Ruang {{ $session->room?->name }} dilepas untuk kelas lain
                                    @elseif ($session->room)
                                        {{ ($session->room->building?->name ?? 'Gedung belum didata').' • Lantai '.($session->room->floor ?? '—') }}
                                    @else
                                        Tanpa ruang fisik
                                    @endif
                                </dd>
                            </div>
                            <div class="flex flex-col gap-0.5 sm:items-end sm:text-right">
                                <dt class="text-[11px] font-medium text-muted">Waktu</dt>
                                <dd class="text-sm font-semibold text-ink tabular-nums">{{ Wita::minutes($session->start_minute) }}–{{ Wita::minutes($session->end_minute) }} WITA</dd>
                            </div>
                        </dl>

                        @if ($meeting['status'] === 'switched_online' && $reschedule->note)
                            <div class="rounded-control border border-primary/20 bg-primary-soft/35 px-3 py-2 text-xs text-ink">
                                <span class="font-semibold text-primary">Catatan:</span> {{ $reschedule->note }}
                            </div>
                        @elseif ($replacement)
                            <div class="rounded-control border border-border bg-page px-3 py-2 text-xs font-medium text-ink">
                                ke {{ Wita::dayName($replacement->start_at) }}, {{ Wita::longDate($replacement->start_at) }} • {{ Wita::clock($replacement->start_at, false) }} • {{ $replacement->room->name }}
                            </div>
                        @elseif ($movedOnline)
                            <div class="rounded-control border border-primary/20 bg-primary-soft/35 px-3 py-2 text-xs font-medium text-ink">
                                ke {{ Wita::dayName($reschedule->target_start_at) }}, {{ Wita::longDate($reschedule->target_start_at) }} • {{ Wita::clock($reschedule->target_start_at, false) }} • Kuliah Online
                            </div>
                        @elseif ($meeting['status'] === 'finished' && ($meeting['finishedEarly'] ?? false))
                            <div class="rounded-control border border-border bg-page px-3 py-2 text-xs text-ink">Diselesaikan pukul {{ Wita::clock($meeting['actualEnd'], false) }} • Ruang {{ $session->room?->name }} dilepas untuk kelas lain</div>
                        @endif

                        <div class="flex flex-col gap-1 text-xs text-muted">
                            <p class="flex items-start gap-1.5">
                                <i data-lucide="user" aria-hidden="true" class="mt-0.5 size-3.5 shrink-0"></i>
                                <span><span class="font-medium text-ink">Pengajar:</span> {{ $lecturersText }}</span>
                            </p>
                            @if ($session->participants->count() > 1)
                                <p class="flex items-start gap-1.5">
                                    <i data-lucide="users" aria-hidden="true" class="mt-0.5 size-3.5 shrink-0"></i>
                                    <span><span class="font-medium text-ink">Kelas gabungan:</span> {{ $session->participants->pluck('name')->implode(' + ') }}</span>
                                </p>
                            @endif
                        </div>

                        <div class="mt-auto flex flex-wrap items-center justify-end gap-2 border-t border-border/60 pt-3">
                            @if ($replacement)
                                <x-button size="sm" variant="soft" :href="route('keti.reservations.show', $replacement)">Lihat pengganti</x-button>
                            @elseif ($movedOnline)
                                <x-button size="sm" variant="soft" :href="route('keti.my-class', ['tanggal' => $reschedule->effectiveStart()->toDateString()])">Lihat jadwal online</x-button>
                            @elseif ($meeting['status'] === 'switched_online')
                                @if ($meeting['canRestoreOnline'])
                                    <form method="POST" action="{{ route('keti.meetings.online.cancel', $reschedule) }}">
                                        @csrf
                                        <x-button size="sm" type="submit" variant="secondary">Kembalikan ke luring</x-button>
                                    </form>
                                    <x-button size="sm" variant="soft" :href="route('keti.meetings.reschedule', ['sesi' => $session->id, 'tanggal' => $date->toDateString(), 'pengganti_online' => $reschedule->id])">Pindahkan</x-button>
                                @endif
                            @elseif ($meeting['status'] === 'ongoing' && $session->blocksRoom())
                                <x-button size="sm" type="button" variant="primary" icon="check" x-data="" x-on:click="$dispatch('open-modal', 'finish-session-{{ $session->id }}')">Selesaikan kelas</x-button>
                            @elseif ($meeting['status'] === 'upcoming' && $session->blocksRoom())
                                <x-button size="sm" type="button" variant="soft" x-data="" x-on:click="$dispatch('open-modal', 'online-{{ $session->id }}')">Jadikan Online</x-button>
                                <x-button size="sm" variant="secondary" :href="route('keti.meetings.reschedule', ['sesi' => $session->id, 'tanggal' => $date->toDateString()])">Pindahkan</x-button>
                            @endif
                            @if ($session->room && ! $reschedule)
                                <x-button size="sm" variant="soft" :href="route('keti.rooms.show', $session->room)">Detail ruangan</x-button>
                            @endif
                        </div>
                    </x-card>

                    @if ($meeting['status'] === 'ongoing' && $session->blocksRoom())
                        <x-modal name="finish-session-{{ $session->id }}" title="Selesaikan Perkuliahan Sekarang?">
                            <form method="POST" action="{{ route('keti.meetings.finish') }}" class="flex flex-col gap-4">
                                @csrf
                                <input type="hidden" name="session_id" value="{{ $session->id }}">
                                <input type="hidden" name="original_date" value="{{ $date->toDateString() }}">

                                <p class="text-sm text-muted">
                                    Perkuliahan <strong class="text-ink">{{ $session->title }}</strong> sedang berlangsung di <strong class="text-ink">{{ $session->room?->name }}</strong>. Jika diselesaikan sekarang, ruang <strong class="text-ink">{{ $session->room?->name }}</strong> akan langsung dilepas dan tersedia untuk kelas lain hingga pukul <strong class="text-ink">{{ Wita::minutes($session->end_minute) }} WITA</strong>.
                                </p>

                                <div class="flex flex-wrap justify-end gap-2 pt-2">
                                    <x-button type="button" variant="secondary" autofocus x-on:click="$dispatch('close-modal', 'finish-session-{{ $session->id }}')">Batal</x-button>
                                    <x-button type="submit" icon="check">Ya, Selesaikan &amp; Kosongkan Ruang</x-button>
                                </div>
                            </form>
                        </x-modal>
                    @endif

                    @if ($meeting['status'] === 'upcoming' && $session->blocksRoom())
                        <x-modal name="online-{{ $session->id }}" title="Jadikan Kuliah Online?">
                            <form method="POST" action="{{ route('keti.meetings.online.store') }}" class="flex flex-col gap-4">
                                @csrf
                                <input type="hidden" name="session_id" value="{{ $session->id }}">
                                <input type="hidden" name="original_date" value="{{ $date->toDateString() }}">
                                <input type="hidden" name="idempotency_key" value="{{ $onlineIdempotencyKey }}">

                                <p class="text-sm text-muted">
                                    Pertemuan <strong class="text-ink">{{ $session->title }}</strong> ({{ Wita::longDate($date) }} • {{ Wita::range($meeting['start'], $meeting['end']) }}) akan ditandai sebagai <strong class="text-ink">Kuliah Online</strong>. Ruang <strong class="text-ink">{{ $session->room?->name }}</strong> langsung dikosongkan agar bisa dipakai kelas lain pada pertemuan ini saja.
                                </p>

                                <fieldset class="flex flex-col gap-2">
                                    <legend class="mb-1 text-sm font-semibold text-ink">Dosen yang mengajar</legend>
                                    @forelse ($meeting['team'] as $lecturer)
                                        <label class="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-sm">
                                            <input type="checkbox"
                                                   name="lecturer_ids[]"
                                                   value="{{ $lecturer['id'] }}"
                                                   @checked(in_array($lecturer['id'], $meeting['defaultLecturerIds'], true))
                                                   class="size-4 rounded border-border text-primary focus:ring-primary/30">
                                            <span class="font-medium text-ink">{{ $lecturer['name'] }}</span>
                                            <span class="text-muted">{{ $lecturer['role'] }}</span>
                                            @if ($lecturer['offTurn'])
                                                <span class="text-xs text-muted">• bukan giliran sesuai fase</span>
                                            @endif
                                        </label>
                                    @empty
                                        <p class="text-sm text-danger">Mata kuliah ini belum punya tim pengajar. Hubungi admin terlebih dahulu.</p>
                                    @endforelse
                                </fieldset>

                                <x-field label="Catatan dosen (opsional)" :name="'note-'.$session->id">
                                    <x-input :id="'note-'.$session->id" type="text" name="note" maxlength="255" placeholder="Contoh: Kuliah online via Zoom sesuai arahan dosen" />
                                </x-field>

                                <div class="flex flex-wrap justify-end gap-2 pt-2">
                                    <x-button type="button" variant="secondary" x-on:click="$dispatch('close-modal', 'online-{{ $session->id }}')">Batal</x-button>
                                    <x-button type="submit" :disabled="$meeting['team'] === []">Ya, Jadikan Online</x-button>
                                </div>
                            </form>
                        </x-modal>
                    @endif
                @endforeach
                @foreach ($replacements as $reservation)
                    <x-card class="sicma-card-interactive sicma-card-enter flex flex-col gap-3.5 hover:border-primary/35">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <span class="text-[11px] font-semibold uppercase tracking-wide text-primary">Kuliah Pengganti</span>
                                <h3 class="text-lg font-semibold leading-snug text-ink">{{ $reservation->sessionReschedule->session_title }}</h3>
                            </div>
                            <x-status-badge :variant="$reservation->status->badgeVariant()" :pulse="$reservation->status->value === 'in_use'">{{ $reservation->status->label() }}</x-status-badge>
                        </div>
                        <dl class="grid grid-cols-1 gap-2.5 rounded-control bg-page px-3.5 py-2.5 sm:grid-cols-2 sm:items-center">
                            <div class="flex flex-col gap-0.5">
                                <dt class="text-[11px] font-medium text-muted">Ruangan pengganti</dt>
                                <dd class="inline-flex items-center gap-1.5 text-sm font-semibold text-ink">
                                    <i data-lucide="map-pin" aria-hidden="true" class="size-3.5 shrink-0 text-primary"></i>
                                    <span>{{ $reservation->room->name }}</span>
                                </dd>
                            </div>
                            <div class="flex flex-col gap-0.5 sm:items-end sm:text-right">
                                <dt class="text-[11px] font-medium text-muted">Waktu pengganti</dt>
                                <dd class="text-sm font-semibold text-ink tabular-nums">{{ Wita::range($reservation->start_at, $reservation->end_at) }}</dd>
                            </div>
                        </dl>
                        <p class="text-xs text-muted">Dipindah dari {{ Wita::dayName($reservation->sessionReschedule->original_start_at) }}, {{ Wita::longDate($reservation->sessionReschedule->original_start_at) }} • {{ Wita::clock($reservation->sessionReschedule->original_start_at, false) }}</p>
                        <div class="mt-auto flex flex-wrap items-center justify-end gap-2 border-t border-border/60 pt-3">
                            @if ($reservation->status->value === 'in_use')
                                @can('manage', $reservation)
                                    <form method="POST" action="{{ route('keti.reservations.finish', $reservation) }}">
                                        @csrf
                                        <x-button size="sm" type="submit" icon="check">Selesai menggunakan</x-button>
                                    </form>
                                @endcan
                            @endif
                            <x-button size="sm" variant="soft" :href="route('keti.reservations.show', $reservation)">Lihat pengganti</x-button>
                        </div>
                    </x-card>
                @endforeach
                @foreach ($onlineReplacements as $onlineReschedule)
                    @php
                        $origStart = CarbonImmutable::instance($onlineReschedule->original_start_at);
                        $targetStart = $onlineReschedule->effectiveStart();
                        $nowTime = CarbonImmutable::now();
                    @endphp
                    <x-card class="sicma-card-interactive sicma-card-enter flex flex-col gap-3.5 hover:border-primary/35">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <span class="text-[11px] font-semibold uppercase tracking-wide text-primary">Kuliah Pengganti • {{ $onlineReschedule->session_title }}</span>
                                <h3 class="text-lg font-semibold leading-snug text-ink">Kuliah Online (Tanpa Ruangan)</h3>
                            </div>
                            <x-status-badge variant="primary">Online</x-status-badge>
                        </div>
                        <dl class="grid grid-cols-1 gap-2.5 rounded-control bg-page px-3.5 py-2.5 sm:grid-cols-2 sm:items-center">
                            <div class="flex flex-col gap-0.5">
                                <dt class="text-[11px] font-medium text-muted">Mode kelas</dt>
                                <dd class="inline-flex items-center gap-1.5 text-sm font-semibold text-ink">
                                    <i data-lucide="globe" aria-hidden="true" class="size-3.5 shrink-0 text-primary"></i>
                                    <span>Tanpa ruangan fisik</span>
                                </dd>
                                <dd class="text-xs text-muted">{{ $onlineReschedule->originalRoom?->name ?? 'Tanpa ruang' }} dilepas</dd>
                            </div>
                            <div class="flex flex-col gap-0.5 sm:items-end sm:text-right">
                                <dt class="text-[11px] font-medium text-muted">Waktu pengganti</dt>
                                <dd class="text-sm font-semibold text-ink tabular-nums">{{ Wita::range($targetStart, $onlineReschedule->effectiveEnd()) }}</dd>
                            </div>
                        </dl>
                        @if ($onlineReschedule->note)
                            <div class="rounded-control border border-primary/20 bg-primary-soft/35 px-3 py-2 text-xs text-ink">
                                <span class="font-semibold text-primary">Catatan:</span> {{ $onlineReschedule->note }}
                            </div>
                        @endif
                        <div class="flex flex-col gap-1 text-xs text-muted">
                            <p>Dipindah dari {{ Wita::dayName($origStart) }}, {{ Wita::longDate($origStart) }} • {{ Wita::clock($origStart, false) }}</p>
                            <p class="flex items-start gap-1.5">
                                <i data-lucide="user" aria-hidden="true" class="mt-0.5 size-3.5 shrink-0"></i>
                                <span><span class="font-medium text-ink">Pengajar:</span> {{ $onlineReschedule->lecturers->pluck('name')->implode(', ') ?: 'Perlu verifikasi' }}</span>
                            </p>
                        </div>
                        <div class="mt-auto flex flex-wrap justify-end gap-2 border-t border-border/60 pt-3">
                            @if ($origStart->greaterThan($nowTime))
                                <form method="POST" action="{{ route('keti.meetings.online.cancel', $onlineReschedule) }}">
                                    @csrf
                                    <x-button size="sm" type="submit" variant="secondary">Kembalikan ke luring</x-button>
                                </form>
                            @endif
                            @if ($targetStart->greaterThan($nowTime))
                                <x-button size="sm" variant="soft" :href="route('keti.meetings.reschedule', ['sesi' => $onlineReschedule->schedule_session_id, 'tanggal' => $origStart->toDateString(), 'pengganti_online' => $onlineReschedule->id])">Pindahkan</x-button>
                            @endif
                        </div>
                    </x-card>
                @endforeach
            </div>
        @endif

        <x-card class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">Pesanan kelas</h2>
                <x-button variant="secondary" :href="route('keti.search')">Cari ruangan kosong</x-button>
            </div>
            @forelse ($reservations as $reservation)
                <div class="flex flex-col gap-2 rounded-control border border-border px-4 py-3 transition hover:bg-primary-soft/40 sm:flex-row sm:items-center sm:justify-between">
                    <a href="{{ route('keti.reservations.show', $reservation) }}" class="flex flex-1 flex-col gap-2 rounded-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:flex-row sm:items-center sm:justify-between">
                        <span class="font-medium">{{ $reservation->room->name }} • {{ $reservation->purpose }}</span>
                        <span class="flex flex-wrap items-center gap-3 text-sm text-muted">
                            {{ Wita::range($reservation->start_at, $reservation->end_at) }}
                            <x-status-badge :variant="$reservation->status->badgeVariant()" :pulse="$reservation->status->value === 'in_use'">{{ $reservation->status->label() }}</x-status-badge>
                        </span>
                    </a>
                    @if ($reservation->status->value === 'in_use')
                        @can('manage', $reservation)
                            <form method="POST" action="{{ route('keti.reservations.finish', $reservation) }}" class="shrink-0">
                                @csrf
                                <x-button size="sm" type="submit" icon="check">Selesai menggunakan</x-button>
                            </form>
                        @endcan
                    @endif
                </div>
            @empty
                <p class="text-sm text-muted">Belum ada pesanan kelas pada tanggal ini.</p>
            @endforelse
        </x-card>
    </div>
@endsection
