@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'err']) }}>{{ $message }}</p>
@enderror
