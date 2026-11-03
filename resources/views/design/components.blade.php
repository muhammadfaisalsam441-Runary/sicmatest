{{-- Contoh isi bersama untuk halaman /_design: semua komponen dan kondisinya. --}}
<div class="flex flex-col gap-10">
    <section class="flex flex-col gap-4">
        <h2 class="text-lg font-semibold">Tombol & badge</h2>
        <div class="flex flex-wrap items-center gap-3">
            <x-button>Masuk</x-button>
            <x-button variant="secondary">Lihat ruang tersedia</x-button>
            <x-button variant="soft">Detail ruangan</x-button>
            <x-button variant="danger">Batalkan reservasi</x-button>
            <x-button variant="danger-outline">Coba lagi</x-button>
            <x-button variant="secondary" icon="log-out">Keluar</x-button>
            <x-button disabled>Pesan ruang</x-button>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <x-status-badge variant="success">Tersedia</x-status-badge>
            <x-status-badge variant="danger">Digunakan</x-status-badge>
            <x-status-badge variant="neutral">Belum pasti</x-status-badge>
            <x-status-badge variant="primary">Data simulasi</x-status-badge>
            <x-status-badge variant="primary">Dipesan</x-status-badge>
            <x-status-badge variant="danger">Digunakan</x-status-badge>
            <x-status-badge variant="success">Selesai</x-status-badge>
            <x-status-badge variant="neutral">Dibatalkan</x-status-badge>
            <x-status-badge variant="success">Jadwal resmi</x-status-badge>
            <x-status-badge variant="primary">Draf</x-status-badge>
            <x-status-badge variant="danger">Masalah wajib</x-status-badge>
            <x-status-badge variant="warning">Perlu verifikasi</x-status-badge>
        </div>
    </section>

    <section class="flex flex-col gap-4">
        <div class="flex items-start justify-between">
            <div class="flex flex-col gap-2">
                <h2 class="text-lg font-semibold">Kartu ruang</h2>
                <p class="text-sm text-muted">Tersedia berarti ruangan dapat digunakan. Digunakan berarti ruangan sedang dipakai.</p>
            </div>
            <x-sim-data-label />
        </div>
        <div class="grid grid-cols-3 gap-4">
            <x-room-card name="AE 107" location="AE • Lantai 1" :capacity="45" time-label="Tersedia sampai" time="19:00 WITA" />
            <x-room-card name="Lab ICT 10" location="Gedung belum didata • Lantai 3" :capacity="35" time-label="Tersedia sampai" time="17:30 WITA" />
            <x-room-card name="AE 103" badge="Digunakan" location="AE • Lantai 1" :capacity="40" time-label="Selesai pukul" time="16:00 WITA" note="Kuliah terjadwal" />
            <x-room-card name="Lab Animasi" badge="Digunakan" location="Gedung belum didata • Lantai —" time-label="Selesai pukul" time="—" note="Ditutup: Perawatan perangkat komputer" />
            <x-room-card name="AE 104" badge="Belum pasti" location="AE • Lantai 1" note="Data ruang belum lengkap." />
        </div>
        <div class="grid grid-cols-2 gap-4">
            <x-room-card size="large" name="AE 107" location="AE • Lantai 1" :capacity="45" time-label="Tersedia sampai" time="19:00 WITA" remaining="4 jam 45 menit lagi" href="#" />
            <x-room-card size="large" name="Lab ICT 10" location="Gedung belum didata • Lantai 3" :capacity="35" :facilities="['AC']" time-label="Tersedia sampai" time="17:30 WITA" remaining="3 jam 15 menit lagi" href="#" />
        </div>
    </section>

    <section class="flex flex-col gap-4">
        <h2 class="text-lg font-semibold">Kartu angka, tab, stepper</h2>
        <div class="grid grid-cols-3 gap-4">
            <x-stat-card label="Total ruang" value="24" hint="10 AE • 4 Pinisi • 10 ruang lainnya" />
            <x-stat-card label="Tersedia sekarang" value="15" hint="Tersedia pada data simulasi" tone="success" />
            <x-stat-card label="Digunakan sekarang" value="9" hint="Sedang digunakan pada data simulasi" tone="danger" />
        </div>
        <x-tabs :items="[
            ['label' => 'Semua', 'href' => '#', 'active' => true, 'count' => 24],
            ['label' => 'Tersedia', 'href' => '#', 'active' => false, 'count' => 15],
            ['label' => 'Digunakan', 'href' => '#', 'active' => false, 'count' => 9],
        ]" />
        <x-stepper :steps="['Data', 'Tim mengajar', 'Draf jadwal', 'Periksa', 'Tetapkan']" :current="3" />
    </section>

    <section class="flex flex-col gap-4">
        <h2 class="text-lg font-semibold">Tabel</h2>
        <x-data-table :headers="['Ruang', 'Kelas', 'Mata kuliah', 'Waktu', 'Status']">
            <tr>
                <td class="px-5 py-4 font-medium">AE 107</td>
                <td class="px-5 py-4">—</td>
                <td class="px-5 py-4 text-muted">Tersedia untuk kelas</td>
                <td class="px-5 py-4">Hingga 19:00</td>
                <td class="px-5 py-4"><x-status-badge variant="success">Tersedia</x-status-badge></td>
            </tr>
            <tr>
                <td class="px-5 py-4 font-medium">AE 103</td>
                <td class="px-5 py-4">Tekom C</td>
                <td class="px-5 py-4 text-muted">Sistem Operasi</td>
                <td class="px-5 py-4">Hingga 11:40</td>
                <td class="px-5 py-4"><x-status-badge variant="danger">Digunakan</x-status-badge></td>
            </tr>
        </x-data-table>
    </section>

    <section class="grid grid-cols-3 gap-4">
        <x-loading-state />
        <x-empty-state title="Tidak ada ruangan yang cocok" message="Ubah waktu, durasi, atau gedung untuk melihat ruangan lain.">
            <x-button variant="soft">Ubah filter</x-button>
        </x-empty-state>
        <x-error-state retry="#" />
    </section>

    <section class="grid grid-cols-3 gap-4">
        <x-alert variant="success" title="Ruang AE 107 berhasil disimpan">Perubahan langsung dipakai di aplikasi ketua tingkat.</x-alert>
        <x-alert variant="danger" title="Perubahan belum tersimpan">Koneksi terputus. Isian kamu masih aman di layar.</x-alert>
        <x-alert variant="info" title="Jadwal sedang dibaca">Halaman 2 dari 3. Kamu bisa membuka menu lain.</x-alert>
    </section>

    <section class="flex flex-col gap-4">
        <h2 class="text-lg font-semibold">Formulir & dialog</h2>
        <x-card class="flex max-w-md flex-col gap-4">
            <x-field label="Email atau NIM" name="identity">
                <x-input name="identity" placeholder="Masukkan email atau NIM" />
            </x-field>
            <x-field label="Gedung" name="building">
                <x-select name="building" :options="['ae' => 'AE', 'pinisi' => 'Pinisi']" placeholder="Semua gedung" />
            </x-field>
        </x-card>
        <div class="flex gap-3">
            <x-button variant="secondary" x-data x-on:click="$dispatch('open-modal', 'contoh-modal')">Buka modal formulir</x-button>
            <x-button variant="danger" x-data x-on:click="$dispatch('open-modal', 'contoh-konfirmasi')">Buka dialog konfirmasi</x-button>
        </div>
    </section>
</div>

@push('dialogs')
    <x-modal name="contoh-modal" title="Tambah ruang">
        <div class="flex flex-col gap-4">
            <x-field label="Nama ruang" name="name"><x-input name="name" placeholder="Contoh: AE 216" /></x-field>
            <div class="grid grid-cols-2 gap-3">
                <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'contoh-modal')">Batal</x-button>
                <x-button>Simpan ruang</x-button>
            </div>
        </div>
    </x-modal>
    <x-confirm-dialog name="contoh-konfirmasi" title="Batalkan reservasi AE 106?" message="PTIK C 2026 • 29 Okt, 15:00–16:40 WITA. Slot terbuka lagi untuk kelas lain." action="#" icon="circle-alert" reason="Alasan (opsional)" confirm="Batalkan reservasi" cancel="Kembali" />
@endpush
