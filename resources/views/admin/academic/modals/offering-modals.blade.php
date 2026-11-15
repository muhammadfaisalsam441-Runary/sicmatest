@php
    $offeringFormErrors = $errors->any() && old('form') === 'offering-new';
@endphp

<x-modal name="offering-new" title="Tambah penawaran perkuliahan" width="max-w-[480px]" :show="$offeringFormErrors">
    <form method="POST" action="{{ route('admin.academic.offerings.store') }}" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="form" value="offering-new">
        <input type="hidden" name="period_id" value="{{ $period?->id }}">

        @if ($offeringFormErrors)
            <x-alert variant="danger" title="Gagal menambah penawaran">
                <ul class="list-disc pl-4 text-xs">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        <x-field label="Mata kuliah" name="course_id" for="offering-course-id" required>
            <x-select id="offering-course-id" name="course_id" :options="$courseOptions" :value="old('course_id')" placeholder="Pilih mata kuliah" required />
        </x-field>
        <x-field label="Kelas" name="class_group_id" for="offering-class-group-id" required>
            <x-select id="offering-class-group-id" name="class_group_id" :options="$classOptions" :value="old('class_group_id')" placeholder="Pilih kelas" required />
        </x-field>
        <div class="flex justify-end gap-3 border-t border-border pt-3">
            <x-button type="button" variant="secondary" x-on:click="$dispatch('close-modal', 'offering-new')">Batal</x-button>
            <x-button type="submit">Simpan</x-button>
        </div>
    </form>
</x-modal>
