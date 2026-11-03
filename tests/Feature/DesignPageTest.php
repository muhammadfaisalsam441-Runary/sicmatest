<?php

test('halaman desain menampilkan komponen di setiap layout', function (string $routeName, array $expectedText) {
    $this->withoutVite();

    $this->get(route($routeName))
        ->assertOk()
        ->assertSeeInOrder($expectedText);
})->with([
    'publik' => ['design', ['SICMA', 'Beranda', 'Ruangan', 'Panduan', 'Bantuan', 'Masuk', 'Data simulasi', 'Tersedia sampai']],
    'auth' => ['design.auth', ['Satu akses untuk ruang kelas JTIK.', 'Publik • Keti • Admin', 'Masuk ke SICMA']],
    'keti' => ['design.keti', ['Cari Ruangan', 'Ruangan JTIK', 'Favorit', 'Aktivitas', 'SICMA / Portal Keti', 'Notifikasi • 0', 'Tersedia sampai']],
    'admin' => ['design.admin', ['Panel Admin', 'Dashboard', 'Kelola Ruang', 'Kelola Jadwal', 'Riwayat Reservasi', 'Log Pemakaian', 'Penyusunan jadwal', 'Data Akademik', 'Data Dosen', 'Pembagian SKS', 'Susun Jadwal', 'Akun Keti', 'Keluar', 'Admin', 'Dashboard', 'Tersedia sampai']],
]);
