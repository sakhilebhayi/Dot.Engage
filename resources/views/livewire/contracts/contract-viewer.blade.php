<div class="flex flex-col h-full space-y-4">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-semibold text-gray-900">{{ $contract->title }}</h2>
            <p class="text-sm text-gray-500">Version {{ $contract->versions->count() }} &middot; {{ ucfirst($contract->status) }}</p>
        </div>
        <div class="flex items-center gap-3">
            @can('sign', $contract)
                <button wire:click="$dispatch('open-signature-pad', { contractId: {{ $contract->id }} })"
                        class="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700">
                    Sign Contract
                </button>
            @endcan
            @can('update', $contract)
                <a href="{{ route('contracts.edit', $contract) }}"
                   class="px-4 py-2 text-sm font-medium text-indigo-600 border border-indigo-600 rounded-md hover:bg-indigo-50">
                    Edit
                </a>
                <button wire:click="$dispatch('open-invite-external-signer', { contractId: {{ $contract->id }} })"
                        class="px-4 py-2 text-sm font-medium text-indigo-600 border border-indigo-600 rounded-md hover:bg-indigo-50">
                    Invite External Signer
                </button>
            @endcan
        </div>
    </div>

    <div class="flex-1 border border-gray-300 rounded-lg overflow-hidden bg-gray-50">
        @if($contract->file_path)
            <iframe src="{{ Storage::url($contract->file_path) }}" class="w-full h-full" style="min-height:600px;"></iframe>
        @else
            <div class="flex items-center justify-center h-64 text-gray-400 text-sm">No document uploaded.</div>
        @endif
    </div>

    {{-- Signatures strip --}}
    @if($contract->signatures->count())
        <div class="bg-white shadow rounded-lg p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Signatures ({{ $contract->signatures->count() }})</h3>
            <div class="flex flex-wrap gap-4">
                @foreach($contract->signatures as $sig)
                    <div class="text-center">
                        <img src="{{ Storage::url($sig->signature_image_path) }}" alt="Signature" class="h-12 border border-gray-200 rounded">
                        <p class="text-xs text-gray-500 mt-1">
                            {{ $sig->signerName() }}
                            @unless($sig->user_id)
                                <span class="text-gray-400">(external)</span>
                            @endunless
                        </p>
                        <p class="text-xs text-gray-400">{{ $sig->signed_at->format('M d, Y') }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Pending external signers --}}
    @if($contract->externalSigners->where('status', '!=', 'signed')->count())
        <div class="bg-white shadow rounded-lg p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Pending External Signers</h3>
            <ul class="space-y-1">
                @foreach($contract->externalSigners->where('status', '!=', 'signed') as $signer)
                    <li class="text-xs text-gray-600 flex items-center justify-between">
                        <span>{{ $signer->name }} ({{ $signer->email }})</span>
                        <span class="text-gray-400">
                            {{ $signer->isExpired() ? 'Expired' : ucfirst($signer->status) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <livewire:contracts.signature-pad :contractId="$contract->id" />
    <livewire:contracts.version-history :contractId="$contract->id" />
    @can('update', $contract)
        <livewire:contracts.invite-external-signer :contractId="$contract->id" />
    @endcan
</div>
