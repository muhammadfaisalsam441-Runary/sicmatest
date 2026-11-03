@props(['padding' => 'p-6'])

<section {{ $attributes->merge(['class' => "rounded-card border border-border bg-surface {$padding}"]) }}>
    {{ $slot }}
</section>
