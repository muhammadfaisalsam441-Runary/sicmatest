@props(['title', 'subtitle' => null, 'danger' => false])

<div class="flex flex-col gap-2 text-center">
    <h2 class="text-[28px] font-extrabold tracking-tight text-ink">{{ $title }}</h2>
    @if ($subtitle)
        <p @class(['text-sm', 'text-danger' => $danger, 'text-muted' => ! $danger])>{{ $subtitle }}</p>
    @endif
</div>
