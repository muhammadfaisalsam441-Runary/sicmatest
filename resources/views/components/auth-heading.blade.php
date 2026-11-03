@props(['title', 'subtitle' => null, 'danger' => false])

<div class="flex flex-col gap-3">
    <h2 class="text-[28px] font-semibold text-ink">{{ $title }}</h2>
    @if ($subtitle)
        <p @class(['text-sm', 'text-danger' => $danger, 'text-muted' => ! $danger])>{{ $subtitle }}</p>
    @endif
</div>
