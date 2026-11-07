@extends('errors._ecosystem-layout', ['code' => 503])

@section('title', "We'll be right back")
@section('message', 'Dot.Engage is down for a short maintenance. Nothing you were working on is lost. Check back in a few minutes.')

@section('actions')
    <a class="btn" href="/"><span>Try again</span></a>
@endsection
