<?php

/*
|--------------------------------------------------------------------------
| Konfigurasi SICMA
|--------------------------------------------------------------------------
|
| Kebijakan operasional SICMA. Nilai bertanda "BELUM DITETAPKAN" adalah
| nilai sementara untuk pengembangan dan wajib dikonfirmasi jurusan
| (keputusan K01–K09 di SICMA_Workflow_AI_Agent.md Bagian 11) sebelum
| dipakai sungguhan. Jangan mengubahnya menjadi kebijakan tanpa persetujuan.
|
*/

return [

    'timezone' => 'Asia/Makassar',

    'timezone_label' => 'WITA',

    'operating_hours' => [
        'start' => '07:00', // BELUM DITETAPKAN
        'end' => '21:30', // BELUM DITETAPKAN
    ],

    'reservation' => [
        'default_minutes' => 60, // desain: "Durasi 1 jam"
        'min_minutes' => 30, // BELUM DITETAPKAN
        'max_minutes' => 180, // BELUM DITETAPKAN
        'max_days_ahead' => 7, // BELUM DITETAPKAN
        'max_active_per_class' => 3, // BELUM DITETAPKAN
        'checkin_open_minutes_before' => 15, // BELUM DITETAPKAN
        'checkin_grace_minutes' => 15, // BELUM DITETAPKAN
        'auto_expire_no_show' => false, // BELUM DITETAPKAN
        'extend_minutes' => 30, // desain: "+30 menit"
    ],

    'privacy' => [
        // Keterlihatan nama pemesan kelas lain di Aktivitas Portal Keti. BELUM DITETAPKAN (keputusan K).
        'show_booker_name_to_keti' => false,
    ],

    // Pilihan "Lapor kendala" di halaman penggunaan aktif.
    'facility_issues' => ['AC tidak dingin', 'Proyektor tidak menyala', 'Stopkontak tidak berfungsi', 'Kursi atau meja kurang', 'Ruang kotor', 'Lainnya'],

    'verification' => [
        'code_ttl_minutes' => 15,
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
    ],

    'public_portal' => [
        'cache_seconds' => 60,
        'show_sim_label' => true,
    ],

    'scheduler' => [
        'time_limits' => [
            'cepat' => 15,
            'seimbang' => 60,
            'mendalam' => 180,
        ],
        'fuzzy_enabled' => false, // BELUM DISAHKAN
        // Kapasitas ruang kurang dari jumlah peserta: masalah wajib (true) atau peringatan (false). BELUM DITETAPKAN
        'capacity_blocks_publish' => false,
        'python_bin' => env('SICMA_PYTHON', 'python3'),
    ],

    'polling_seconds' => 30,

];
