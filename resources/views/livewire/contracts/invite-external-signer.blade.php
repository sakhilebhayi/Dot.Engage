<div>
    @if(session('external-invited'))
        <p class="text-sm text-green-600 mb-2">{{ session('external-invited') }}</p>
    @endif

    @if($externalSigners->isNotEmpty())
        <div class="bg-white shadow rounded-lg p-4 mb-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">External Signers</h3>
            <ul class="space-y-1">
                @foreach($externalSigners as $signer)
                    <li class="text-xs text-gray-600 flex items-center justify-between">
                        <span>
                            {{ $signer->name }} ({{ $signer->email }})
                            @if($signer->sign_order !== null)
                                <span class="text-gray-400">— order {{ $signer->sign_order }}</span>
                            @endif
                        </span>
                        <span class="text-gray-400">
                            {{ $signer->isExpired() ? 'Expired' : ucfirst($signer->status) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($show)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="$set('show', false)">
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md space-y-4">
            <h3 class="text-lg font-semibold text-gray-900">Invite External Signer</h3>
            <p class="text-sm text-gray-500">They'll get an emailed link to view and sign this contract — no Dot.Engage account required.</p>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                <input wire:model="name" type="text"
                       class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input wire:model="email" type="email"
                       class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                @error('email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Link expires in (days)</label>
                <input wire:model="expiresInDays" type="number" min="1" max="90"
                       class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                @error('expiresInDays') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center gap-2">
                <input wire:model="enforceOrder" type="checkbox" id="enforce-order"
                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                <label for="enforce-order" class="text-sm text-gray-700">
                    Must sign after all previously-invited ordered signers
                </label>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button wire:click="$set('show', false)" class="px-4 py-2 text-sm text-gray-700 border border-gray-300 rounded-md hover:bg-gray-50">Cancel</button>
                <button wire:click="invite" class="px-4 py-2 text-sm text-white bg-indigo-600 rounded-md hover:bg-indigo-700">Send Invitation</button>
            </div>
        </div>
    </div>
    @endif
</div>
