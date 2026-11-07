@extends('errors._ecosystem-layout', ['code' => 500])

@section('title', 'Something went wrong on our end')
@section('message', 'That is on us, not you. An unexpected error stopped this page. Try again in a moment, and if it keeps happening, tell us.')

@section('actions')
    <a class="btn" href="/"><span>Go home</span></a>
    @if (Route::has('contact'))
        <a class="btn btn-quiet" href="{{ route('contact') }}"><span>Contact support</span></a>
    @endif
@endsection
