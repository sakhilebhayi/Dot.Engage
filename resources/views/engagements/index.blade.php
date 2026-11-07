<x-app-layout title="Engagements">
    <div class="page-head">
        <div>
            <h1>Engagements</h1>
            <p class="lede">Every agreement your business is working on, with the other party, the document and the signatures in one place.</p>
        </div>
        <div class="page-actions"><a class="btn" href="{{ route('engagements.create') }}"><span>New engagement</span></a></div>
    </div>

    <div class="tabs" style="margin-bottom:8px">
        @foreach (['open' => 'Open', 'closed' => 'Closed', 'all' => 'All'] as $key => $label)
            <a href="{{ route('engagements.index', array_filter(['show' => $key, 'q' => $q])) }}" @if ($filter === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </div>

    @if ($engagements->isNotEmpty())
        <ul class="rows">
            @foreach ($engagements as $e)
                <li style="--cols: 1fr minmax(170px,auto) 140px">
                    <div>
                        <a class="row-title" href="{{ route('engagements.show', $e) }}">{{ $e->title }}</a>
                        <div class="row-sub">{{ $e->parties->pluck('name')->join(' with ') }} · {{ $e->kind->label() }}</div>
                    </div>
                    <x-state :state="$e->state" />
                    <span class="row-end fig">{{ $e->reference }}</span>
                </li>
            @endforeach
        </ul>
        <div style="margin-top:18px">{{ $engagements->links() }}</div>
    @else
        <p class="empty">
            @if ($q !== '')
                <b>Nothing matches “{{ $q }}”.</b>Search covers titles, references and party names.
            @else
                <b>No {{ $filter === 'all' ? '' : $filter }} engagements.</b>Start one when you are ready to agree something with another business.
            @endif
        </p>
    @endif
</x-app-layout>
