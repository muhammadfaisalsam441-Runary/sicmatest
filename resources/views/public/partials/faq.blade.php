<section class="flex flex-col gap-4">
    <h2 class="text-lg font-semibold text-ink">Pertanyaan yang sering ditanyakan</h2>
    <div class="flex flex-col gap-3">
        @foreach ([
            ['Siapa yang bisa memesan ruangan?', 'Ketua tingkat dengan akun terdaftar. Pengunjung publik dapat melihat informasi ketersediaan ruang.'],
            ['Bagaimana mendapatkan akun Keti?', 'Daftar sebagai Keti, verifikasi email, lalu tunggu pemeriksaan kelas oleh admin JTIK.'],
            ['Apakah status ruang selalu pasti?', config('sicma.public_portal.show_sim_label') && config('sicma.public_portal.sim_label') === 'Data simulasi' ? 'Periksa waktu pembaruan yang tercantum. Tampilan ini menggunakan data simulasi dengan status Tersedia dan Digunakan.' : 'Periksa waktu pembaruan yang tercantum. Status dihitung dari jadwal resmi, reservasi, dan penutupan ruang.'],
            ['Seberapa baru informasi ruangan?', 'Status dihitung ulang dari jadwal resmi, reservasi, dan penutupan ruang. Waktu pembaruan selalu tercantum di dekat data.'],
        ] as [$question, $answer])
            <details name="public-faq" @if($loop->first) open @endif class="sicma-card-enter group rounded-card border border-border bg-surface p-5 transition open:border-primary/40" style="--stagger: {{ $loop->index }}">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-control text-base font-semibold text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary [&::-webkit-details-marker]:hidden">
                    <span>{{ $question }}</span>
                    <i data-lucide="chevron-down" aria-hidden="true" class="size-4 shrink-0 text-muted transition-transform duration-200 group-open:rotate-180"></i>
                </summary>
                <p class="mt-3 text-sm leading-relaxed text-muted">{{ $answer }}</p>
            </details>
        @endforeach
    </div>
</section>
