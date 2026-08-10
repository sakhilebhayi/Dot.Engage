<?php

namespace App\Livewire\Contracts;

use App\Jobs\ProcessContractUpload;
use App\Models\Contract;
use App\Models\ContractTemplate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class ContractWizard extends Component
{
    use WithFileUploads;

    public ?int $contractId = null;

    /**
     * The template this contract is being created from, if any. Its file
     * (when present) is copied in at save time unless the user uploads
     * their own in step 2, and its signing window is applied to expiresAt.
     */
    public ?int $templateId = null;

    public int $step = 1;

    public string $title = '';

    public string $description = '';

    public ?string $expiresAt = null;

    public $file = null;

    public function mount(?int $contractId = null, ?int $templateId = null): void
    {
        if ($contractId) {
            $contract = Contract::findOrFail($contractId);
            $this->authorize('update', $contract);
            $this->contractId = $contractId;
            $this->title = $contract->title;
            $this->description = $contract->description ?? '';
            $this->expiresAt = $contract->expires_at?->format('Y-m-d');

            return;
        }

        $templateId ??= request()->integer('template') ?: null;

        if ($templateId) {
            $template = ContractTemplate::findOrFail($templateId);
            $this->authorize('view', $template);
            $this->templateId = $templateId;
            $this->title = $template->title;
            $this->description = $template->description ?? '';
            $this->expiresAt = $template->expires_in_days
                ? now()->addDays($template->expires_in_days)->format('Y-m-d')
                : null;
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

        // No file uploaded but this contract is being created from a
        // template that has one -- copy it in as the starting document
        // rather than leaving the contract fileless.
        if (! $filePath && ! $this->contractId && $this->templateId) {
            $template = ContractTemplate::findOrFail($this->templateId);
            if ($template->file_path && Storage::disk('contracts')->exists($template->file_path)) {
                $filePath = Str::uuid().'.pdf';
                Storage::disk('contracts')->copy($template->file_path, $filePath);
            }
        }

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
