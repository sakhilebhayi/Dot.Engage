{{--
    Shown with a 503 when InfoDot has switched Dot.Engage off (maintenance or unavailable). It says so plainly and reads nothing of the
    business's work: the engagements, documents and signed agreements already here are kept exactly as they are. It is not a purchase or
    permission problem, so there is no way round and no other action than signing out.
--}}
@extends('errors._ecosystem-layout', ['code' => 503, 'quiet' => true])

@section('title', 'Dot.Engage is not available right now')
@section('message', ($message ?? 'Dot.Engage is temporarily unavailable.').' Nothing has been removed: the engagements, documents and signed agreements are kept as they were.')
