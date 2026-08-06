@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'border-[var(--line)] bg-[var(--panel)] text-[var(--ink)] rounded-lg shadow-sm transition duration-150 ease-in-out focus:border-[var(--gold)] focus:ring-[var(--gold)]']) !!}>
