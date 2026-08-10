<?php

namespace App\Livewire\Contracts;

use App\Models\Contract;
use App\Models\ContractTemplate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

class SaveAsTemplate extends Component
{
    use AuthorizesRequests;

    public int $contractId;

    public bool $show = false;

    public string $title = '';

    #[On('open-save-as-template')]
    public function open(int $contractId): void
    {
        $contract = Contract::findOrFail($contractId);
        $this->authorize('view', $contract);

        $this->contractId = $contractId;
        $this->title = $contract->title.' Template';
        $this->show = true;
    }

    public function save(): void
    {
        $this->validate(['title' => 'required|string|max:255']);

        $contract = Contract::findOrFail($this->contractId);
        $this->authorize('view', $contract);
        $this->authorize('create', ContractTemplate::class);

        $filePath = null;
        if ($contract->file_path && Storage::disk('contracts')->exists($contract->file_path)) {
            $filePath = 'templates/'.Str::uuid().'.pdf';
            Storage::disk('contracts')->copy($contract->file_path, $filePath);
        }

        ContractTemplate::create([
            'team_id' => Auth::user()->currentTeam->id,
            'created_by' => Auth::id(),
            'title' => $this->title,
            'description' => $contract->description,
            'file_path' => $filePath,
            // Preserve the same signing window (in days) the original
            // contract had, rather than its absolute expiry date -- a
            // template is reused far past when that date has passed.
            'expires_in_days' => $contract->expires_at
                ? max(1, now()->diffInDays($contract->expires_at, false))
                : null,
        ]);

        $this->show = false;
        session()->flash('template-saved', 'Template saved. Find it under Contracts → Templates.');
    }

    public function render()
    {
        return view('livewire.contracts.save-as-template');
    }
}
