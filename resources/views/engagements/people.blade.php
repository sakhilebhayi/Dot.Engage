<x-workspace :engagement="$engagement" :access="$access" :ws="$ws" tab="people">
    @php($canManage = $access->can('manage_participants'))
    <div class="section-head" style="margin-top:0">
        <h2>People <span class="n">{{ $participants->whereNull('revoked_at')->count() }}</span></h2>
        @if ($canManage)<a href="#invite" class="btn btn-sm" data-open-dialog="invite-dialog"><span>Add a person</span></a>@endif
    </div>

    @foreach ($engagement->parties as $party)
        @php($people = $participants->where('party_id', $party->id)->whereNull('revoked_at'))
        <section class="people-party pen pen-{{ $ws->penFor($party->id) }}" aria-labelledby="party-{{ $party->id }}">
            <h3 id="party-{{ $party->id }}" class="party-name"><span class="ini ini-sm">{{ strtoupper(mb_substr($party->name, 0, 1)) }}</span> <span class="by">{{ $party->name }}</span> @if($party->is_host)<span class="tag">Your business</span>@endif</h3>
            @if ($people->isNotEmpty())
                <ul class="rows">
                    @foreach ($people as $p)
                        <li style="--cols: 1fr auto auto">
                            <div>
                                <div class="row-title">{{ $p->name }} @if($p->required)<span class="tag">Required</span>@endif</div>
                                <div class="row-sub">{{ $p->email }} · {{ $p->role->label() }}@if($p->capabilities) · {{ collect($p->capabilities)->map(fn ($c) => ['approve' => 'approves', 'sign' => 'signs', 'host' => 'hosts meetings'][$c] ?? $c)->join(', ') }}@endif</div>
                            </div>
                            <div>
                                @if ($p->declined_at)<span class="state is-wax">Declined</span>
                                @elseif ($p->accepted_at && $p->verified_at)<span class="state is-ok">Joined</span>
                                @elseif ($p->accepted_at)<span class="state is-ok">On the team</span>
                                @elseif ($p->invitation_sent_at)<span class="state is-warn">Invited</span>
                                @else<span class="state">Not invited</span>@endif
                                @if ($p->last_seen_at)<div class="row-sub fig">Seen <time data-local datetime="{{ $p->last_seen_at->toIso8601String() }}">{{ $p->last_seen_at->format('j M H:i') }}</time></div>@endif
                            </div>
                            <div class="page-actions">
                                @if ($canManage && $p->role->value !== 'owner')
                                    @unless($p->user_id)
                                        <form method="post" action="{{ route('engagements.resend', [$engagement, $p]) }}">@csrf<button class="btn btn-quiet btn-sm" type="submit"><span>{{ $p->accepted_at ? 'New link' : 'Resend' }}</span></button></form>
                                    @endunless
                                    <form method="post" action="{{ route('engagements.revoke', [$engagement, $p]) }}" onsubmit="return confirm('Remove {{ addslashes($p->name) }}? They lose access at once.')">@csrf<button class="btn btn-wax btn-sm" type="submit"><span>Remove</span></button></form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="empty" style="padding:14px 0">Nobody from {{ $party->name }} yet.@if($canManage) Add the person who will review and sign.@endif</p>
            @endif
        </section>
    @endforeach

    @if ($canManage)
        <dialog class="sheet" id="invite-dialog" aria-labelledby="invite-title">
            <form method="post" action="{{ route('engagements.invite', $engagement) }}">
                @csrf
                <div class="sheet-head"><h2 id="invite-title">Add a person</h2><button type="button" class="iconbtn" data-close-dialog aria-label="Close"><x-icon name="x" /></button></div>
                <div class="sheet-body">
                    <div class="field"><label for="p-party">Which party</label>
                        <select class="select" id="p-party" name="party_id">
                            @foreach ($engagement->parties as $party)<option value="{{ $party->id }}" @selected(old('party_id', $party->is_host ? null : $party->id) == $party->id)>{{ $party->name }}@if($party->is_host) (your business)@endif</option>@endforeach
                        </select>
                    </div>
                    <div class="row-2">
                        <div class="field"><label for="p-name">Name</label><input class="input" id="p-name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="off"></div>
                        <div class="field"><label for="p-email">Email</label><input class="input" id="p-email" name="email" type="email" value="{{ old('email') }}" required maxlength="190" autocomplete="off"></div>
                    </div>
                    <div class="field"><label for="p-role">Role</label>
                        <select class="select" id="p-role" name="role">
                            @foreach ($roles as $r)<option value="{{ $r->value }}" @selected(old('role', 'external') === $r->value)>{{ $r->label() }}</option>@endforeach
                        </select>
                        <span class="hint">For people outside your business, the role is set to External party.</span>
                    </div>
                    <fieldset class="field" style="border:0;padding:0;margin:0 0 16px">
                        <legend class="label" style="padding:0;margin-bottom:6px">They also</legend>
                        <label class="check"><input type="checkbox" name="capabilities[]" value="sign" checked> Sign the agreement</label>
                        <label class="check"><input type="checkbox" name="capabilities[]" value="approve"> Approve it before signing</label>
                        <label class="check"><input type="checkbox" name="capabilities[]" value="host"> Host meetings</label>
                    </fieldset>
                    <label class="check"><input type="hidden" name="required" value="0"><input type="checkbox" name="required" value="1" checked> Their presence is required before signing</label>
                    @error('email')<p class="err" style="margin-top:10px">{{ $message }}</p>@enderror
                </div>
                <div class="sheet-foot"><button type="button" class="btn btn-quiet" data-close-dialog><span>Cancel</span></button><button class="btn" type="submit"><span>Add and invite</span></button></div>
            </form>
        </dialog>
        @if ($errors->has('email'))<script>document.addEventListener('DOMContentLoaded',()=>document.getElementById('invite-dialog')?.showModal())</script>@endif
    @endif
</x-workspace>
