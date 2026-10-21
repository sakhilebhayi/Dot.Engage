<div class="space-y-4">
    <div class="bg-white shadow rounded-lg p-6 space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Start or Join a Video Session</h2>

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
            {{-- Create new session --}}
            <div class="border border-gray-200 rounded-lg p-4 space-y-3">
                <h3 class="text-sm font-semibold text-gray-700">New Session</h3>
                <div>
                    <label class="block text-xs font-medium text-gray-600">Attach Contract (optional)</label>
                    <select wire:model="contractId" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">— None —</option>
                        @foreach($teamContracts as $contract)
                            <option value="{{ $contract->id }}">{{ $contract->title }}</option>
                        @endforeach
                    </select>
                </div>
                @can('create', App\Models\VideoSession::class)
                    <button wire:click="create" wire:loading.attr="disabled"
                            class="w-full px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">
                        Create Session
                    </button>
                @else
                    <p class="text-xs text-gray-500">Your role is read-only, so you can join a session but not start one.</p>
                @endcan
            </div>

            {{-- Join existing --}}
            <div class="border border-gray-200 rounded-lg p-4 space-y-3">
                <h3 class="text-sm font-semibold text-gray-700">Join Session</h3>
                <div>
                    <label class="block text-xs font-medium text-gray-600">Room ID</label>
                    <input wire:model="joinRoomId" type="text" placeholder="Enter room ID…"
                           class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                    @error('joinRoomId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <button wire:click="join"
                        class="w-full px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700">
                    Join Session
                </button>
            </div>
        </div>
    </div>
</div>
