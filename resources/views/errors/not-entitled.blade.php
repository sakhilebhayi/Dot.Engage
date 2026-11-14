@extends('errors._ecosystem-layout', ['code' => 403, 'quiet' => true])

@section('title', $words['title'])
@section('message', $words['message'])

@section('actions')
    <a class="btn" href="{{ $platformsUrl }}"><span>Open Platforms in InfoDot</span></a>
@endsection

@if (($others ?? collect())->isNotEmpty())
    @section('after')
        <section class="switch-block" aria-labelledby="switch-h">
            <div class="section-head"><h2 id="switch-h">Or work in another of your businesses</h2></div>
            <ul class="switch-list">
                @foreach ($others as $team)
                    <li>
                        <form method="POST" action="{{ route('current-team.update') }}">
                            @method('PUT')
                            @csrf
                            <input type="hidden" name="team_id" value="{{ $team->id }}">
                            <button type="submit" class="switch-row"><span>{{ $team->name }}</span><span class="switch-go">Switch</span></button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </section>
    @endsection
@endif
