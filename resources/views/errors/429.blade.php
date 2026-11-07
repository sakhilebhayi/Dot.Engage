@extends('errors._ecosystem-layout', ['code' => 429])

@section('title', 'Slow down a little')
@section('message', 'There were too many requests from you in a short time. This protects everyone, and it clears if you wait a minute and try again.')

@section('actions')
    <a class="btn" href="/"><span>Go home</span></a>
@endsection
