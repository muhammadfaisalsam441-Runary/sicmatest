@props([
    'name',
    'options' => [],
    'value' => null,
    'placeholder' => null,
])

<select name="{{ $name }}" id="{{ $attributes->get('id', $name) }}"
        @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
        {{ $attributes->except('id')->class([
           'w-full' => ! str_contains((string) $attributes->get('class'), 'w-'),
            'rounded-control border bg-surface px-4 py-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/15',
            'border-danger' => $errors->has($name),
            'border-muted/60' => ! $errors->has($name),
        ]) }}>
    @if ($placeholder)
        <option value="">{{ $placeholder }}</option>
    @endif
    @foreach ($options as $optionValue => $optionLabel)
        <option value="{{ $optionValue }}" @selected((string) old($name, $value) === (string) $optionValue)>{{ $optionLabel }}</option>
    @endforeach
</select>
