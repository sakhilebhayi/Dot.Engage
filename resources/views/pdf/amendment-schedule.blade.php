<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Schedule of agreed amendments</title>
<style>
    @page { margin: 56px 56px 64px; }
    body { font-family: 'DejaVu Serif', 'Times New Roman', serif; font-size: 11px; color: #15181b; line-height: 1.55; }
    h1 { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 17px; margin: 0 0 4px; }
    .sub { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9.5px; color: #596067; margin-bottom: 22px; }
    .item { border-top: 1px solid #b9bec3; padding: 12px 0; page-break-inside: avoid; }
    .head { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9.5px; color: #353b41; margin-bottom: 6px; }
    .was { background: #f6e5e5; text-decoration: line-through; padding: 5px 8px; margin: 4px 0; }
    .now { background: #e1f0e8; padding: 5px 8px; margin: 4px 0; }
    .why { font-size: 10px; color: #353b41; margin-top: 4px; }
    .foot { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 8.5px; color: #596067; margin-top: 24px; border-top: 1px solid #b9bec3; padding-top: 8px; }
</style></head><body>
<h1>Schedule of agreed amendments</h1>
<div class="sub">{{ $contract->title }} &middot; {{ $engagement->reference }} &middot; to be read with version {{ $base->version_number }} (SHA-256 {{ substr($base->sha256 ?? '', 0, 16) }}) &middot; this is version {{ $number }}</div>
<p style="margin-bottom:14px">The parties agreed the following changes. Where this schedule and the earlier pages differ, this schedule applies.</p>
@foreach ($amendments as $i => $a)
    <div class="item">
        <div class="head"><b>{{ $i + 1 }}.</b> {{ $a->typeLabel() }}@if($a->clause_ref) &middot; clause {{ $a->clause_ref }}@endif @if($a->page) &middot; page {{ $a->page }} of version {{ $base->version_number }}@endif</div>
        @if ($a->original_text)<div class="was">{{ $a->original_text }}</div>@endif
        @if ($a->proposed_text)<div class="now">{{ $a->proposed_text }}</div>@endif
        @if ($a->explanation)<div class="why">Reason: {{ $a->explanation }}</div>@endif
        <div class="head" style="margin-top:6px">Proposed by {{ $a->author_name }} on {{ $a->created_at->format('j F Y') }}; accepted by {{ $a->reviewer?->name ?? 'the reviewer' }} on {{ $a->reviewed_at?->format('j F Y') }}.</div>
    </div>
@endforeach
<div class="foot">Generated {{ $generatedAt->format('j F Y, H:i') }} UTC by Dot.Engage from the changes recorded in {{ $engagement->reference }}. The text above was agreed in the engagement; it was not typed into this document by hand.</div>
</body></html>
