        @foreach ([null, ...$courses] as $course)
            <x-modal :name="$course ? 'course-'.$course->id : 'course-new'" :title="$course ? 'Edit Mata Kuliah: '.$course->name : 'Tambah Mata Kuliah'" width="max-w-[480px]">
                <form method="POST" action="{{ $course ? route('admin.academic.courses.update', $course) : route('admin.academic.courses.store') }}" class="flex flex-col gap-4">
                    @csrf
                    @if ($course) @method('PUT') @endif
                    <div class="grid grid-cols-[1fr_100px] gap-3">
                        <x-field label="Kode MK" name="code">
                            <x-input name="code" :id="'code-'.($course?->id ?? 'new')" :value="$course?->code" placeholder="Contoh: TKM2301" required />
                        </x-field>
                        <x-field label="SKS" name="sks">
                            <x-input type="number" name="sks" :id="'sks-'.($course?->id ?? 'new')" :value="$course?->sks ?? 3" min="1" max="8" required />
                        </x-field>
                    </div>
                    <x-field label="Nama mata kuliah" name="name">
                        <x-input name="name" :id="'course-name-'.($course?->id ?? 'new')" :value="$course?->name" placeholder="Contoh: Pemrograman Web" required />
                    </x-field>
                    <input type="hidden" name="curriculum" value="{{ $course?->curriculum }}">
                    <p class="text-xs text-muted">Kode MK digunakan sebagai acuan unik jadwal perkuliahan dan penawaran kelas.</p>
                    <div class="flex justify-end gap-3 pt-2 border-t border-border">
                        <x-button variant="secondary" x-on:click="$dispatch('close-modal', '{{ $course ? 'course-'.$course->id : 'course-new' }}')">Batal</x-button>
                        <x-button type="submit">{{ $course ? 'Simpan Perubahan' : 'Tambah Mata Kuliah' }}</x-button>
                    </div>
                </form>
            </x-modal>
        @endforeach
