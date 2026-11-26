{{-- MODAL 1: EDIT / CREATE DINAMIS TUNGGAL --}}
<div x-show="formModalOpen" x-cloak data-modal-name="lecturer-profile"
     role="dialog" aria-modal="true" aria-labelledby="lecturer-modal-title"
     @keydown.escape.window="if (formModalOpen) formModalOpen = false"
     class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="formModalOpen = false"></div>
    <div class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <div>
                <span class="text-xs font-semibold text-primary" x-text="formLecturer.id ? 'Perbarui Profil Dosen' : 'Dosen Baru'"></span>
                <h3 id="lecturer-modal-title" class="text-lg font-bold text-ink" x-text="formLecturer.id ? formLecturer.name : 'Tambah Data Dosen'"></h3>
            </div>
            <button type="button" @click="formModalOpen = false" aria-label="Tutup modal profil dosen" class="cursor-pointer text-muted hover:text-ink">
                <i data-lucide="x" class="size-5" aria-hidden="true"></i>
            </button>
        </div>

        <form method="POST" action="{{ route('admin.lecturers.store') }}" :action="formLecturer.updateUrl || @js(route('admin.lecturers.store'))" class="mt-4 flex flex-col gap-4">
            @csrf
            <template x-if="formLecturer.id">
                <input type="hidden" name="_method" value="PUT">
            </template>
            <input type="hidden" name="form" value="lecturer">
            <input type="hidden" name="lecturer_id" :value="formLecturer.id || ''">
            <input type="hidden" name="update_url" :value="formLecturer.updateUrl || ''">

            @if ($errors->any() && $oldForm === 'lecturer')
                <x-alert variant="danger">{{ $errors->first() }}</x-alert>
            @endif

            <div>
                <label for="lecturer-name" class="mb-1 block text-xs font-medium text-muted">Nama Lengkap & Gelar <span class="text-danger">*</span></label>
                <input id="lecturer-name" type="text" name="name" x-model="formLecturer.name" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                @if ($oldForm === 'lecturer') @error('name') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label for="lecturer-nidn" class="mb-1 block text-xs font-medium text-muted">NIDN</label>
                    <input id="lecturer-nidn" type="text" name="nidn" x-model="formLecturer.nidn" placeholder="Nomor Induk Dosen Nasional" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    @if ($oldForm === 'lecturer') @error('nidn') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                </div>
                <div>
                    <label for="lecturer-homebase" class="mb-1 block text-xs font-medium text-muted">Asal / Homebase</label>
                    <select id="lecturer-homebase" name="homebase" x-model="formLecturer.homebase" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        <option value="">Belum diverifikasi</option>
                        <option value="jtik">Homebase JTIK</option>
                        <option value="external">Luar JTIK</option>
                    </select>
                    @if ($oldForm === 'lecturer') @error('homebase') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label for="lecturer-nip" class="mb-1 block text-xs font-medium text-muted">NIP</label>
                    <input id="lecturer-nip" type="text" name="nip" x-model="formLecturer.nip" inputmode="numeric" placeholder="18 angka" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    @if ($oldForm === 'lecturer') @error('nip') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                </div>
                <div>
                    <label for="lecturer-functional-position" class="mb-1 block text-xs font-medium text-muted">Jabatan fungsional</label>
                    <input id="lecturer-functional-position" type="text" name="functional_position" x-model="formLecturer.functional_position" placeholder="Contoh: Lektor" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                </div>
                <div>
                    <label for="lecturer-rank" class="mb-1 block text-xs font-medium text-muted">Pangkat</label>
                    <input id="lecturer-rank" type="text" name="rank" x-model="formLecturer.rank" placeholder="Contoh: Penata Tingkat I" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                </div>
                <div>
                    <label for="lecturer-grade" class="mb-1 block text-xs font-medium text-muted">Golongan</label>
                    <input id="lecturer-grade" type="text" name="grade" x-model="formLecturer.grade" placeholder="Contoh: III/d" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                </div>
            </div>

            <div>
                <label for="lecturer-profile-status" class="mb-1 block text-xs font-medium text-muted">Status Profil <span class="text-danger">*</span></label>
                <select id="lecturer-profile-status" name="profile_status" x-model="formLecturer.profile_status" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    <option value="verified">Terverifikasi (Dapat ditugaskan mengajar)</option>
                    <option value="unverified">Perlu verifikasi (Belum dapat mengajar)</option>
                </select>
                @if ($oldForm === 'lecturer') @error('profile_status') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
            </div>

            {{-- Jabatan Pimpinan --}}
            <div class="flex flex-col gap-3 rounded-lg border border-border bg-page/50 p-3">
                <div>
                    <label for="lecturer-leadership-position" class="mb-1 block text-xs font-semibold text-ink">Jabatan Pimpinan (Opsional)</label>
                    <input id="lecturer-leadership-position" type="text" name="leadership_position" x-model="formLecturer.leadership_position" placeholder="Contoh: Ketua Jurusan / Sekretaris Jurusan" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    <p class="mt-1 text-[11px] text-muted">Dosen pimpinan otomatis tidak dijadwalkan pada hari Kamis.</p>
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2" x-show="formLecturer.leadership_position">
                    <div>
                        <label for="lecturer-leadership-from" class="mb-1 block text-xs font-medium text-muted">Mulai Menjabat</label>
                        <input id="lecturer-leadership-from" type="date" name="leadership_from" x-model="formLecturer.leadership_from" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        @if ($oldForm === 'lecturer') @error('leadership_from') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                    </div>
                    <div>
                        <label for="lecturer-leadership-to" class="mb-1 block text-xs font-medium text-muted">Akhir Menjabat</label>
                        <input id="lecturer-leadership-to" type="date" name="leadership_to" x-model="formLecturer.leadership_to" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        @if ($oldForm === 'lecturer') @error('leadership_to') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                    </div>
                </div>
            </div>

            {{-- Target & Maks SKS --}}
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label for="lecturer-target-sks" class="mb-1 block text-xs font-medium text-muted">Target SKS (Beban)</label>
                    <input id="lecturer-target-sks" type="number" step="1" min="0" name="target_sks" x-model="formLecturer.target_sks" placeholder="Contoh: 12" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    @if ($oldForm === 'lecturer') @error('target_sks') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                </div>
                <div>
                    <label for="lecturer-max-sks" class="mb-1 block text-xs font-medium text-muted">Maksimum SKS</label>
                    <input id="lecturer-max-sks" type="number" step="1" min="0" name="max_sks" x-model="formLecturer.max_sks" placeholder="Contoh: 16" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    @if ($oldForm === 'lecturer') @error('max_sks') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                </div>
            </div>

            <fieldset x-show="formLecturer.id" :disabled="!formLecturer.id" class="rounded-control border border-border p-3">
                <legend class="px-1 text-sm font-semibold text-ink">Mata kuliah yang bisa diampu</legend>
                <input type="hidden" name="course_selection_present" value="1">
                <p class="mb-2 text-sm font-medium text-ink"><span x-text="selectedCourseIds.length"></span> MK dipilih</p>
                <template x-for="id in formLecturer.course_ids" :key="id">
                    <input type="hidden" name="course_ids[]" :value="id">
                </template>
                <label for="lecturer-course-search" class="sr-only">Cari mata kuliah</label>
                <input id="lecturer-course-search" type="search" x-model="courseQuery"
                       autocorrect="off" autocapitalize="none" spellcheck="false"
                       placeholder="Cari nama atau kode mata kuliah"
                       class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                <div class="mt-2 max-h-48 overflow-y-auto rounded-control border border-border">
                    @forelse ($courses as $course)
                        <label x-show="@js(mb_strtolower($course->code.' '.$course->name)).includes(courseQuery.trim().toLowerCase())" class="flex items-start gap-2 border-b border-border px-3 py-2 text-sm last:border-b-0">
                            <input type="checkbox" value="{{ $course->id }}" :checked="selectedCourseIds.includes($el.value)"
                                   :disabled="formLecturer.source_course_ids?.includes($el.value)"
                                   @change="toggleCourse($el.value, $event.target.checked)" class="mt-0.5">
                            <span>{{ $course->name }} <span class="text-xs text-muted">{{ $course->code }}</span>
                                <span x-show="formLecturer.source_course_ids?.includes('{{ $course->id }}')" class="text-xs text-muted">· Sudah tercatat</span>
                            </span>
                        </label>
                    @empty
                        <p class="p-3 text-sm text-muted">Belum ada mata kuliah di katalog.</p>
                    @endforelse
                    @if ($courses->isNotEmpty())
                        @php($courseSearchStrings = $courses->map(fn ($c) => mb_strtolower($c->code.' '.$c->name))->values()->all())
                        <p x-show="courseQuery.trim() !== '' && !@js($courseSearchStrings).some(s => s.includes(courseQuery.trim().toLowerCase()))"
                           x-cloak class="p-3 text-center text-xs text-muted">
                            Tidak ada mata kuliah yang cocok dengan pencarian.
                        </p>
                    @endif
                </div>
            </fieldset>

            <div class="mt-2 flex items-center justify-end gap-2 border-t border-border pt-4">
                <x-button variant="secondary" type="button" @click="formModalOpen = false">Batal</x-button>
                <x-button variant="primary" type="submit" icon="check">Simpan Data Dosen</x-button>
            </div>
        </form>
    </div>
</div>
