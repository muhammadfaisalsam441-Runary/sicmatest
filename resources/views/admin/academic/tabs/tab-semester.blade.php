@use('App\Support\Wita')

            @if ($period)
                <div
                    class="grid grid-cols-1 lg:grid-cols-[1fr_420px] gap-6"
                    x-data="{
                        deleteExceptionModalOpen: false,
                        deleteExceptionUrl: '',
                        deleteExceptionLabel: '',
                        confirmDeleteException(url, label) {
                            this.deleteExceptionUrl = url;
                            this.deleteExceptionLabel = label;
                            this.deleteExceptionModalOpen = true;
                        },
                    }"
                    @keydown.escape.window="deleteExceptionModalOpen = false"
                >
                    <x-card class="flex flex-col gap-4">
                        <h2 class="text-lg font-semibold">Periode akademik</h2>
                        <form method="POST" action="{{ route('admin.academic.periods.update', $period) }}" class="flex flex-col gap-4">
                            @csrf
                            @method('PUT')
                            <x-field label="Semester & tahun akademik" name="name" required><x-input name="name" :value="old('name', $period->name)" required /></x-field>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <x-field label="Mulai kuliah" name="start_date" required><x-input type="date" name="start_date" :value="old('start_date', $period->start_date?->toDateString())" required /></x-field>
                                <x-field label="Akhir kuliah" name="end_date" required><x-input type="date" name="end_date" :value="old('end_date', $period->end_date?->toDateString())" required /></x-field>
                                <x-field label="Mulai UTS" name="uts_start"><x-input type="date" name="uts_start" :value="old('uts_start', $period->uts_start?->toDateString())" /></x-field>
                                <x-field label="Selesai UTS" name="uts_end"><x-input type="date" name="uts_end" :value="old('uts_end', $period->uts_end?->toDateString())" /></x-field>
                                <x-field label="Mulai UAS" name="uas_start"><x-input type="date" name="uas_start" :value="old('uas_start', $period->uas_start?->toDateString())" /></x-field>
                                <x-field label="Selesai UAS" name="uas_end"><x-input type="date" name="uas_end" :value="old('uas_end', $period->uas_end?->toDateString())" /></x-field>
                            </div>
                            <p class="text-xs text-muted">Zona waktu WITA. Tanggal UTS/UAS menentukan sesi "sebelum UTS" dan "setelah UTS". Perubahan berlaku pada draf berikutnya yang ditetapkan.</p>
                            <div class="flex justify-end"><x-button type="submit">Simpan kalender</x-button></div>
                        </form>
                    </x-card>

                    <x-card class="flex flex-col gap-4">
                        <h2 class="text-lg font-semibold">Hari libur & pengecualian tanggal</h2>
                        <form method="POST" action="{{ route('admin.academic.exceptions.store', $period) }}" class="flex flex-col gap-3">
                            @csrf
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <x-field label="Tanggal" name="date" required><x-input type="date" name="date" :value="old('date')" required /></x-field>
                                <x-field label="Sampai (opsional)" name="until"><x-input type="date" name="until" :value="old('until')" /></x-field>
                            </div>
                            <x-field label="Jenis" name="type"><x-select name="type" :options="['holiday' => 'Hari libur', 'no_class' => 'Tidak ada perkuliahan']" :value="old('type', 'holiday')" /></x-field>
                            <x-field label="Keterangan" name="note"><x-input name="note" :value="old('note')" placeholder="Contoh: Dies Natalis" /></x-field>
                            <x-button type="submit" variant="soft" icon="plus">Tambah hari libur</x-button>
                        </form>
                        <div class="flex flex-col divide-y divide-border">
                            @forelse ($period->calendarExceptions as $exception)
                                <div class="flex items-center justify-between gap-2 py-2 text-sm">
                                    <span><span class="font-medium">{{ Wita::dayName($exception->date) }}, {{ Wita::date($exception->date) }}</span> <span class="text-muted">• {{ $exception->note ?? $exception->type->label() }}</span></span>
                                    <button
                                        type="button"
                                        @click="confirmDeleteException('{{ route('admin.academic.exceptions.destroy', $exception) }}', @js(Wita::date($exception->date)))"
                                        class="inline-flex shrink-0 items-center gap-1 rounded-control border border-danger/30 bg-danger-soft/30 px-2.5 py-1 text-xs font-semibold text-danger hover:bg-danger hover:text-white transition cursor-pointer"
                                        aria-label="Hapus {{ Wita::date($exception->date) }}"
                                    >
                                        <i data-lucide="trash-2" class="size-3.5" aria-hidden="true"></i>
                                        <span>Hapus</span>
                                    </button>
                                </div>
                            @empty
                                <p class="py-3 text-sm text-muted">Belum ada hari libur.</p>
                            @endforelse
                        </div>
                    </x-card>

                    <div
                        x-show="deleteExceptionModalOpen"
                        x-cloak
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="delete-exception-modal-title"
                        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-[2px]"
                    >
                        <div @click.outside="deleteExceptionModalOpen = false" class="w-full max-w-md rounded-2xl border border-border bg-white p-6 shadow-2xl">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 id="delete-exception-modal-title" class="text-base font-bold text-ink">Hapus hari libur?</h3>
                                <button type="button" @click="deleteExceptionModalOpen = false" aria-label="Tutup dialog" class="cursor-pointer rounded-md p-1 text-muted hover:bg-slate-100 hover:text-ink">
                                    <i data-lucide="x" class="size-4" aria-hidden="true"></i>
                                </button>
                            </div>
                            <p class="mb-5 text-sm text-muted">
                                Pengecualian tanggal <strong class="text-ink" x-text="deleteExceptionLabel"></strong> akan dihapus dari kalender akademik.
                            </p>
                            <form method="POST" :action="deleteExceptionUrl" class="flex justify-end gap-2">
                                @csrf
                                @method('DELETE')
                                <x-button type="button" variant="ghost" @click="deleteExceptionModalOpen = false">Batal</x-button>
                                <x-button type="submit" variant="danger" icon="trash-2">Hapus</x-button>
                            </form>
                        </div>
                    </div>
                </div>
            @else
                <x-empty-state title="Belum ada semester aktif" message="Tambahkan semester lewat seeder atau hubungi pengelola sistem." icon="calendar" />
            @endif
