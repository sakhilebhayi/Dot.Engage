<?php

namespace App\Livewire\Contracts;

use App\Jobs\ProcessContractUpload;
use App\Models\Contract;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;

class ContractWizard extends Component
{
    use WithFileUploads;

    public ?int $contractId = null;

    public int $step = 1;

    public string $title = '';

    public string $description = '';

    public ?string $expiresAt = null;

    public $file = null;

    public function mount(?int $contractId = null): void
    {
        if ($contractId) {
            $contract = Contract::findOrFail($contractId);
            $this->authorize('update', $contract);
            $this->contractId = $contractId;
            $this->title = $contract->title;
            $this->description = $contract->description ?? '';
            $this->expiresAt = $contract->expires_at?->format('Y-m-d');
        }
    }

    protected function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'expiresAt' => 'nullable|date|after:today',
            'file' => 'nullable|file|mimes:pdf,doc,docx|max:20480',
        ];
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validateOnly('title');
            $this->validateOnly('description');
            $this->validateOnly('expiresAt');
        }
        if ($this->step === 2) {
            $this->validateOnly('file');
        }
        $this->step++;
    }

    public function previousStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function save(): void
    {
        $this->validate();

        // Store file on the private contracts disk.
        $filePath = $this->file ? $this->file->store('/', 'contracts') : null;

        if ($this->contractId) {
            // Update existing contract.
            $contract = Contract::findOrFail($this->contractId);
            $this->authorize('update', $contract);

            $updates = [
                'title' => $this->title,
                'description' => $this->description,
                'expires_at' => $this->expiresAt,
            ];
            if ($filePath) {
                $updates['file_path'] = $filePath;
                $updates['status'] = 'draft';
            }
            $contract->update($updates);

            if ($filePath) {
                ProcessContractUpload::dispatch($contract->fresh());
            }
        } else {
            if (! Auth::user()->currentTeam) {
                $this->addError('title', 'You need a team before creating a contract.');

                return;
            }

            // Create new contract.
            $contract = Contract::create([
                'team_id' => Auth::user()->currentTeam->id,
                'created_by' => Auth::id(),
                'title' => $this->title,
                'description' => $this->description,
                'file_path' => $filePath,
                'expires_at' => $this->expiresAt,
                'status' => 'draft',
            ]);

            if ($filePath) {
                ProcessContractUpload::dispatch($contract);
            }
        }

        $this->redirect(route('contracts.show', $contract));
    }

    public function render()
    {
        return view('livewire.contracts.contract-wizard');
    }
}
