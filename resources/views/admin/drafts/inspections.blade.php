@use('App\Support\Wita')
@extends('layouts.admin')
@section('title', 'Pemeriksaan Jadwal')
@section('breadcrumb', 'Pemeriksaan Jadwal')
@section('content')
    <div class="flex flex-col gap-6">
        <x-page-header title="Daftar & Pemeriksaan Draf Jadwal" :subtitle="($period?->name ?? 'Belum ada semester aktif').' • Kelola penempatan sesi draf atau periksa kesiapan sebelum ditetapkan menjadi jadwal resmi.'">
            <x-slot:actions>
                <x-button variant="secondary" icon="sparkles" :href="route('admin.scheduler.create', ['susun' => 1])">Susun Otomatis</x-button>
                @if ($period && $versions->isEmpty())
                    <form method="POST" action="{{ route('admin.drafts.store') }}">
                        @csrf
                        <x-button type="submit" icon="plus">Buat Draf Manual</x-button>
                    </form>
                @endif
            </x-slot:actions>
        </x-page-header>
        <x-card padding="px-2.5 py-2" class="w-fit max-w-full"><x-schedule-steps :current="4" /></x-card>
        @include('admin.partials.scheduling-tabs')
        @forelse ($versions as $version)
            @php($isActive = ($activeDraftId ?? null) === $version->id)
            <x-card class="flex flex-wrap items-center justify-between gap-4 {{ $isActive ? 'border-primary/40 bg-primary-soft/20' : '' }}">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($isActive)
                            <span class="rounded-md bg-primary px-2 py-0.5 text-xs font-semibold text-white">Draf Aktif</span>
                        @endif
                        <h2 class="text-lg font-semibold">Draf v{{ $version->version_no }}</h2>
                        <x-status-badge :variant="$version->issues_count === 0 ? 'success' : 'warning'">
                            {{ $version->issues_count === 0 ? '0 masalah wajib' : $version->issues_count.' temuan wajib' }}
                        </x-status-badge>
                    </div>
                    <p class="mt-1 text-sm text-muted">{{ $version->source->label() }} • {{ $version->sessions_count }} sesi • {{ $version->issues_count }} temuan wajib tersimpan</p>
                    <p class="text-xs text-muted">Dibuat {{ Wita::stamp($version->created_at) }} • Diperbarui {{ Wita::stamp($version->updated_at) }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if (! $isActive)
                        <form method="POST" action="{{ route('admin.drafts.activate', $version) }}">
                            @csrf
                            <x-button type="submit" variant="soft" icon="check">Gunakan</x-button>
                        </form>
                    @endif
                    <x-button variant="secondary" icon="pencil" :href="route('admin.drafts.show', $version)">Edit Sesi Draf</x-button>
                    <x-button icon="list-checks" :href="route('admin.drafts.inspect', $version)">Buka Pemeriksaan</x-button>
                    <x-button type="button"
                              variant="danger-outline"
                              icon="archive"
                              x-data
                              x-on:click="$dispatch('open-archive-draft', { versionNo: {{ $version->version_no }}, action: @js(route('admin.drafts.archive', $version)), isActive: @js($isActive) }); $dispatch('open-modal', 'archive-draft-modal')">
                        Arsipkan
                    </x-button>
                </div>
            </x-card>
        @empty
            <x-empty-state title="Belum ada draf untuk diperiksa" message="Buat draf revisi atau susun jadwal terlebih dahulu.">
                <x-button :href="route('admin.scheduler.create')">Buka Susun Jadwal</x-button>
                <x-button variant="secondary" :href="route('admin.schedules.index')">Jadwal Resmi</x-button>
            </x-empty-state>
        @endforelse
    </div>
@endsection

@push('dialogs')
    @if ($versions->isNotEmpty())
        <x-modal name="archive-draft-modal" width="max-w-md" labelled-by="archive-draft-inspections-title">
            <form method="POST"
                  x-data="{ versionNo: null, action: '', isActive: false, submitting: false }"
                  x-on:open-archive-draft.window="versionNo = $event.detail.versionNo; action = $event.detail.action; isActive = Boolean($event.detail.isActive); submitting = false;"
                  x-bind:action="action"
                  x-on:submit="if (!action || submitting) { $event.preventDefault(); return; } submitting = true"
                  x-on:sicma:form-error.window="submitting = false"
                  x-on:sicma:form-complete.window="submitting = false"
                  class="flex flex-col gap-5">
                @csrf
                <input type="hidden" name="redirect" value="inspections">

                <div class="flex items-start justify-between gap-4">
                    <span class="flex size-12 items-center justify-center rounded-control bg-danger-soft text-danger">
                        <i data-lucide="archive" aria-hidden="true" class="size-5"></i>
                    </span>
                    <button type="button"
                            x-on:click="$dispatch('close-modal', 'archive-draft-modal')"
                            class="inline-flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-control text-muted transition hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                            aria-label="Tutup">
                        <i data-lucide="x" aria-hidden="true" class="size-5"></i>
                    </button>
                </div>

                <div class="flex flex-col gap-2">
                    <h2 id="archive-draft-inspections-title" class="text-xl font-semibold text-ink">
                        Arsipkan Draf v<span x-text="versionNo"></span>?
                    </h2>
                    <p class="text-sm leading-relaxed text-muted">
                        Draf ini akan dipindahkan dari daftar kerja agar tidak menumpuk. Jadwal resmi tetap aman dan tidak berubah.
                    </p>
                    <p x-show="isActive" x-cloak class="text-xs font-medium text-warning-ink">
                        Karena ini draf aktif, sistem akan otomatis mengalihkan draf aktif ke draf terbuka terbaru jika masih ada.
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <x-button type="button" variant="secondary" x-on:click="$dispatch('close-modal', 'archive-draft-modal')">Batal</x-button>
                    <x-button type="submit" variant="danger" x-bind:disabled="submitting">Ya, arsipkan</x-button>
                </div>
            </form>
        </x-modal>
    @endif
@endpush
