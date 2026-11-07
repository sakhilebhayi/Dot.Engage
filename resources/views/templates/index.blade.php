<x-app-layout title="Templates">
    <div class="page-head"><div><h1>Templates</h1><p class="lede">Starting points for agreements you make again and again. Save any document as a template from its engagement, then begin a new engagement from it.</p></div></div>
    @if ($templates->isNotEmpty())
        <ul class="rows">
            @foreach ($templates as $t)
                <li style="--cols: 1fr auto">
                    <div><span class="row-title">{{ $t->title }}</span><div class="row-sub">{{ $t->expires_in_days ? 'Signing window '.$t->expires_in_days.' days' : 'No signing window' }} · saved {{ $t->created_at->format('j M Y') }}</div></div>
                    <div class="page-actions">
                        @can('create', App\Models\Contract::class)<a class="btn btn-sm" href="{{ route('engagements.create', ['template' => $t->id]) }}"><span>Start an engagement</span></a>@endcan
                        @can('delete', $t)<form method="post" action="{{ route('contracts.templates.destroy', $t) }}" onsubmit="return confirm('Delete this template?')">@csrf @method('DELETE')<button class="btn btn-wax btn-sm" type="submit"><span>Delete</span></button></form>@endcan
                    </div>
                </li>
            @endforeach
        </ul>
    @else
        <p class="empty"><b>No templates yet.</b>Open an agreement and choose “Save as template” to keep it for next time.</p>
    @endif
</x-app-layout>
