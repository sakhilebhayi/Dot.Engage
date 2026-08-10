<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold text-gray-900">Templates</h3>
        <a href="{{ route('contracts.index') }}" class="text-sm text-indigo-600 hover:text-indigo-900">Back to Contracts</a>
    </div>

    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Title</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Signing window</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Saved</th>
                    <th class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($templates as $template)
                    <tr>
                        <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $template->title }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            {{ $template->expires_in_days ? $template->expires_in_days.' days' : '—' }}
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $template->created_at->format('M d, Y') }}</td>
                        <td class="px-6 py-4 text-right text-sm font-medium space-x-2">
                            <a href="{{ route('contracts.create', ['template' => $template->id]) }}"
                               class="text-indigo-600 hover:text-indigo-900">Use Template</a>
                            @can('delete', $template)
                                <button wire:click="delete({{ $template->id }})"
                                        wire:confirm="Delete this template?"
                                        class="text-red-600 hover:text-red-900">Delete</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-4 text-sm text-gray-500 text-center">
                            No templates yet. Save any contract as a template from its detail page.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
