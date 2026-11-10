            <div class="flex flex-col gap-6" x-data="classManager(@js($classesPayload ?? []))" @sicma:navigated.document="batchModalOpen = false">
                {{-- Toolbar Pencarian, Filter Prodi, dan Tombol Mode Edit Massal --}}
                <x-card class="flex flex-col gap-4">
                    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-border pb-4">
                        {{-- Filter Tabs Status --}}
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" @click="statusFilter = 'semua'"
                                    :class="statusFilter === 'semua' ? 'bg-primary text-white' : 'bg-page text-muted hover:text-ink hover:bg-border'"
                                    class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition cursor-pointer">
                                Semua ({{ $classGroups->count() }})
                            </button>
                            <button type="button" @click="statusFilter = 'perlu_dilengkapi'"
                                    :class="statusFilter === 'perlu_dilengkapi' ? 'bg-danger text-white' : 'bg-danger-soft/60 text-danger hover:bg-danger-soft'"
                                    class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition cursor-pointer">
                                ⚠️ Perlu Dilengkapi ({{ $classStats['needs_attention'] ?? 0 }})
                            </button>
                            <button type="button" @click="statusFilter = 'lengkap'"
                                    :class="statusFilter === 'lengkap' ? 'bg-success text-white' : 'bg-success-soft/60 text-success hover:bg-success-soft'"
                                    class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition cursor-pointer">
                                ✅ Lengkap ({{ $classStats['complete'] ?? 0 }})
                            </button>
                            <button type="button" @click="statusFilter = 'arsip'"
                                    :class="statusFilter === 'arsip' ? 'bg-primary text-white' : 'bg-page text-muted hover:text-ink hover:bg-border'"
                                    class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition cursor-pointer">
                                Arsip ({{ $classStats['inactive'] ?? 0 }})
                            </button>
                        </div>

                        {{-- Tombol Toggle Edit Massal di Tabel --}}
                        <div class="flex items-center gap-2">
                            <button type="button" @click="editMode = !editMode"
                                    :class="editMode ? 'bg-amber-500 text-white ring-2 ring-amber-400/30' : 'bg-page text-ink border border-border hover:bg-primary-soft hover:text-primary'"
                                    class="inline-flex items-center gap-1.5 rounded-control px-3.5 py-2 text-xs font-bold transition shadow-xs cursor-pointer">
                                <i data-lucide="pencil" class="size-3.5"></i>
                                <span x-text="editMode ? 'Tutup Edit Massal' : '⚡ Edit Massal di Tabel'"></span>
                            </button>
                        </div>
                    </div>

                    {{-- Form Pencarian & Filter Prodi/Angkatan --}}
                    <div class="grid grid-cols-1 items-end gap-3 sm:grid-cols-12">
                        <div class="sm:col-span-6">
                            <label class="mb-1 block text-xs font-medium text-muted">Cari Nama Kelas / Kode / Prodi</label>
                            <div class="relative">
                                <i data-lucide="search" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted"></i>
                                <input type="text" x-model="search" placeholder="Ketik nama kelas (misal: PTIK A, Tekom C)..."
                                        class="w-full rounded-control border border-border bg-surface pl-9 pr-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                            </div>
                        </div>

                        <div class="sm:col-span-4">
                            <label class="mb-1 block text-xs font-medium text-muted">Filter Program Studi</label>
                            <select x-model="prodiFilter" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                <option value="">Semua Program Studi</option>
                                @foreach ($distinctPrograms ?? [] as $dp)
                                    <option value="{{ $dp }}">{{ $dp }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="sm:col-span-2">
                            <button type="button" @click="search = ''; prodiFilter = ''; statusFilter = 'semua'"
                                    x-show="search !== '' || prodiFilter !== '' || statusFilter !== 'semua'"
                                    class="inline-flex h-[38px] w-full items-center justify-center gap-1.5 rounded-control border border-border px-3 text-xs font-semibold text-muted hover:text-ink hover:bg-page transition cursor-pointer">
                                <i data-lucide="rotate-ccw" class="size-3.5"></i>
                                <span>Reset Filter</span>
                            </button>
                        </div>
                    </div>

                    {{-- Bar Bantuan Cepat saat Mode Edit Massal Aktif --}}
                    <div x-show="editMode" x-cloak class="rounded-xl border border-primary/30 bg-primary-soft/30 p-3.5 flex flex-wrap items-center justify-between gap-3 animate-in fade-in duration-200">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="font-bold text-primary flex items-center gap-1.5">
                                <i data-lucide="pencil" class="size-4"></i> Mode Edit Massal Aktif
                            </span>
                            <span class="text-muted">| Isi langsung angka peserta di tabel, atau isi serentak kelas yang masih kosong:</span>
                            <div class="flex items-center gap-1.5 ml-1">
                                <input type="number" min="1" max="500" x-model="fillEmptyValue" class="w-16 rounded border border-border bg-surface px-2 py-1 text-center text-xs font-semibold text-ink focus:border-primary focus:outline-none">
                                <button type="button" @click="applyFillEmpty()" class="rounded bg-surface px-2.5 py-1 text-xs font-semibold text-primary border border-primary/40 hover:bg-primary hover:text-white transition shadow-xs cursor-pointer">
                                    Terapkan ke Yang Kosong
                                </button>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="editMode = false" class="rounded-control border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-muted hover:text-ink transition cursor-pointer">
                                Batal
                            </button>
                            <button type="submit" form="bulkClassForm" class="inline-flex items-center gap-1.5 rounded-control bg-primary px-4 py-1.5 text-xs font-bold text-white hover:bg-primary/90 transition shadow-sm cursor-pointer">
                                <i data-lucide="check" class="size-4"></i>
                                Simpan Semua Perubahan
                            </button>
                        </div>
                    </div>

                    {{-- TABEL KELAS --}}
                    <div class="overflow-x-auto rounded-lg border border-border">
                        <table class="w-full border-collapse text-left text-sm">
                            <thead class="border-b border-border bg-page text-xs font-semibold text-muted">
                                <tr>
                                    <th class="w-10 px-4 py-3 text-center">
                                        <input type="checkbox"
                                                @change="toggleSelectAll($event.target.checked)"
                                                :checked="isAllSelected()"
                                                class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer"
                                                title="Pilih semua yang tampil">
                                    </th>
                                    <th class="px-4 py-3 font-semibold">Nama Kelas & Kode</th>
                                    <th class="px-4 py-3 font-semibold">Program Studi</th>
                                    <th class="px-4 py-3 font-semibold text-center">Angkatan</th>
                                    <th class="px-4 py-3 font-semibold text-center">Jadwal</th>
                                    <th class="px-4 py-3 font-semibold text-center">Jumlah Mahasiswa</th>
                                    <th class="px-4 py-3 font-semibold text-center">Status</th>
                                    <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse ($classGroups as $index => $classGroup)
                                    @php
                                        $searchStr = mb_strtolower($classGroup->name.' '.$classGroup->study_program.' '.$classGroup->cohort_year.' '.$classGroup->code);
                                        $needsAttention = $classGroup->student_count === null;
                                        $statusKey = ! $classGroup->is_active ? 'arsip' : ($needsAttention ? 'perlu_dilengkapi' : 'lengkap');
                                    @endphp
                                    <tr data-id="{{ $classGroup->id }}"
                                        data-search="{{ $searchStr }}"
                                        data-status="{{ $statusKey }}"
                                        data-prodi="{{ $classGroup->study_program }}"
                                        x-show="
                                            (!search.trim() || $el.dataset.search.includes(search.trim().toLowerCase())) &&
                                            (statusFilter === 'semua' || $el.dataset.status === statusFilter) &&
                                            (!prodiFilter || $el.dataset.prodi === prodiFilter)
                                        "
                                        class="border-b border-border transition hover:bg-primary-soft/20"
                                        :class="{ 'bg-primary-soft/30': selectedIds.map(Number).includes({{ $classGroup->id }}) }">

                                        {{-- Checkbox --}}
                                        <td class="px-4 py-3 text-center">
                                            <input type="checkbox"
                                                   value="{{ $classGroup->id }}"
                                                   x-model.number="selectedIds"
                                                   class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer">
                                        </td>

                                        {{-- Nama Kelas & Kode --}}
                                        <td class="px-4 py-3">
                                            <div x-show="!editMode" class="flex flex-col">
                                                <span class="font-semibold text-ink">{{ $classGroup->name }}</span>
                                                <span class="font-mono text-[11px] text-muted">{{ $classGroup->code }}</span>
                                            </div>
                                            <div x-show="editMode" x-cloak class="flex flex-col gap-1">
                                                <input type="text"
                                                       form="bulkClassForm"
                                                       name="classes[{{ $index }}][name]"
                                                       :disabled="!editMode"
                                                       x-model="classesData[{{ $classGroup->id }}].name"
                                                       required
                                                       class="w-36 rounded-control border border-border bg-surface px-2.5 py-1 text-sm font-semibold text-ink focus:border-primary focus:outline-none">
                                                <span class="font-mono text-[10px] text-muted">{{ $classGroup->code }}</span>
                                            </div>
                                        </td>

                                        {{-- Program Studi --}}
                                        <td class="px-4 py-3">
                                            <span x-show="!editMode" class="text-xs text-muted">{{ $classGroup->study_program }}</span>
                                            <div x-show="editMode" x-cloak>
                                                <input type="text"
                                                       form="bulkClassForm"
                                                       name="classes[{{ $index }}][study_program]"
                                                       :disabled="!editMode"
                                                       x-model="classesData[{{ $classGroup->id }}].study_program"
                                                       list="prodi-options-grid"
                                                       required
                                                       class="w-48 rounded-control border border-border bg-surface px-2.5 py-1 text-xs text-ink focus:border-primary focus:outline-none">
                                            </div>
                                        </td>

                                        {{-- Angkatan --}}
                                        <td class="px-4 py-3 text-center">
                                            <span x-show="!editMode" class="text-sm font-medium text-ink">{{ $classGroup->cohort_year }}</span>
                                            <div x-show="editMode" x-cloak class="flex justify-center">
                                                <input type="number" min="2000" max="2100"
                                                       form="bulkClassForm"
                                                       name="classes[{{ $index }}][cohort_year]"
                                                       :disabled="!editMode"
                                                       x-model="classesData[{{ $classGroup->id }}].cohort_year"
                                                       required
                                                       class="w-20 rounded-control border border-border bg-surface px-2 py-1 text-center text-sm text-ink focus:border-primary focus:outline-none">
                                            </div>
                                        </td>

                                        {{-- Sesi Jadwal --}}
                                        <td class="px-4 py-3 text-center">
                                            <span class="inline-flex items-center rounded-md bg-page px-2 py-0.5 text-xs font-medium text-muted ring-1 ring-border">
                                                {{ $classGroup->schedule_sessions_count }} sesi
                                            </span>
                                        </td>

                                        {{-- Jumlah Mahasiswa (Peserta) --}}
                                        <td class="px-4 py-3 text-center">
                                            <div x-show="!editMode">
                                                @if ($classGroup->student_count)
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-page px-2.5 py-0.5 text-xs font-semibold text-ink border border-border">
                                                        <i data-lucide="users" class="size-3 text-muted"></i>
                                                        {{ $classGroup->student_count }} mhs
                                                    </span>
                                                @else
                                                    <button type="button" @click="editMode = true" class="inline-flex items-center gap-1 text-xs font-semibold text-danger hover:underline cursor-pointer" title="Klik untuk edit cepat">
                                                        ⚠️ Belum diisi
                                                    </button>
                                                @endif
                                            </div>
                                            <div x-show="editMode" x-cloak class="flex justify-center">
                                                <input type="number" min="1" max="500"
                                                       form="bulkClassForm"
                                                       name="classes[{{ $index }}][student_count]"
                                                       :disabled="!editMode"
                                                       x-model="classesData[{{ $classGroup->id }}].student_count"
                                                       placeholder="0 mhs"
                                                       class="w-24 rounded-control border border-primary/40 bg-surface px-2.5 py-1 text-center text-sm font-bold text-ink focus:border-primary focus:ring-1 focus:ring-primary focus:outline-none shadow-xs">
                                            </div>
                                        </td>

                                        {{-- Status Data / Aktif --}}
                                        <td class="px-4 py-3 text-center">
                                            <div x-show="!editMode">
                                                @if (! $classGroup->is_active)
                                                    <x-status-badge>Arsip</x-status-badge>
                                                @elseif ($classGroup->student_count === null)
                                                    <x-status-badge variant="warning">Perlu dilengkapi</x-status-badge>
                                                @else
                                                    <x-status-badge variant="success">Lengkap</x-status-badge>
                                                @endif
                                            </div>
                                            <div x-show="editMode" x-cloak class="flex items-center justify-center gap-1.5">
                                                <input type="hidden" form="bulkClassForm" name="classes[{{ $index }}][id]" value="{{ $classGroup->id }}" :disabled="!editMode">
                                                <input type="hidden" form="bulkClassForm" name="classes[{{ $index }}][is_active]" value="0" :disabled="!editMode">
                                                <label class="inline-flex items-center gap-1 text-xs cursor-pointer">
                                                    <input type="checkbox"
                                                           form="bulkClassForm"
                                                           name="classes[{{ $index }}][is_active]"
                                                           value="1"
                                                           :disabled="!editMode"
                                                           x-model="classesData[{{ $classGroup->id }}].is_active"
                                                           class="size-4 accent-primary rounded cursor-pointer">
                                                    <span class="text-[11px]" x-text="classesData[{{ $classGroup->id }}].is_active ? 'Aktif' : 'Nonaktif'"></span>
                                                </label>
                                            </div>
                                        </td>

                                        {{-- Aksi --}}
                                        <td class="px-4 py-3 text-right">
                                            <div class="flex items-center justify-end gap-1.5">
                                                <button type="button"
                                                        class="inline-flex items-center gap-1 rounded-control border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-primary hover:bg-primary-soft hover:border-primary/40 transition cursor-pointer"
                                                        x-data x-on:click="$dispatch('open-modal', 'class-{{ $classGroup->id }}')">
                                                    <i data-lucide="pencil" class="size-3.5"></i>
                                                    <span>Edit</span>
                                                </button>
                                                <form method="POST" action="{{ route('admin.academic.classes.destroy', $classGroup) }}" class="inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            class="inline-flex items-center gap-1 rounded-control border border-danger/30 bg-danger-soft/30 px-2.5 py-1 text-xs font-semibold text-danger hover:bg-danger hover:text-white transition cursor-pointer"
                                                            onclick="return confirm('Hapus kelas {{ $classGroup->name }}?')">
                                                        <i data-lucide="trash-2" class="size-3.5"></i>
                                                        <span>Hapus</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="py-12 text-center text-muted">
                                            <i data-lucide="users" class="mx-auto size-8 text-muted/60 mb-2"></i>
                                            <p class="font-medium text-ink">Belum ada data kelas.</p>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- Footer jika dalam editMode --}}
                    <div x-show="editMode" x-cloak class="flex items-center justify-between border-t border-border pt-4">
                        <span class="text-xs text-muted">Simpan setelah selesai mengubah data kelas di atas.</span>
                        <div class="flex gap-2">
                            <button type="button" @click="editMode = false" class="rounded-control border border-border bg-surface px-4 py-2 text-xs font-semibold text-muted hover:text-ink transition cursor-pointer">
                                Batal
                            </button>
                            <button type="submit" form="bulkClassForm" class="inline-flex items-center gap-1.5 rounded-control bg-primary px-5 py-2 text-xs font-bold text-white hover:bg-primary/90 transition shadow-sm cursor-pointer">
                                <i data-lucide="check" class="size-4"></i>
                                Simpan Semua Perubahan
                            </button>
                        </div>
                    </div>
                </x-card>

                {{-- FLOATING BATCH ACTION BAR --}}
                <div x-show="selectedIds.length > 0 && !editMode"
                     x-cloak
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 translate-y-4"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 translate-y-0"
                     x-transition:leave-end="opacity-0 translate-y-4"
                     class="fixed bottom-6 left-1/2 z-40 flex -translate-x-1/2 flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-ink px-6 py-3.5 text-surface shadow-2xl">
                    <div class="flex items-center gap-2">
                        <i data-lucide="check-circle" class="size-5 text-success"></i>
                        <span class="text-sm font-semibold"><span x-text="selectedIds.length"></span> kelas dipilih</span>
                    </div>
                    <div class="h-4 w-px bg-surface/20"></div>

                    {{-- 1. Atur Mahasiswa Modal --}}
                    <button type="button" @click="openBatchModal('set_student_count', 'Atur Jumlah Mahasiswa')" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-primary/90 transition cursor-pointer">
                        <i data-lucide="users" class="size-3.5"></i>
                        Set Mahasiswa
                    </button>

                    {{-- 2. Set Angkatan Modal --}}
                    <button type="button" @click="openBatchModal('set_cohort_year', 'Set Tahun Angkatan')" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                        <i data-lucide="calendar" class="size-3.5"></i>
                        Set Angkatan
                    </button>

                    {{-- 3. Set Prodi Modal --}}
                    <button type="button" @click="openBatchModal('set_study_program', 'Set Program Studi')" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                        <i data-lucide="building-2" class="size-3.5"></i>
                        Set Prodi
                    </button>

                    {{-- 4. Aktifkan --}}
                    <button type="button" @click="submitBatch('activate')" class="inline-flex items-center gap-1.5 rounded-lg bg-surface/10 px-3 py-1.5 text-xs font-semibold text-surface hover:bg-surface/20 transition cursor-pointer">
                        <i data-lucide="check" class="size-3.5"></i>
                        Aktifkan
                    </button>

                    {{-- 5. Arsipkan --}}
                    <button type="button" @click="submitBatch('archive')" class="inline-flex items-center gap-1.5 rounded-lg bg-danger/80 px-3 py-1.5 text-xs font-semibold text-white hover:bg-danger transition cursor-pointer">
                        <i data-lucide="lock" class="size-3.5"></i>
                        Arsipkan
                    </button>

                    <button type="button" @click="selectedIds = []" class="text-xs text-surface/70 hover:text-white transition ml-2 cursor-pointer">
                        Batal
                    </button>
                </div>

                {{-- FORM TERSEMBUNYI UNTUK BATCH ACTION --}}
                <form x-ref="batchForm" method="POST" action="{{ route('admin.academic.classes.batch') }}" class="hidden">
                    @csrf
                    <input type="hidden" name="action" x-model="batchAction">
                    <input type="hidden" name="student_count" x-model="batchValue">
                    <input type="hidden" name="cohort_year" x-model="batchValue">
                    <input type="hidden" name="study_program" x-model="batchValue">
                    <template x-for="id in selectedIds" :key="id">
                        <input type="hidden" name="class_ids[]" :value="id">
                    </template>
                </form>

                {{-- FORM TERSEMBUNYI UNTUK BULK EDIT GRID --}}
                <form id="bulkClassForm" method="POST" action="{{ route('admin.academic.classes.bulk-update') }}" class="hidden">
                    @csrf
                    @method('PUT')
                </form>

                {{-- MODAL BATCH ATUR NILAI --}}
                <div x-show="batchModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="batchModalOpen = false"></div>
                    <div class="relative w-full max-w-md rounded-2xl bg-surface p-6 shadow-2xl">
                        <div class="flex items-start justify-between border-b border-border pb-3">
                            <div>
                                <span class="text-xs font-semibold text-primary">Penyesuaian Massal</span>
                                <h3 class="text-lg font-bold text-ink" x-text="batchLabel + ' (' + selectedIds.length + ' Kelas)'"></h3>
                            </div>
                            <button type="button" @click="batchModalOpen = false" class="text-muted hover:text-ink">
                                <i data-lucide="x" class="size-5"></i>
                            </button>
                        </div>

                        <div class="mt-4 flex flex-col gap-4">
                            <template x-if="batchAction === 'set_student_count'">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-muted">Jumlah Mahasiswa per Kelas</label>
                                    <input type="number" min="1" max="500" x-model="batchValue" placeholder="Misal: 40" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                    <p class="mt-1 text-[11px] text-muted">Akan diterapkan ke seluruh kelas yang Anda centang.</p>
                                </div>
                            </template>

                            <template x-if="batchAction === 'set_cohort_year'">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-muted">Tahun Angkatan</label>
                                    <input type="number" min="2000" max="2100" x-model="batchValue" placeholder="Misal: 2026" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                </div>
                            </template>

                            <template x-if="batchAction === 'set_study_program'">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-muted">Nama Program Studi</label>
                                    <input type="text" x-model="batchValue" list="prodi-options-batch" placeholder="Misal: Pendidikan Teknik Informatika dan Komputer" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                                </div>
                            </template>

                            <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                                <x-button variant="secondary" type="button" @click="batchModalOpen = false">Batal</x-button>
                                <x-button variant="primary" type="button" icon="check" @click="submitBatch(); batchModalOpen = false">Terapkan Perubahan</x-button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Datalist options untuk prodi --}}
                <datalist id="prodi-options-grid">
                    @foreach ($distinctPrograms ?? [] as $dp)
                        <option value="{{ $dp }}"></option>
                    @endforeach
                </datalist>
                <datalist id="prodi-options-batch">
                    @foreach ($distinctPrograms ?? [] as $dp)
                        <option value="{{ $dp }}"></option>
                    @endforeach
                </datalist>
            </div>
