@use('App\Support\Wita')

            @if ($period)
                <div class="grid grid-cols-[1fr_420px] gap-6">
                    <x-card class="flex flex-col gap-4">
                        <h2 class="text-lg font-semibold">Periode akademik</h2>
                        <form method="POST" action="{{ route('admin.academic.periods.update', $period) }}" class="flex flex-col gap-4">
                            @csrf
                            @method('PUT')
                            <x-field label="Semester & tahun akademik" name="name"><x-input name="name" :value="$period->name" required /></x-field>
                            <div class="grid grid-cols-2 gap-3">
                                <x-field label="Mulai kuliah" name="start_date"><x-input type="date" name="start_date" :value="$period->start_date?->toDateString()" required /></x-field>
                                <x-field label="Akhir kuliah" name="end_date"><x-input type="date" name="end_date" :value="$period->end_date?->toDateString()" required /></x-field>
                                <x-field label="Mulai UTS" name="uts_start"><x-input type="date" name="uts_start" :value="$period->uts_start?->toDateString()" /></x-field>
                                <x-field label="Selesai UTS" name="uts_end"><x-input type="date" name="uts_end" :value="$period->uts_end?->toDateString()" /></x-field>
                                <x-field label="Mulai UAS" name="uas_start"><x-input type="date" name="uas_start" :value="$period->uas_start?->toDateString()" /></x-field>
                                <x-field label="Selesai UAS" name="uas_end"><x-input type="date" name="uas_end" :value="$period->uas_end?->toDateString()" /></x-field>
                            </div>
                            <p class="text-xs text-muted">Zona waktu WITA. Tanggal UTS/UAS menentukan sesi "sebelum UTS" dan "setelah UTS". Perubahan berlaku pada draf berikutnya yang ditetapkan.</p>
                            <div class="flex justify-end"><x-button type="submit">Simpan kalender</x-button></div>
                        </form>
                    </x-card>

                    <x-card class="flex flex-col gap-4">
                        <h2 class="text-lg font-semibold">Hari libur & pengecualian tanggal</h2>
                        <form method="POST" action="{{ route('admin.academic.exceptions.store', $period) }}" class="flex flex-col gap-3">
                            @csrf
                            <div class="grid grid-cols-2 gap-3">
                                <x-field label="Tanggal" name="date"><x-input type="date" name="date" required /></x-field>
                                <x-field label="Sampai (opsional)" name="until"><x-input type="date" name="until" /></x-field>
                            </div>
                            <x-field label="Jenis" name="type"><x-select name="type" :options="['holiday' => 'Hari libur', 'no_class' => 'Tidak ada perkuliahan']" /></x-field>
                            <x-field label="Keterangan" name="note"><x-input name="note" placeholder="Contoh: Dies Natalis" /></x-field>
                            <x-button type="submit" variant="soft" icon="plus">Tambah hari libur</x-button>
                        </form>
                        <div class="flex flex-col divide-y divide-border">
                            @forelse ($period->calendarExceptions as $exception)
                                <div class="flex items-center justify-between py-2 text-sm">
                                    <span><span class="font-medium">{{ Wita::dayName($exception->date) }}, {{ Wita::date($exception->date) }}</span> <span class="text-muted">• {{ $exception->note ?? $exception->type->label() }}</span></span>
                                    <form method="POST" action="{{ route('admin.academic.exceptions.destroy', $exception) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex items-center gap-1 rounded-control border border-danger/30 bg-danger-soft/30 px-2.5 py-1 text-xs font-semibold text-danger hover:bg-danger hover:text-white transition cursor-pointer" aria-label="Hapus {{ Wita::date($exception->date) }}" onclick="return confirm('Hapus hari libur {{ Wita::date($exception->date) }}?')">
                                            <i data-lucide="trash-2" class="size-3.5"></i>
                                            <span>Hapus</span>
                                        </button>
                                    </form>
                                </div>
                            @empty
                                <p class="py-3 text-sm text-muted">Belum ada hari libur.</p>
                            @endforelse
                        </div>
                    </x-card>
                </div>
            @else
                <x-empty-state title="Belum ada semester aktif" message="Tambahkan semester lewat seeder atau hubungi pengelola sistem." icon="calendar" />
            @endif
