{{-- MODAL 1: ATUR TIM / TAMBAH & EDIT PENUGASAN DOSEN --}}
<div x-show="quickModalOpen" x-cloak data-modal-name="quick-assign-modal"
     role="dialog" aria-modal="true" aria-labelledby="quick-assign-modal-title"
     class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="quickModalOpen = false"></div>
    <div class="relative flex max-h-[90vh] w-full max-w-xl flex-col overflow-y-auto rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <div>
                <span class="text-xs font-semibold text-primary" x-text="editingAssignmentId ? 'Ubah Penugasan Dosen' : 'Tambah Pengajar Kelas'"></span>
                <h3 id="quick-assign-modal-title" class="text-lg font-bold text-ink" x-text="activeOffering.course"></h3>
                <p class="text-xs text-muted">
                    Kelas: <span class="font-semibold text-ink" x-text="activeOffering.classGroup"></span>
                    <template x-if="activeOffering.sks">
                        <span> • <span class="font-semibold text-ink" x-text="`${activeOffering.sks} SKS`"></span></span>
                    </template>
                </p>
            </div>
            <button type="button" @click="quickModalOpen = false" aria-label="Tutup" class="cursor-pointer rounded p-1 text-muted hover:text-ink">
                <i data-lucide="x" class="size-5" aria-hidden="true"></i>
            </button>
        </div>

        {{-- Daftar Anggota Tim Saat Ini pada Kelas Ini --}}
        <template x-if="activeOffering.team && activeOffering.team.length > 0">
            <div class="mt-4 rounded-xl border border-border bg-page p-3">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <span class="text-xs font-semibold text-ink">Tim Pengajar Saat Ini</span>
                    <button type="button"
                            @click="switchToCreate('partner')"
                            class="inline-flex cursor-pointer items-center gap-1 rounded-md bg-primary-soft px-2 py-1 text-[11px] font-semibold text-primary transition hover:bg-primary hover:text-white">
                        <span>+ Tambah Anggota Baru</span>
                    </button>
                </div>
                <div class="flex flex-col gap-1.5">
                    <template x-for="member in activeOffering.team" :key="member.id">
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2 text-xs transition"
                             x-data="{ confirmingDelete: false }"
                             :class="editingAssignmentId === member.id ? 'border-primary bg-primary-soft/40' : 'border-border bg-surface'">
                            <div class="flex flex-col">
                                <div class="flex items-center gap-1.5">
                                    <span class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase"
                                          :class="member.role === 'lead' ? 'bg-primary text-white' : 'bg-page text-muted border border-border'"
                                          x-text="member.role_label"></span>
                                    <span class="font-semibold text-ink" x-text="member.lecturer_name"></span>
                                </div>
                                <span class="mt-0.5 text-[11px] text-muted">
                                    Periode: <strong class="text-ink" x-text="member.phase_label"></strong>
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5">
                                {{-- Toggle Lock --}}
                                <form method="POST" :action="@js($lockUrlTemplate).replace('__ID__', member.id)" class="inline">
                                    @csrf
                                    <button type="submit"
                                            class="inline-flex cursor-pointer items-center gap-1 rounded-md border border-border bg-surface px-2 py-1 text-[11px] font-medium transition hover:border-primary hover:text-primary"
                                            :class="member.is_locked ? 'text-primary' : 'text-muted'"
                                            :title="member.is_locked ? 'Klik untuk buka kunci' : 'Klik untuk kunci penugasan'">
                                        <i x-show="member.is_locked" data-lucide="lock" class="size-3" aria-hidden="true"></i>
                                        <i x-show="!member.is_locked" data-lucide="lock-open" class="size-3" aria-hidden="true"></i>
                                        <span x-text="member.is_locked ? 'Terkunci' : 'Kunci'"></span>
                                    </button>
                                </form>

                                {{-- Edit Button --}}
                                <template x-if="!member.is_locked && !confirmingDelete">
                                    <button type="button"
                                            @click="openEditAssignment(activeOffering, member)"
                                            class="inline-flex cursor-pointer items-center gap-1 rounded-control px-2.5 py-1 text-xs font-semibold transition"
                                            :class="editingAssignmentId === member.id ? 'bg-primary text-white' : 'border border-border bg-surface text-primary hover:border-primary/40 hover:bg-primary-soft'">
                                        <i data-lucide="pencil" class="size-3.5" aria-hidden="true"></i>
                                        <span x-text="editingAssignmentId === member.id ? 'Sedang Diedit' : 'Edit'"></span>
                                    </button>
                                </template>

                                {{-- Delete Button with inline confirmation --}}
                                <template x-if="!member.is_locked && !confirmingDelete">
                                    <button type="button"
                                            @click="confirmingDelete = true"
                                            class="inline-flex cursor-pointer items-center gap-1 rounded-control border border-danger/30 bg-danger-soft/30 px-2.5 py-1 text-xs font-semibold text-danger transition hover:bg-danger hover:text-white"
                                            title="Hapus pengajar dari tim">
                                        <i data-lucide="trash-2" class="size-3.5" aria-hidden="true"></i>
                                        <span>Hapus</span>
                                    </button>
                                </template>
                                <template x-if="!member.is_locked && confirmingDelete">
                                    <div class="inline-flex items-center gap-1">
                                        <button type="button" @click="confirmingDelete = false" class="cursor-pointer rounded-control border border-border bg-surface px-2 py-1 text-[11px] font-medium text-muted hover:text-ink">Batal</button>
                                        <form method="POST" :action="@js($destroyUrlTemplate).replace('__ID__', member.id)" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="cursor-pointer rounded-control bg-danger px-2 py-1 text-[11px] font-semibold text-white hover:bg-danger/90">Ya, Hapus</button>
                                        </form>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        <form method="POST"
              :action="editingAssignmentId ? @js($updateUrlTemplate).replace('__ID__', editingAssignmentId) : @js(route('admin.teaching.store'))"
              class="mt-4 flex flex-col gap-4">
            @csrf
            <template x-if="editingAssignmentId">
                <input type="hidden" name="_method" value="PUT">
            </template>
            <input type="hidden" name="course_offering_id" :value="activeOffering.id">

            <div>
                <div class="mb-1.5 flex items-center justify-between">
                    <label for="quick-lecturer-id" class="text-xs font-semibold text-ink">
                        <span x-text="editingAssignmentId ? 'Ganti / Pilih Dosen Pengajar' : 'Pilih Dosen Pengajar Baru'"></span>
                        <span class="text-danger">*</span>
                    </label>
                    <span class="text-[11px] text-muted" x-show="quickLecturers.length > 0" x-text="`${quickLecturers.length} dosen rekomendasi MK ini`"></span>
                </div>

                <div class="relative mb-2">
                    <input id="quick-lecturer-search"
                           type="text"
                           x-model="quickSearch"
                           aria-label="Filter daftar dosen pengajar"
                           placeholder="Ketik nama dosen untuk memfilter daftar pilihan di bawah..."
                           class="w-full rounded-control border border-border bg-page px-3 py-1.5 text-xs text-ink focus:border-primary focus:bg-surface focus:outline-none">
                </div>

                <select id="quick-lecturer-id" name="lecturer_id" x-model="quickLecturerId" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    <option value="">-- Pilih Dosen Pengajar --</option>
                    <template x-if="quickLecturers.length > 0">
                        <optgroup label="Dosen Pengampu Mata Kuliah Ini (Rekomendasi)">
                            <template x-for="lecturer in quickLecturers" :key="'rec-' + lecturer.id">
                                <option :value="String(lecturer.id)"
                                        :selected="String(lecturer.id) === String(quickLecturerId)"
                                        x-text="`${lecturer.name} — ${lecturer.sks_info}${lecturer.verified ? '' : ' • Perlu verifikasi'}`"></option>
                            </template>
                        </optgroup>
                    </template>
                    <template x-if="otherLecturers.length > 0">
                        <optgroup label="Semua Dosen Aktif Lainnya">
                            <template x-for="lecturer in otherLecturers" :key="'all-' + lecturer.id">
                                <option :value="String(lecturer.id)"
                                        :selected="String(lecturer.id) === String(quickLecturerId)"
                                        x-text="`${lecturer.name} — ${lecturer.sks_info}${lecturer.verified ? '' : ' • Perlu verifikasi'}`"></option>
                            </template>
                        </optgroup>
                    </template>
                </select>
                <p class="mt-1 text-[11px] text-muted">
                    Dosen rekomendasi diurutkan di bagian atas berdasarkan riwayat mengajar & bidang keahlian mata kuliah ini.
                </p>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label for="quick-role" class="mb-1 block text-xs font-medium text-muted">Peran Dosen</label>
                    <select id="quick-role" name="role" x-model="activeOffering.role" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        <option value="lead">Pengampu (Utama)</option>
                        <option value="partner">Mitra (Pendamping)</option>
                    </select>
                </div>
                <div>
                    <label for="quick-phase" class="mb-1 block text-xs font-medium text-muted">Periode Mengajar</label>
                    <select id="quick-phase" name="phase" x-model="quickPhase" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        <option value="full">Satu semester penuh (1–14)</option>
                        <option value="before_uts">Pertemuan 1–7 (sampai UTS)</option>
                        <option value="after_uts">Pertemuan 8–14 (sampai UAS)</option>
                    </select>
                </div>
            </div>

            <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-button variant="secondary" type="button" @click="quickModalOpen = false">Batal</x-button>
                <x-button variant="primary" type="submit" icon="check">
                    <span x-text="editingAssignmentId ? 'Simpan Perubahan' : 'Simpan Penugasan'"></span>
                </x-button>
            </div>
        </form>
    </div>
</div>
