@props(['sid' => null])
<div class="section-head">
    <h2 @if ($sid) id="{{ $sid }}" @endif>{{ $title }}</h2>
    {{ $aside ?? '' }}
</div>
<p class="sect-desc">{{ $description }}</p>
