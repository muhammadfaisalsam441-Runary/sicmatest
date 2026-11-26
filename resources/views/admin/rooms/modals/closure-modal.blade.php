{{-- MODAL 2: PENUTUPAN RUANG SEMENTARA --}}
<div x-show="closureModalOpen" x-cloak data-modal-name="room-closure"
     role="dialog" aria-modal="true" aria-labelledby="room-closure-title"
     @keydown.escape.window="if (closureModalOpen) closureModalOpen = false"
     class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-ink/80 backdrop-blur-sm" @click="closureModalOpen = false"></div>
    <div class="relative max-h-[90dvh] w-full max-w-lg overflow-y-auto rounded-2xl bg-surface p-6 shadow-2xl">
        <div class="flex items-start justify-between border-b border-border pb-3">
            <div>
                <span class="text-xs font-semibold text-danger">Pemeliharaan & Penutupan</span>
                <h3 id="room-closure-title" class="text-lg font-bold text-ink">Tutup Ruang Sementara</h3>
            </div>
            <button type="button" @click="closureModalOpen = false" aria-label="Tutup modal penutupan ruang" class="inline-flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-control text-muted transition hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <i data-lucide="x" class="size-5" aria-hidden="true"></i>
            </button>
        </div>

        <form method="POST" action="{{ route('admin.closures.store') }}" class="mt-4 flex flex-col gap-4">
            @csrf
            <input type="hidden" name="form" value="closure">
            <input type="hidden" name="mode" x-model="closureMode">

            @if ($errors->any() && $oldForm === 'closure')
                <x-alert variant="danger">{{ $errors->first() }}</x-alert>
            @endif

            <div>
                <span class="mb-1.5 block text-xs font-medium text-muted">Jenis Penutupan <span class="text-danger">*</span></span>
                <div class="grid grid-cols-2 gap-2 rounded-control border border-border bg-page p-1">
                    <button type="button"
                            @click="closureMode = 'once'"
                            :class="closureMode === 'once' ? 'bg-surface text-primary shadow-2xs font-bold' : 'text-muted hover:text-ink font-medium'"
                            class="cursor-pointer rounded-control px-3 py-1.5 text-xs transition">
                        Sekali (Rentang Waktu)
                    </button>
                    <button type="button"
                            @click="closureMode = 'recurring'"
                            :class="closureMode === 'recurring' ? 'bg-surface text-primary shadow-2xs font-bold' : 'text-muted hover:text-ink font-medium'"
                            class="cursor-pointer rounded-control px-3 py-1.5 text-xs transition">
                        Berulang (Jam Tertentu)
                    </button>
                </div>
            </div>

            <div>
                <label for="closure-room-id" class="mb-1 block text-xs font-medium text-muted">Pilih Ruang <span class="text-danger">*</span></label>
                <select id="closure-room-id" name="room_id" x-model="closureRoomId" required class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    <option value="">-- Pilih Ruangan --</option>
                    @foreach ($allRooms as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                @if ($oldForm === 'closure') @error('room_id') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
            </div>

            <div x-show="closureMode === 'once'" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label for="closure-start-at" class="mb-1 block text-xs font-medium text-muted">Mulai Tutup <span class="text-danger">*</span></label>
                    <input id="closure-start-at" type="datetime-local" name="start_at" x-model="closureStart" :disabled="closureMode !== 'once'" :required="closureMode === 'once'" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    @if ($oldForm === 'closure') @error('start_at') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                </div>
                <div>
                    <label for="closure-end-at" class="mb-1 block text-xs font-medium text-muted">Selesai Tutup <span class="text-danger">*</span></label>
                    <input id="closure-end-at" type="datetime-local" name="end_at" x-model="closureEnd" :disabled="closureMode !== 'once'" :required="closureMode === 'once'" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                    @if ($oldForm === 'closure') @error('end_at') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                </div>
            </div>

            <div x-show="closureMode === 'recurring'" x-cloak class="flex flex-col gap-3 rounded-control border border-border bg-page/60 p-3.5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs font-semibold text-ink">Jam Tidak Boleh Dipakai <span class="text-danger">*</span></span>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <button type="button" @click="closureStartTime = '07:30'; closureEndTime = '12:00'" class="cursor-pointer rounded border border-border bg-surface px-2 py-0.5 text-[11px] font-medium text-primary hover:bg-primary-soft">Pagi (07:30–12:00)</button>
                        <button type="button" @click="closureStartTime = '13:00'; closureEndTime = '17:30'" class="cursor-pointer rounded border border-border bg-surface px-2 py-0.5 text-[11px] font-medium text-primary hover:bg-primary-soft">Siang (13:00–17:30)</button>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label for="closure-start-time" class="mb-1 block text-xs font-medium text-muted">Mulai Jam <span class="text-danger">*</span></label>
                        <input id="closure-start-time" type="time" name="start_time" x-model="closureStartTime" :disabled="closureMode !== 'recurring'" :required="closureMode === 'recurring'" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        @if ($oldForm === 'closure') @error('start_time') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                    </div>
                    <div>
                        <label for="closure-end-time" class="mb-1 block text-xs font-medium text-muted">Selesai Jam <span class="text-danger">*</span></label>
                        <input id="closure-end-time" type="time" name="end_time" x-model="closureEndTime" :disabled="closureMode !== 'recurring'" :required="closureMode === 'recurring'" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        @if ($oldForm === 'closure') @error('end_time') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                    </div>
                </div>

                <div>
                    <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                        <span class="text-xs font-medium text-muted">Berlaku Setiap Hari <span class="text-danger">*</span></span>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button type="button" @click="setClosureDays([1, 2, 3, 4, 5])" class="cursor-pointer rounded border border-border bg-surface px-2 py-0.5 text-[11px] font-medium text-primary hover:bg-primary-soft">Senin–Jumat</button>
                            <button type="button" @click="setClosureDays([1, 2, 3, 4, 5, 6, 7])" class="cursor-pointer rounded border border-border bg-surface px-2 py-0.5 text-[11px] font-medium text-primary hover:bg-primary-soft">Setiap Hari</button>
                        </div>
                    </div>
                    <div class="grid grid-cols-7 gap-1">
                        @foreach ([1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'] as $dayNum => $dayLabel)
                            <label class="cursor-pointer">
                                <input type="checkbox"
                                       name="days_of_week[]"
                                       value="{{ $dayNum }}"
                                       :disabled="closureMode !== 'recurring'"
                                       :checked="closureDays.includes({{ $dayNum }})"
                                       @change="toggleClosureDay({{ $dayNum }})"
                                       class="sr-only">
                                <span :class="closureDays.includes({{ $dayNum }}) ? 'border-primary bg-primary text-white font-bold' : 'border-border bg-surface text-muted hover:text-ink font-medium'"
                                      class="flex select-none items-center justify-center rounded-control border py-1.5 text-xs transition">
                                    {{ $dayLabel }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @if ($oldForm === 'closure') @error('days_of_week') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label for="closure-start-date" class="mb-1 block text-xs font-medium text-muted">Dari Tanggal <span class="text-danger">*</span></label>
                        <input id="closure-start-date" type="date" name="start_date" x-model="closureStartDate" :disabled="closureMode !== 'recurring'" :required="closureMode === 'recurring'" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        @if ($oldForm === 'closure') @error('start_date') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                    </div>
                    <div>
                        <label for="closure-end-date" class="mb-1 block text-xs font-medium text-muted">Sampai Tanggal <span class="text-danger">*</span></label>
                        <input id="closure-end-date" type="date" name="end_date" x-model="closureEndDate" :disabled="closureMode !== 'recurring'" :required="closureMode === 'recurring'" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                        @if ($oldForm === 'closure') @error('end_date') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
                    </div>
                </div>
            </div>

            <div>
                <label for="closure-reason" class="mb-1 block text-xs font-medium text-muted">Alasan Penutupan <span class="text-danger">*</span></label>
                <input id="closure-reason" type="text" name="reason" x-model="closureReason" required placeholder="Contoh: Perawatan AC / Perbaikan proyektor / Renovasi" class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none">
                @if ($oldForm === 'closure') @error('reason') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror @endif
            </div>

            <div>
                <label class="flex cursor-pointer select-none items-center gap-2 text-xs font-medium text-ink">
                    <input type="checkbox" name="cancel_impacted" value="1" @checked(old('cancel_impacted')) class="size-4 cursor-pointer rounded accent-danger">
                    Batalkan juga reservasi terdampak secara otomatis jika ada
                </label>
            </div>

            @if (session('closure_impact'))
                <div class="flex flex-col gap-2 rounded-card bg-danger-soft p-4 text-xs">
                    <div class="flex items-center gap-1.5 font-semibold text-danger">
                        <i data-lucide="triangle-alert" class="size-4" aria-hidden="true"></i>
                        <span>{{ count(session('closure_impact')) }} reservasi aktif terdampak penutupan ini:</span>
                    </div>
                    <div class="max-h-36 divide-y divide-danger/20 overflow-y-auto">
                        @foreach (session('closure_impact') as $impact)
                            <div class="py-1 text-ink">
                                <strong>{{ $impact['class'] }}</strong> • {{ $impact['time'] }} ({{ $impact['purpose'] }})
                            </div>
                        @endforeach
                    </div>
                    <label class="mt-1 flex cursor-pointer items-center gap-2 font-medium text-ink">
                        <input type="checkbox" name="confirm_impact" value="1" class="size-4 cursor-pointer rounded accent-danger" required>
                        Saya mengerti dan tetap menutup ruang
                    </label>
                </div>
            @endif

            <div class="mt-2 flex flex-wrap items-center justify-end gap-2 border-t border-border pt-4">
                <x-button variant="secondary" type="button" @click="closureModalOpen = false">Batal</x-button>
                <x-button variant="danger" type="submit" icon="lock">Tutup Ruang</x-button>
            </div>
        </form>
    </div>
</div>
