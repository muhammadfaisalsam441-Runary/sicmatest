@php
    $classFormErrors = $errors->any() && in_array(old('form'), ['class-new', 'class-edit'], true);
    $initialClassForm = [
        'id' => $classFormErrors && old('form') === 'class-edit' ? old('class_group_id') : null,
        'name' => $classFormErrors ? (string) old('name', '') : '',
        'code' => $classFormErrors ? (string) old('code', '') : '',
        'study_program' => $classFormErrors ? (string) old('study_program', '') : '',
        'cohort_year' => $classFormErrors ? (string) old('cohort_year', now()->year) : (string) now()->year,
        'student_count' => $classFormErrors ? old('student_count', '') : '',
        'is_active' => $classFormErrors ? (bool) old('is_active', true) : true,
    ];
@endphp

<div
    x-data="{
        cls: @js($initialClassForm),
        storeUrl: @js(route('admin.academic.classes.store')),
        updateUrlTemplate: @js(route('admin.academic.classes.update', '__CLASS__')),
        get isEdit() {
            return Boolean(this.cls && this.cls.id);
        },
        get formAction() {
            return this.isEdit
                ? this.updateUrlTemplate.replace('__CLASS__', this.cls.id)
                : this.storeUrl;
        },
        openForm(data) {
            if (data && data.id) {
                this.cls = {
                    id: data.id,
                    name: data.name ?? '',
                    code: data.code ?? '',
                    study_program: data.study_program ?? '',
                    cohort_year: data.cohort_year ?? {{ now()->year }},
                    student_count: data.student_count ?? '',
                    is_active: data.is_active !== undefined ? Boolean(data.is_active) : true,
                };
            } else {
                this.cls = {
                    id: null,
                    name: '',
                    code: '',
                    study_program: '',
                    cohort_year: {{ now()->year }},
                    student_count: '',
                    is_active: true,
                };
            }
            this.$dispatch('open-modal', 'class-form');
        },
    }"
    @class-open-form.window="openForm($event.detail)"
>
    <x-modal name="class-form" title="Kelola Kelas" width="max-w-[480px]" :show="$classFormErrors">
        <form method="POST" :action="formAction" class="flex flex-col gap-4">
            @csrf
            <template x-if="isEdit">
                <input type="hidden" name="_method" value="PUT">
            </template>
            <input type="hidden" name="form" :value="isEdit ? 'class-edit' : 'class-new'">
            <input type="hidden" name="class_group_id" :value="cls.id || ''">
            <template x-if="isEdit">
                <input type="hidden" name="code" :value="cls.code || ''">
            </template>

            <div class="flex items-center justify-between rounded-control border border-border bg-page/60 px-3 py-2 text-xs">
                <span class="font-semibold text-ink" x-text="isEdit ? 'Mengedit kelas: ' + (cls.name || '—') : 'Menambahkan kelas baru'"></span>
                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                      :class="isEdit ? 'bg-primary-soft text-primary' : 'bg-success-soft text-success'"
                      x-text="isEdit ? 'Mode Edit' : 'Kelas Baru'"></span>
            </div>

            @if ($classFormErrors)
                <x-alert variant="danger" title="Periksa kembali isian kelas">
                    <ul class="list-disc pl-4 text-xs">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            @endif

            <x-field label="Nama kelas" name="name" for="class-form-name" required>
                <x-input id="class-form-name" name="name" x-model="cls.name" placeholder="Contoh: Tekom C" required />
            </x-field>

            <x-field label="Prodi" name="study_program" for="class-form-study_program" required>
                <x-input id="class-form-study_program" name="study_program" x-model="cls.study_program" placeholder="Teknik Komputer" required />
            </x-field>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-field label="Angkatan" name="cohort_year" for="class-form-cohort_year" required>
                    <x-input type="number" id="class-form-cohort_year" name="cohort_year" x-model="cls.cohort_year" required />
                </x-field>
                <x-field label="Jumlah mahasiswa" name="student_count" for="class-form-student_count">
                    <x-input type="number" id="class-form-student_count" name="student_count" x-model="cls.student_count" placeholder="Belum diisi" />
                </x-field>
            </div>

            <template x-if="isEdit">
                <label for="class-form-is_active" class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="hidden" name="is_active" value="0">
                    <input id="class-form-is_active" type="checkbox" name="is_active" value="1" class="size-4 accent-primary" x-model="cls.is_active">
                    <span>Kelas aktif</span>
                </label>
            </template>

            <div class="flex justify-end gap-3 border-t border-border pt-3">
                <x-button type="button" variant="secondary" x-on:click="$dispatch('close-modal', 'class-form')">Batal</x-button>
                <x-button type="submit">Simpan</x-button>
            </div>
        </form>
    </x-modal>
</div>
