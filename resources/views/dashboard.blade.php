<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.2rem;letter-spacing:-0.01em;">Client Engagement</h1>
            <p style="font-size:0.78rem;color:#52525b;margin:0;">{{ now()->format('l, F j, Y') }}</p>
        </div>
        <a href="{{ route('video.index') }}" class="dot-btn dot-btn-primary">
            <span class="material-symbols-rounded" style="font-size:15px;">add</span>
            New Session
        </a>
    </div>

    {{-- KPI Strip --}}
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;margin-bottom:2rem;">
        @php
            $kpis = [
                ['label' => 'Total Contracts',      'val' => $stats['total_contracts'],      'color' => 'var(--accent)'],
                ['label' => 'Pending Signatures',    'val' => $stats['pending_signatures'],    'color' => '#f59e0b'],
                ['label' => 'Signed Contracts',      'val' => $stats['signed_contracts'],      'color' => '#10b981'],
                ['label' => 'Active Conversations',  'val' => $stats['active_conversations'],  'color' => '#3b82f6'],
                ['label' => 'Active Video Sessions', 'val' => $stats['active_video_sessions'], 'color' => '#06b6d4'],
            ];
        @endphp
        @foreach($kpis as $kpi)
        <div class="dot-card" style="padding:1.25rem 1.5rem;">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.75rem;">{{ $kpi['label'] }}</div>
            <div class="metric-val" style="font-size:2rem;font-weight:600;color:{{ $kpi['color'] }};">{{ $kpi['val'] }}</div>
        </div>
        @endforeach
    </div>

    {{-- Two columns: recent contracts + active conversations --}}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">

        {{-- Recent Contracts --}}
        <div class="dot-card" style="padding:1.5rem;">
            <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0 0 1.25rem;">Recent Contracts</h3>
            @forelse($recentContracts as $contract)
            <div style="display:flex;align-items:center;gap:0.75rem;padding:0.65rem 0;border-bottom:1px solid rgba(255,255,255,0.05);">
                <div style="width:32px;height:32px;border-radius:8px;background:rgba(var(--accent-rgb),0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <span class="material-symbols-rounded" style="font-size:15px;color:var(--accent);">description</span>
                </div>
                <div style="min-width:0;flex:1;">
                    <div style="font-size:12px;font-weight:600;color:#d4d4d8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $contract->title }}</div>
                    <div style="font-size:11px;color:#52525b;">{{ $contract->creator->name ?? 'Unknown' }} · {{ $contract->created_at->format('M d') }}</div>
                </div>
                <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#52525b;flex-shrink:0;">{{ ucfirst($contract->status) }}</div>
            </div>
            @empty
            <div style="text-align:center;padding:2rem 0;">
                <span class="material-symbols-rounded" style="font-size:32px;color:#3f3f46;display:block;margin-bottom:0.75rem;">description</span>
                <p style="font-size:0.8rem;color:#52525b;margin:0;">No contracts yet. Create your first contract to get started.</p>
            </div>
            @endforelse
        </div>

        {{-- Active Conversations --}}
        <div class="dot-card" style="padding:1.5rem;">
            <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0 0 1.25rem;">Active Conversations</h3>
            @forelse($activeConversations as $conversation)
            <div style="display:flex;align-items:center;gap:0.75rem;padding:0.65rem 0;border-bottom:1px solid rgba(255,255,255,0.05);">
                <div style="width:28px;height:28px;border-radius:50%;background:rgba(var(--accent-rgb),0.15);border:1px solid rgba(var(--accent-rgb),0.25);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--accent);font-family:'Syne',sans-serif;flex-shrink:0;">{{ strtoupper(substr($conversation->name ?? 'D', 0, 1)) }}</div>
                <div style="min-width:0;flex:1;">
                    <div style="font-size:12px;font-weight:600;color:#d4d4d8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $conversation->name ?? 'Direct message' }}</div>
                    <div style="font-size:11px;color:#52525b;">{{ $conversation->participants_count }} {{ $conversation->participants_count === 1 ? 'participant' : 'participants' }} · {{ $conversation->last_message_at->diffForHumans() }}</div>
                </div>
            </div>
            @empty
            <div style="text-align:center;padding:2rem 0;">
                <span class="material-symbols-rounded" style="font-size:32px;color:#3f3f46;display:block;margin-bottom:0.75rem;">forum</span>
                <p style="font-size:0.8rem;color:#52525b;margin:0;">No active conversations yet.</p>
            </div>
            @endforelse
        </div>

    </div>

</div>

</x-app-layout>
