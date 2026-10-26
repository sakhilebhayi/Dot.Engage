<div class="flex flex-col h-full">
    <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
        <div>
            <h3 class="text-sm font-semibold text-gray-900">{{ $contract->title }}</h3>
            <p class="text-xs text-gray-500">{{ ucfirst($contract->status) }}</p>
        </div>
        @can('sign', $contract)
            <button wire:click="$dispatch('open-signature-pad', { contractId: {{ $contract->id }} })"
                    class="px-3 py-1.5 text-xs font-medium text-white bg-green-600 rounded-md hover:bg-green-700">
                Sign Now
            </button>
        @endcan
    </div>
    <div class="flex-1 overflow-hidden">
        @if($contract->file_path)
            <iframe title="{{ __('Contract document') }}" src="{{ Storage::url($contract->file_path) }}" class="w-full h-full"></iframe>
        @else
            <div class="flex items-center justify-center h-full text-gray-400 text-sm">No document available.</div>
        @endif
    </div>
    <livewire:video.in-call-signature-pad :sessionId="$sessionId" :contractId="$contractId" />
</div>
