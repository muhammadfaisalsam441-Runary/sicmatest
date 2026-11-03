@props(['href', 'icon', 'active' => false])

<a href="{{ $href }}" @if ($active) aria-current="page" @endif
   {{ $attributes->class([
       'flex items-center gap-3 rounded-control px-3 py-3 text-[15px]',
       'bg-primary-soft font-semibold text-primary' => $active,
       'text-ink hover:bg-primary-soft/50' => ! $active,
   ]) }}>
    <i data-lucide="{{ $icon }}" class="size-5 shrink-0 {{ $active ? 'text-primary' : 'text-muted' }}"></i>
    <span>{{ $slot }}</span>
</a>
