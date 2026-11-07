<div class="op-queue">
    @if($statusMessage)
        <div class="flash is-ok" role="status">{{ $statusMessage }}</div>
    @endif

    <section aria-labelledby="h-pending">
        <div class="section-head"><h2 id="h-pending">Waiting for a decision <span class="n">{{ $pending->count() }}</span></h2></div>
        @if($pending->isNotEmpty())
            <ul class="rows">
                @foreach($pending as $proposal)
                    <li style="--cols: minmax(0, 1fr) minmax(240px, 320px)" wire:key="p-{{ $proposal->id }}">
                        <div>
                            <div class="op-id">{{ ucfirst($proposal->manager) }}</div>
                            <div class="op-meta">{{ $proposal->risk_summary }}</div>
                            <code class="cmd">{{ $proposal->proposed_command }}</code>
                        </div>
                        <div class="op-act">
                            <button type="button" wire:click="approve({{ $proposal->id }})"
                                    wire:confirm="Approve and queue this patch?"
                                    class="btn btn-sm"><span>Approve</span></button>
                            <form wire:submit.prevent="reject({{ $proposal->id }})">
                                <label class="sr-only" for="reject-{{ $proposal->id }}">Reason for rejecting {{ $proposal->manager }} patch</label>
                                <input class="input" id="reject-{{ $proposal->id }}" type="text" wire:model="rejectReasons.{{ $proposal->id }}"
                                       placeholder="Reason for rejecting">
                                @error("rejectReasons.{$proposal->id}")
                                    <p class="err">{{ $message }}</p>
                                @enderror
                                <button type="submit" class="btn btn-wax btn-sm"><span>Reject</span></button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="empty"><b>Nothing is waiting.</b>New proposals appear here when the platform finds a package worth updating.</p>
        @endif
    </section>

    <section class="section" aria-labelledby="h-decided">
        <div class="section-head"><h2 id="h-decided">Recent decisions <span class="n">{{ $reviewed->count() }}</span></h2></div>
        @if($reviewed->isNotEmpty())
            <ul class="rows">
                @foreach($reviewed as $proposal)
                    @php($tone = match($proposal->status) { 'applied' => 'is-ok', 'failed', 'rejected' => 'is-wax', 'approved' => 'is-warn', default => '' })
                    <li style="--cols: minmax(0, 1fr) 130px 130px">
                        <div>
                            <div class="op-id">{{ ucfirst($proposal->manager) }}</div>
                            <div class="op-meta">{{ $proposal->reviewer?->name ? 'Reviewed by '.$proposal->reviewer->name : 'No reviewer recorded' }}</div>
                        </div>
                        <span class="state {{ $tone }}">{{ ucfirst($proposal->status) }}</span>
                        <span class="row-end fig">{{ $proposal->reviewed_at?->format('j M Y') ?? 'Not decided' }}</span>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="empty">No decisions yet.</p>
        @endif
    </section>
</div>
