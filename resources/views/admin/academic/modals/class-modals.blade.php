        @foreach ([null, ...$classGroups] as $classGroup)
            <x-modal :name="$classGroup ? 'class-'.$classGroup->id : 'class-new'" :title="$classGroup ? 'Edit kelas' : 'Tambah kelas'" width="max-w-[480px]">
                <form method="POST" action="{{ $classGroup ? route('admin.academic.classes.update', $classGroup) : route('admin.academic.classes.store') }}" class="flex flex-col gap-4">
                    @csrf
                    @if ($classGroup) @method('PUT') @endif
                    <x-field label="Nama kelas" name="name"><x-input name="name" :id="'name-'.($classGroup?->id ?? 'new')" :value="$classGroup?->name" placeholder="Contoh: Tekom C" required /></x-field>
                    <x-field label="Prodi" name="study_program"><x-input name="study_program" :id="'program-'.($classGroup?->id ?? 'new')" :value="$classGroup?->study_program" placeholder="Teknik Komputer" required /></x-field>
                    <div class="grid grid-cols-2 gap-3">
                        <x-field label="Angkatan" name="cohort_year"><x-input type="number" name="cohort_year" :id="'cohort-'.($classGroup?->id ?? 'new')" :value="$classGroup?->cohort_year ?? now()->year" required /></x-field>
                        <x-field label="Jumlah mahasiswa" name="student_count"><x-input type="number" name="student_count" :id="'students-'.($classGroup?->id ?? 'new')" :value="$classGroup?->student_count" placeholder="Belum diisi" /></x-field>
                    </div>
                    @if ($classGroup)
                        <input type="hidden" name="code" value="{{ $classGroup->code }}">
                        <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="size-4 accent-primary" @checked($classGroup->is_active)> Kelas aktif</label>
                    @endif
                    <div class="flex justify-end gap-3">
                        <x-button variant="secondary" x-on:click="$dispatch('close-modal', '{{ $classGroup ? 'class-'.$classGroup->id : 'class-new' }}')">Batal</x-button>
                        <x-button type="submit">Simpan</x-button>
                    </div>
                </form>
            </x-modal>
        @endforeach
