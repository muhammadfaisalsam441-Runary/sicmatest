@use('App\Support\Wita')
<x-card class="flex flex-col gap-4">
    <h2 class="text-lg font-semibold">Penetapan Draf</h2>
    @if ($candidate)
        <p class="text-sm">Draf <strong>v{{ $candidate->version_no }}</strong> • {{ $candidate->source->label() }} • {{ $candidate->period->name }}</p>
        <x-alert :variant="$reviewStatus['ready'] ? 'success' : 'info'" :title="$reviewStatus['label']">
            {{ $reviewStatus['errors'] }} masalah wajib • {{ $reviewStatus['warnings'] }} peringatan.
            {{ $reviewStatus['checked_at'] ? 'Diperiksa '.Wita::stamp($reviewStatus['checked_at']).'.' : 'Jalankan pemeriksaan terlebih dahulu.' }}
        </x-alert>
        <div><x-button variant="secondary" :href="route('admin.drafts.inspect', $candidate)">Buka Pemeriksaan</x-button></div>
        @if ($reviewStatus['ready'] && auth()->user()->can('publish-schedule'))
            <form method="POST" action="{{ route('admin.drafts.publish', $candidate) }}" x-data="{ confirming: false }"
                  @submit.prevent="if (confirming) { $el.submit() } else { confirming = true }" class="flex max-w-xl flex-col gap-4">
                @csrf
                <input type="hidden" name="lock_version" value="{{ $candidate->lock_version }}">
                <x-field label="Berlaku mulai" name="effective_from">
                    <x-input type="date" name="effective_from" :value="max(now()->toDateString(), $candidate->effective_from?->toDateString() ?? now()->toDateString())"
                             :min="max(now()->toDateString(), $candidate->period?->start_date?->toDateString() ?? now()->toDateString())" :max="$candidate->period?->end_date?->toDateString()" x-bind:readonly="confirming" required />
                </x-field>
                <x-field label="Catatan revisi (opsional)" name="note"><x-input name="note" maxlength="255" x-bind:readonly="confirming" placeholder="Catatan penetapan jadwal" /></x-field>
                <p class="text-sm text-muted">Sistem memeriksa ulang data terbaru dan reservasi sebelum menyimpan. Perubahan serentak atau masalah wajib akan menghentikan penetapan.</p>
                @if ($reviewStatus['warnings'] > 0)
                    <x-alert title="Peringatan perlu ditinjau">{{ $reviewStatus['warnings'] }} peringatan tersimpan. Tinjau rinciannya pada Pemeriksaan Jadwal sebelum melanjutkan.</x-alert>
                @endif
                <div x-show="confirming" x-cloak class="rounded-control border border-primary/30 bg-primary-soft p-4 text-sm" role="status">
                    Tetapkan draf v{{ $candidate->version_no }} sebagai jadwal resmi sesuai tanggal berlaku di atas? Versi sebelumnya tetap tersimpan dalam riwayat.
                </div>
                <div class="flex flex-wrap gap-3">
                    <x-button variant="secondary" type="button" x-show="confirming" @click="confirming = false">Kembali</x-button>
                    <x-button type="submit"><span x-text="confirming ? 'Tetapkan sebagai Jadwal Resmi' : 'Tinjau Penetapan'">Tinjau Penetapan</span></x-button>
                </div>
            </form>
        @elseif ($reviewStatus['ready'])
            <p class="text-sm text-muted">Akun ini tidak memiliki izin menetapkan jadwal.</p>
        @endif
    @else
        <p class="text-sm text-muted">Pilih draf yang sudah ditinjau. Kesiapan diperiksa pada halaman Pemeriksaan Jadwal.</p>
        @forelse ($candidates as $item)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-control border border-border p-4">
                <span>Draf v{{ $item->version_no }} • {{ $item->source->label() }}</span>
                <x-button variant="secondary" :href="route('admin.schedules.index', ['tab' => 'penetapan', 'draf' => $item->id])">Tinjau Draf</x-button>
            </div>
        @empty
            <p class="text-sm text-muted">Belum ada draf untuk ditetapkan.</p>
        @endforelse
    @endif
</x-card>
