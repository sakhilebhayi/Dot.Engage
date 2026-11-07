<x-plain-layout title="Meeting ended">
    <div class="page-head"><div><h1>{{ $meeting->status === 'cancelled' ? 'This meeting was cancelled' : 'This meeting has ended' }}</h1>
        <p class="lede">{{ $meeting->title }}. The link no longer opens a room. Anything agreed belongs in the engagement, not in the call.</p></div></div>
    <a class="btn" href="{{ route('engagements.show', $engagement) }}"><span>Back to {{ $engagement->reference }}</span></a>
</x-plain-layout>
