<div class="space-y-4">
    <div class="flex items-center justify-between">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search contracts…"
               class="w-64 rounded-md border-gray-300 shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500">
        <div class="flex items-center gap-3">
            <select wire:model.live="statusFilter" class="rounded-md border-gray-300 shadow-sm text-sm">
                <option value="">All statuses</option>
                <option value="draft">Draft</option>
                <option value="pending">Pending</option>
                <option value="signed">Signed</option>
                <option value="expired">Expired</option>
                <option value="rejected">Rejected</option>
            </select>
            <a href="{{ route('contracts.templates') }}"
               class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-50">
                Templates
            </a>
            <a href="{{ route('contracts.create') }}"
               class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">
                New Contract
            </a>
        </div>
    </div>

    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Title</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Expires</th>
                    <th class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($contracts as $contract)
                    <tr>
                        <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $contract->title }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                {{ $contract->status === 'signed' ? 'bg-green-100 text-green-800' : ($contract->status === 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800') }}">
                                {{ ucfirst($contract->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $contract->created_at->format('M d, Y') }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $contract->expires_at?->format('M d, Y') ?? '—' }}</td>
                        <td class="px-6 py-4 text-right text-sm font-medium space-x-2">
                            <a href="{{ route('contracts.show', $contract) }}" class="text-indigo-600 hover:text-indigo-900">View</a>
                            @can('delete', $contract)
                                <button wire:click="delete({{ $contract->id }})"
                                        wire:confirm="Delete this contract?"
                                        class="text-red-600 hover:text-red-900">Delete</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-4 text-sm text-gray-500 text-center">No contracts found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $contracts->links() }}</div>
</div>
