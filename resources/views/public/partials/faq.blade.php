<section class="flex flex-col gap-4">
    <h2 class="text-lg font-semibold text-ink">Pertanyaan yang sering ditanyakan</h2>
    <div class="grid grid-cols-2 gap-4">
        @foreach ([
            ['Siapa yang bisa memesan ruangan?', 'Ketua tingkat dengan akun terdaftar. Pengunjung publik dapat melihat informasi ketersediaan ruang.'],
            ['Bagaimana mendapatkan akun Keti?', 'Daftar sebagai Keti, verifikasi email, lalu tunggu pemeriksaan kelas oleh admin JTIK.'],
            ['Apakah status ruang selalu pasti?', 'Periksa waktu pembaruan yang tercantum. Tampilan ini menggunakan data simulasi dengan status Tersedia dan Digunakan.'],
            ['Seberapa baru informasi ruangan?', 'Status dihitung ulang dari jadwal resmi, reservasi, dan penutupan ruang. Waktu pembaruan selalu tercantum di dekat data.'],
        ] as [$question, $answer])
            <article class="flex flex-col gap-2 rounded-card border border-border bg-surface px-6 py-5">
                <h3 class="font-semibold text-ink">{{ $question }}</h3>
                <p class="text-sm leading-relaxed text-muted">{{ $answer }}</p>
            </article>
        @endforeach
    </div>
</section>
