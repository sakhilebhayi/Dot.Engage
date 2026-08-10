<div>
    @if(session('template-saved'))
        <p class="text-sm text-green-600 mb-2">{{ session('template-saved') }}</p>
    @endif

    @if($show)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="$set('show', false)">
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md space-y-4">
            <h3 class="text-lg font-semibold text-gray-900">Save as Template</h3>
            <p class="text-sm text-gray-500">Reuse this contract's file, description, and signing window for future contracts.</p>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Template name</label>
                <input wire:model="title" type="text"
                       class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                @error('title') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button wire:click="$set('show', false)" class="px-4 py-2 text-sm text-gray-700 border border-gray-300 rounded-md hover:bg-gray-50">Cancel</button>
                <button wire:click="save" class="px-4 py-2 text-sm text-white bg-indigo-600 rounded-md hover:bg-indigo-700">Save Template</button>
            </div>
        </div>
    </div>
    @endif
</div>
