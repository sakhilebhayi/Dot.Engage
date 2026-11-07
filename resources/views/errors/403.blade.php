@extends('errors._ecosystem-layout', ['code' => 403])

@section('title', "You don't have access to this")
@section('message', "It may belong to a different business, need a role you do not have, or need you to sign in first. If you think this is wrong, ask whoever owns the business.")

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
