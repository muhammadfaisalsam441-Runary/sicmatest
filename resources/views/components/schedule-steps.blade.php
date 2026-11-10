@props(['current' => 1, 'version' => null])
@php
    $draftVersion = $version && in_array($version->status->value, ['draft', 'ready'], true) ? $version : null;
    $links = [
        route('admin.academic.index'),
        route('admin.teaching.index'),
        $draftVersion ? route('admin.drafts.show', $draftVersion) : route('admin.scheduler.create'),
        $draftVersion ? route('admin.drafts.inspect', $draftVersion) : route('admin.drafts.inspections'),
        route('admin.schedules.index', $draftVersion ? ['tab' => 'penetapan', 'draf' => $draftVersion->id] : []),
    ];
@endphp
<div class="overflow-x-auto">
    <x-stepper :steps="['Data', 'Tim mengajar', 'Draf jadwal', 'Pemeriksaan', 'Jadwal Resmi']" :current="$current" :links="$links" class="min-w-max" />
</div>
