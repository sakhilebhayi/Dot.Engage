<?php

namespace App\Livewire\Contracts;

use App\Models\ContractTemplate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class TemplateList extends Component
{
    use AuthorizesRequests;

    public function delete(int $id): void
    {
        $template = ContractTemplate::findOrFail($id);
        $this->authorize('delete', $template);
        $template->delete();
    }

    public function render()
    {
        // HasTeamScope on ContractTemplate already scopes this to the current team.
        $templates = ContractTemplate::latest()->get();

        return view('livewire.contracts.template-list', compact('templates'));
    }
}
