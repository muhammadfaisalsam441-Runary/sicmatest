        <x-modal name="offering-new" title="Tambah penawaran perkuliahan" width="max-w-[480px]">
            <form method="POST" action="{{ route('admin.academic.offerings.store') }}" class="flex flex-col gap-4">
                @csrf
                <input type="hidden" name="period_id" value="{{ $period->id }}">
                <x-field label="Mata kuliah" name="course_id"><x-select name="course_id" :options="$courseOptions" placeholder="Pilih mata kuliah" required /></x-field>
                <x-field label="Kelas" name="class_group_id"><x-select name="class_group_id" :options="$classOptions" placeholder="Pilih kelas" required /></x-field>
                <div class="flex justify-end gap-3">
                    <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'offering-new')">Batal</x-button>
                    <x-button type="submit">Simpan</x-button>
                </div>
            </form>
        </x-modal>
