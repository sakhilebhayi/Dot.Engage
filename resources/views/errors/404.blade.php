@extends('errors._ecosystem-layout', ['code' => 404])

@section('title', "We couldn't find that page")
@section('message', "The page may have moved, been renamed, or never existed. Check the link, or start from the home page.")

@section('actions')
    <a class="btn" href="/"><span>Go home</span></a>
    @auth
        <a class="btn btn-quiet" href="{{ route('dashboard') }}"><span>Go to your desk</span></a>
    @else
        @if (Route::has('login'))
            <a class="btn btn-quiet" href="{{ route('login') }}"><span>Sign in</span></a>
        @endif
    @endauth
@endsection
