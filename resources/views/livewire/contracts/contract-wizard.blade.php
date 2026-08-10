<div class="max-w-2xl mx-auto bg-white shadow rounded-lg p-6 space-y-6">
    {{-- Step indicator --}}
    <div class="flex items-center gap-4 mb-4">
        @foreach(['Details', 'Upload', 'Review'] as $i => $label)
            <div class="flex items-center gap-2">
                <span class="w-7 h-7 rounded-full flex items-center justify-center text-sm font-semibold
                    {{ $step === $i + 1 ? 'bg-indigo-600 text-white' : ($step > $i + 1 ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-600') }}">
                    {{ $i + 1 }}
                </span>
                <span class="text-sm font-medium {{ $step === $i + 1 ? 'text-indigo-600' : 'text-gray-500' }}">{{ $label }}</span>
            </div>
            @if($i < 2) <div class="flex-1 h-px bg-gray-200"></div> @endif
        @endforeach
    </div>

    {{-- Step 1: Details --}}
    @if($step === 1)
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Title <span class="text-red-500">*</span></label>
                <input wire:model="title" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Description</label>
                <textarea wire:model="description" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Expiry Date</label>
                <input wire:model="expiresAt" type="date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
            </div>
        </div>
    @endif

    {{-- Step 2: Upload --}}
    @if($step === 2)
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Contract Document (PDF) <span class="text-red-500">*</span></label>
                <input wire:model="file" type="file" accept=".pdf,.doc,.docx"
                       class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                @error('file') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            @if($file)
                <p class="text-sm text-green-600">File selected: {{ $file->getClientOriginalName() }}</p>
            @elseif($templateId)
                <p class="text-sm text-gray-500">Using the template's file. Upload a different one to override it.</p>
            @endif
        </div>
    @endif

    {{-- Step 3: Review --}}
    @if($step === 3)
        <div class="space-y-3">
            <div class="bg-gray-50 rounded-md p-4 text-sm space-y-2">
                <div class="flex justify-between"><span class="font-medium text-gray-600">Title</span><span>{{ $title }}</span></div>
                @if($description)
                    <div class="flex justify-between"><span class="font-medium text-gray-600">Description</span><span>{{ $description }}</span></div>
                @endif
                @if($expiresAt)
                    <div class="flex justify-between"><span class="font-medium text-gray-600">Expires</span><span>{{ $expiresAt }}</span></div>
                @endif
                @if($file)
                    <div class="flex justify-between"><span class="font-medium text-gray-600">File</span><span>{{ $file->getClientOriginalName() }}</span></div>
                @endif
            </div>
        </div>
    @endif

    {{-- Navigation --}}
    <div class="flex justify-between pt-4 border-t border-gray-200">
        @if($step > 1)
            <button wire:click="previousStep" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">Back</button>
        @else
            <div></div>
        @endif
        @if($step < 3)
            <button wire:click="nextStep" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">Next</button>
        @else
            <button wire:click="save" class="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700">Create Contract</button>
        @endif
    </div>
</div>
