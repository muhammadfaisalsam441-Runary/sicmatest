{{-- MODAL 3: REKAP LENGKAP BEBAN DOSEN --}}
<div x-show="rekapModalOpen" x-cloak data-modal-name="rekap-load-modal"
     role="dialog" aria-modal="true" aria-labelledby="rekap-load-modal-title"
     class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="rekapModalOpen = false"></div>
    <div class="relative flex max-h-[85vh] w-full max-w-4xl flex-col rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <div>
                <span class="text-xs font-semibold text-primary">Semester Aktif</span>
                <h3 id="rekap-load-modal-title" class="text-xl font-bold text-ink">Rekap Beban SKS Dosen</h3>
            </div>
            <button type="button" @click="rekapModalOpen = false" aria-label="Tutup" class="cursor-pointer rounded p-1 text-muted hover:text-ink">
                <i data-lucide="x" class="size-5" aria-hidden="true"></i>
            </button>
        </div>

        <div class="my-3">
            <input type="text"
                   x-model="lecturerSearch"
                   aria-label="Filter nama dosen pada tabel rekap"
                   placeholder="Filter nama dosen pada tabel rekap..."
                   class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
        </div>

        <div class="custom-scrollbar min-h-0 flex-1 overflow-x-auto overflow-y-auto rounded-lg border border-border">
            <table class="w-full text-left text-sm">
                <thead class="sticky top-0 z-10 border-b border-border bg-page text-xs text-muted">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 font-semibold">Dosen</th>
                        <th scope="col" class="px-3 py-2.5 text-center font-semibold">SKS Semester</th>
                        <th scope="col" class="px-3 py-2.5 text-center font-semibold">Target</th>
                        <th scope="col" class="px-3 py-2.5 text-center font-semibold">Maks.</th>
                        <th scope="col" class="px-3 py-2.5 text-center font-semibold">Sisa</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">Status Beban</th>
                        <th scope="col" class="px-3 py-2.5 text-center font-semibold">SKS × Pertemuan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($loads as $load)
                        <tr x-show="!lecturerSearch || @js(strtolower($load['lecturer']->name)).includes(lecturerSearch.toLowerCase())">
                            <td class="px-4 py-2.5 font-medium text-ink">{{ $load['lecturer']->name }}</td>
                            <td class="px-3 py-2.5 text-center font-semibold text-ink">
                                {{ $load['assigned'] }}
                                @if ($load['pending'] > 0)
                                    <span class="block text-[10px] font-normal text-muted">+{{ $load['pending'] }} pending</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-center">{{ $load['target'] ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-center">{{ $load['max'] ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-center">{{ $load['remaining'] ?? '—' }}</td>
                            <td class="px-4 py-2.5">
                                <x-status-badge :variant="$load['tone']">{{ $load['status'] }}</x-status-badge>
                            </td>
                            <td class="px-3 py-2.5 text-center font-semibold text-primary">
                                {{ $load['meeting_sks'] }}
                                @if ($load['pending_phases'] > 0)
                                    <span class="block text-[10px] font-normal text-muted">{{ $load['pending_phases'] }} penugasan perlu atur fase</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-muted">Belum ada pembagian mengajar.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3 text-xs text-muted">
            <span>SKS Semester menjumlahkan bobot mata kuliah pada setiap kelas yang diajar masing-masing dosen.</span>
            <x-button variant="secondary" type="button" @click="rekapModalOpen = false">Tutup</x-button>
        </div>
    </div>
</div>
