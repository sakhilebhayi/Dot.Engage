@extends('errors._ecosystem-layout', ['code' => 419])

@section('title', 'Your session timed out')
@section('message', 'For your security a form expires after a while without use. Nothing was saved, so go back and send it again.')

@section('actions')
    <a class="btn" href="{{ url()->previous() === url()->current() ? '/' : url()->previous() }}"><span>Go back and try again</span></a>
    <a class="btn btn-quiet" href="/"><span>Go home</span></a>
@endsection
