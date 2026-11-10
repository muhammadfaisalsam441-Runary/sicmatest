# Dokumentasi API & Postman Collection — SICMA JTIK

Paket dokumentasi dan koleksi pengujian API resmi untuk **Sistem Informasi Cek Ruangan & Manajemen Akademik (SICMA)** Jurusan Teknik Informatika dan Komputer, Universitas Negeri Makassar.

---

## 📂 Berkas yang Tersedia

1. **[`openapi.yaml`](./openapi.yaml)**
   Spesifikasi kontrak API standar **OpenAPI 3.1.0** lengkap dengan parameter query, header autentikasi, skema request body, kode status HTTP, serta contoh respons sukses dan gagal (409 Conflict, 422 Validation Error).

2. **[`sicma.postman_collection.json`](./sicma.postman_collection.json)**
   Koleksi request **Postman Collection v2.1** siap pakai yang tersusun rapi per modul:
   * **1. Autentikasi**: Login, Register Keti, Logout.
   * **2. Portal Keti - Ketersediaan Ruang**: Cari Ruangan Kosong Real-time, Kalender Visual, Detail Ruangan.
   * **3. Portal Keti - Siklus Reservasi**: Pemesanan Ruang, Polling Status (30s), Check-in Mulai Pakai, Selesai Pakai, Perpanjang Waktu (+30 mnt), Lapor Kerusakan Fasilitas.
   * **4. Portal Admin - Kelola Ruang**: Master Data Ruangan, Tambah Ruangan Baru, Penutupan Ruang Sementara (*Closure*).

3. **[`sicma.postman_environment.json`](./sicma.postman_environment.json)**
   File environment Postman untuk konfigurasi variabel dinamis (`base_url`, `csrf_token`, `test_room_id`, `test_reservation_id`).

4. **[`index.html`](./index.html)**
   Halaman web dokumentasi interaktif yang ditenagai oleh **Scalar API Reference** (dapat dibuka langsung di browser).

---

## 🚀 Cara Menggunakan di Postman

### Langkah 1: Impor ke Postman
1. Buka aplikasi **Postman** (Desktop atau Web).
2. Klik tombol **Import** di pojok kiri atas.
3. Tarik atau pilih kedua file berikut:
   * `docs/api/sicma.postman_collection.json`
   * `docs/api/sicma.postman_environment.json`
4. Pilih environment **SICMA Local Environment** di pojok kanan atas aplikasi Postman.

### Langkah 2: Menjalankan Request
* Semua request telah dikonfigurasi dengan variabel `{{base_url}}` (`http://127.0.0.1:8000`).
* Request pembuatan reservasi otomatis menyimpan `reservation_id` baru ke dalam variabel koleksi untuk langsung digunakan pada request berikutnya (*Mulai*, *Selesai*, *Perpanjang*, dan *Lapor*).

---

## 🌐 Membuka Dokumentasi Interaktif di Browser

Anda dapat membuka file `docs/api/index.html` langsung di browser Anda (Chrome, Edge, Brave, dll.):

1. Buka file explorer dan klik ganda file:
   `c:\PROJECT WEB\SICMA\docs\api\index.html`
2. Atau jalankan via live server lokal:
   ```bash
   # Contoh jika menggunakan python http server
   python -m http.server 8080 --directory docs/api
   ```
   Lalu buka `http://localhost:8080`.

Halaman interaktif ini menyediakan:
* Filter pencarian endpoint (Shortcut: `Ctrl + K` / `Cmd + K`).
* Contoh kode otomatis dalam berbagai bahasa pemrograman (**cURL, JavaScript, PHP, Python, Go**).
* Tautan unduhan langsung untuk berkas Postman Collection dan OpenAPI Spec.

---

## 🧪 Menjalankan Pengujian Otomatis via Terminal (Newman)

Anda dapat menjalankan seluruh rangkaian pengujian koleksi API secara otomatis tanpa membuka antarmuka Postman:

```bash
npx newman run docs/api/sicma.postman_collection.json -e docs/api/sicma.postman_environment.json
```
