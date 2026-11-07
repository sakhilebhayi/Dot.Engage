<x-workspace :engagement="$engagement" :access="$access" :ws="$ws" tab="agreement">
    <div class="section-head" style="margin-top:0"><h2>Compare versions of {{ $document->title }}</h2><a href="{{ route('engagements.show', ['engagement' => $engagement, 'doc' => $document->id]) }}">Back to the agreement</a></div>
    <form method="get" class="docbar" style="margin:14px 0">
        <label for="va">From</label>
        <select class="select" id="va" name="a" style="width:auto" onchange="this.form.submit()">@foreach ($versions as $v)<option value="{{ $v->id }}" @selected($v->id === $a->id)>v{{ $v->version_number }} · {{ $v->created_at->format('j M') }}</option>@endforeach</select>
        <label for="vb">To</label>
        <select class="select" id="vb" name="b" style="width:auto" onchange="this.form.submit()">@foreach ($versions as $v)<option value="{{ $v->id }}" @selected($v->id === $b->id)>v{{ $v->version_number }} · {{ $v->created_at->format('j M') }}</option>@endforeach</select>
    </form>
    <dl class="kv" style="margin-bottom:22px">
        <dt>v{{ $a->version_number }} hash</dt><dd class="fig">{{ $a->sha256 }}</dd>
        <dt>v{{ $b->version_number }} hash</dt><dd class="fig">{{ $b->sha256 }}</dd>
        <dt>Why v{{ $b->version_number }}</dt><dd>{{ $b->change_notes ?: 'No note' }}</dd>
    </dl>
    <div id="compare-root" data-config='@json(['a' => ['url' => $urlA, 'number' => $a->version_number], 'b' => ['url' => $urlB, 'number' => $b->version_number]])'><p class="empty"><span class="spinner" style="display:inline-block;vertical-align:-2px"></span> Reading both versions…</p></div>
    @push('scripts')@vite('resources/js/viewer/compare.js')@endpush
</x-workspace>
