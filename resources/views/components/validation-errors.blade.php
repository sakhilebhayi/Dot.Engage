@if ($errors->any())
    <div {{ $attributes->merge(['class' => 'flash is-error']) }} role="alert">
        <x-icon name="warn" />
        <div>
            <b>{{ __('Check these and try again.') }}</b>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
