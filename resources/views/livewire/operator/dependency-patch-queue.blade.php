<div class="space-y-6">
    @if($statusMessage)
        <div class="rounded-md bg-blue-50 px-4 py-3 text-sm text-blue-800">{{ $statusMessage }}</div>
    @endif

    <div>
        <h3 class="text-sm font-semibold text-gray-900 mb-3">Pending Dependency Patches</h3>
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Manager</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Risk</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Proposed Command</th>
                        <th class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($pending as $proposal)
                        <tr>
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ ucfirst($proposal->manager) }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $proposal->risk_summary }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500 font-mono">{{ $proposal->proposed_command }}</td>
                            <td class="px-6 py-4 text-right text-sm font-medium">
                                <div class="flex items-center justify-end gap-2 mb-2">
                                    <button wire:click="approve({{ $proposal->id }})"
                                            wire:confirm="Approve and queue this patch?"
                                            class="text-green-600 hover:text-green-900">Approve</button>
                                </div>
                                <div class="flex items-center justify-end gap-2">
                                    <input type="text" wire:model="rejectReasons.{{ $proposal->id }}"
                                           placeholder="Reason for rejecting…"
                                           class="w-48 rounded-md border-gray-300 shadow-sm text-xs">
                                    <button wire:click="reject({{ $proposal->id }})"
                                            class="text-red-600 hover:text-red-900">Reject</button>
                                </div>
                                @error("rejectReasons.{$proposal->id}")
                                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                @enderror
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-sm text-gray-500 text-center">No pending proposals.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>
        <h3 class="text-sm font-semibold text-gray-900 mb-3">Recent Decisions</h3>
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Manager</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reviewer</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Decided</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($reviewed as $proposal)
                        <tr>
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ ucfirst($proposal->manager) }}</td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                    {{ $proposal->status === 'applied' ? 'bg-green-100 text-green-800' : ($proposal->status === 'failed' ? 'bg-red-100 text-red-800' : ($proposal->status === 'approved' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800')) }}">
                                    {{ ucfirst($proposal->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $proposal->reviewer?->name ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $proposal->reviewed_at?->format('M d, Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-sm text-gray-500 text-center">No decisions yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
