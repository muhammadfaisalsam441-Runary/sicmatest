@php
    $courseFormErrors = $errors->any() && in_array(old('form'), ['course-new', 'course-edit'], true);
    $initialCourseForm = [
        'id' => $courseFormErrors && old('form') === 'course-edit' ? old('course_id') : null,
        'code' => $courseFormErrors ? (string) old('code', '') : '',
        'sks' => $courseFormErrors ? (string) old('sks', 3) : '3',
        'name' => $courseFormErrors ? (string) old('name', '') : '',
        'curriculum' => $courseFormErrors ? (string) old('curriculum', '') : '',
    ];
@endphp

<div
    x-data="{
        course: @js($initialCourseForm),
        storeUrl: @js(route('admin.academic.courses.store')),
        updateUrlTemplate: @js(route('admin.academic.courses.update', '__COURSE__')),
        get isEdit() {
            return Boolean(this.course && this.course.id);
        },
        get formAction() {
            return this.isEdit
                ? this.updateUrlTemplate.replace('__COURSE__', this.course.id)
                : this.storeUrl;
        },
        openForm(data) {
            if (data && data.id) {
                this.course = {
                    id: data.id,
                    code: data.code ?? '',
                    sks: data.sks ?? 3,
                    name: data.name ?? '',
                    curriculum: data.curriculum ?? '',
                };
            } else {
                this.course = {
                    id: null,
                    code: '',
                    sks: 3,
                    name: '',
                    curriculum: '',
                };
            }
            this.$dispatch('open-modal', 'course-form');
        },
    }"
    @course-open-form.window="openForm($event.detail)"
>
    <x-modal name="course-form" title="Kelola Mata Kuliah" width="max-w-[480px]" :show="$courseFormErrors">
        <form method="POST" :action="formAction" class="flex flex-col gap-4">
            @csrf
            <template x-if="isEdit">
                <input type="hidden" name="_method" value="PUT">
            </template>
            <input type="hidden" name="form" :value="isEdit ? 'course-edit' : 'course-new'">
            <input type="hidden" name="course_id" :value="course.id || ''">
            <input type="hidden" name="curriculum" :value="course.curriculum || ''">

            <div class="flex items-center justify-between rounded-control border border-border bg-page/60 px-3 py-2 text-xs">
                <span class="font-semibold text-ink" x-text="isEdit ? 'Mengedit mata kuliah: ' + (course.name || '—') : 'Menambahkan mata kuliah baru'"></span>
                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                      :class="isEdit ? 'bg-primary-soft text-primary' : 'bg-success-soft text-success'"
                      x-text="isEdit ? 'Mode Edit' : 'MK Baru'"></span>
            </div>

            @if ($courseFormErrors)
                <x-alert variant="danger" title="Periksa kembali data mata kuliah">
                    <ul class="list-disc pl-4 text-xs">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-[1fr_110px] gap-3">
                <x-field label="Kode MK" name="code" for="course-form-code" required>
                    <x-input id="course-form-code" name="code" x-model="course.code" placeholder="Contoh: TKM2301" required />
                </x-field>
                <x-field label="SKS" name="sks" for="course-form-sks" required>
                    <x-input type="number" id="course-form-sks" name="sks" x-model="course.sks" min="1" max="8" required />
                </x-field>
            </div>

            <x-field label="Nama mata kuliah" name="name" for="course-form-name" required>
                <x-input id="course-form-name" name="name" x-model="course.name" placeholder="Contoh: Pemrograman Web" required />
            </x-field>

            <div class="flex justify-end gap-3 pt-2 border-t border-border">
                <x-button type="button" variant="secondary" x-on:click="$dispatch('close-modal', 'course-form')">Batal</x-button>
                <x-button type="submit"><span x-text="isEdit ? 'Simpan Perubahan' : 'Tambah Mata Kuliah'"></span></x-button>
            </div>
        </form>
    </x-modal>
</div>
