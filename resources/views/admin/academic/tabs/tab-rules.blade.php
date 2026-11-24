            <x-card class="flex flex-col gap-4">
                <div class="flex flex-col gap-1">
                    <h2 class="text-lg font-semibold">Hari &amp; jam kuliah (slot)</h2>
                    <p class="text-sm font-semibold text-primary">Perkuliahan hanya Senin–Jumat. Sabtu dan Minggu tidak digunakan untuk jadwal kuliah. Khusus hari Jumat pukul 12.00–13.10 WITA tidak ada perkuliahan.</p>
                    <p class="text-sm text-muted">Slot adalah acuan pemeriksaan impor dan susun otomatis. Jam pada jadwal tidak dikoreksi otomatis bila berbeda (mis. 15.40 vs 15.45).</p>
                </div>
                <form method="POST" action="{{ route('admin.academic.slots.update', $period) }}" class="flex flex-col gap-4">
                    @csrf
                    @method('PUT')
                    @if ($errors->any())
                        <x-alert variant="danger" title="Slot waktu belum valid">
                            <ul class="list-disc pl-4 text-xs">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </x-alert>
                    @endif
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        @foreach ($period->timeSlots as $slot)
                            <div class="flex flex-wrap items-center gap-2 rounded-control border border-border px-3 py-2">
                                <span class="w-10 shrink-0 text-sm font-semibold">{{ $slot->label }}</span>
                                <div class="flex min-w-[13.5rem] flex-1 items-center gap-2 sm:min-w-0">
                                    <input type="time" name="slots[{{ $slot->id }}][start]" min="{{ config('sicma.operating_hours.start') }}" max="{{ config('sicma.operating_hours.end') }}" value="{{ $clock($slot->start_minute) }}" class="min-w-0 flex-1 rounded border border-border px-2 py-1 text-sm" aria-label="Mulai slot {{ $slot->label }}">
                                    <span class="shrink-0 text-muted">–</span>
                                    <input type="time" name="slots[{{ $slot->id }}][end]" min="{{ config('sicma.operating_hours.start') }}" max="{{ config('sicma.operating_hours.end') }}" value="{{ $clock($slot->end_minute) }}" class="min-w-0 flex-1 rounded border border-border px-2 py-1 text-sm" aria-label="Selesai slot {{ $slot->label }}">
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="flex justify-end"><x-button type="submit">Simpan slot</x-button></div>
                </form>
            </x-card>
