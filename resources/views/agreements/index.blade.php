<x-app-layout title="Agreements">
    <div class="page-head"><div><h1>Agreements</h1><p class="lede">Completed agreements. Each one has its signed copy, its evidence record and a public check anyone with the file can run.</p></div></div>
    @if ($agreements->isNotEmpty())
        <ul class="rows">
            @foreach ($agreements as $e)
                <li style="--cols: 1fr auto 130px"><div><a class="row-title" href="{{ route('engagements.signing', $e) }}">{{ $e->title }}</a><div class="row-sub">{{ $e->parties->pluck('name')->join(' with ') }}</div></div><span class="fig row-end">{{ $e->reference }}</span><time class="row-end" data-local="day" datetime="{{ $e->completed_at?->toIso8601String() }}">{{ $e->completed_at?->format('j M Y') }}</time></li>
            @endforeach
        </ul>
        {{ $agreements->links() }}
    @else
        <p class="empty"><b>No completed agreements yet.</b>When everyone has signed, the agreement and its evidence are stored here.</p>
    @endif
</x-app-layout>
