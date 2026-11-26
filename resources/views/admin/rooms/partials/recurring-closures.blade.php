@if ($recurringClosures->isNotEmpty())
    <x-card padding="p-4" class="admin-table-card flex flex-col gap-3 shadow-xs">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <span class="flex size-7 items-center justify-center rounded-control bg-danger-soft text-danger">
                    <i data-lucide="calendar-x" class="size-4" aria-hidden="true"></i>
                </span>
                <div>
                    <h2 class="text-sm font-bold text-ink">Aturan Pembatasan Jam Ruang Berulang</h2>
                </div>
            </div>
            <x-button size="sm" variant="secondary" icon="plus" @click="openClosureModal(null, 'recurring')">Tambah Aturan</x-button>
        </div>

        <div class="divide-y divide-border rounded-control border border-border">
            @foreach ($recurringClosures as $rule)
                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5 text-xs">
                    <div class="flex flex-col gap-0.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-bold text-ink">{{ $rule->room?->name ?? 'Ruang' }}</span>
                            <span class="rounded-full bg-danger-soft px-2 py-0.5 text-[11px] font-semibold text-danger">{{ $rule->scheduleSummary() }}</span>
                        </div>
                        <span class="text-muted">Alasan: {{ $rule->reason }}</span>
                    </div>
                    <button type="button"
                            @click="openReopenClosureModal(@js($rule->room?->name ?? 'Ruang'), @js(route('admin.closures.destroy', $rule)))"
                            class="inline-flex cursor-pointer items-center gap-1 rounded-control border border-danger/30 bg-danger-soft/30 px-2.5 py-1 text-xs font-semibold text-danger transition hover:bg-danger hover:text-white">
                        <i data-lucide="trash-2" class="size-3.5" aria-hidden="true"></i>
                        <span>Hapus Aturan</span>
                    </button>
                </div>
            @endforeach
        </div>
    </x-card>
@endif
