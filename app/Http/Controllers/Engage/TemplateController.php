<?php

namespace App\Http\Controllers\Engage;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->currentTeam, 403);

        return view('templates.index', ['templates' => ContractTemplate::where('team_id', $request->user()->currentTeam->id)->latest()->get()]);
    }

    /** Save the working version of a document as a reusable starting point. */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ContractTemplate::class);
        $data = $request->validate(['document' => ['required', 'integer'], 'title' => ['required', 'string', 'max:160']]);

        $document = Contract::findOrFail($data['document']);
        Gate::authorize('view', $document);

        $path = null;
        if ($document->file_path && Storage::disk('contracts')->exists($document->file_path)) {
            $path = 'templates/'.Str::uuid().'.pdf';
            Storage::disk('contracts')->copy($document->file_path, $path);
        }

        ContractTemplate::create([
            'team_id' => $request->user()->currentTeam->id,
            'created_by' => $request->user()->id,
            'title' => $data['title'],
            'description' => $document->description,
            'file_path' => $path,
            'expires_in_days' => $document->expires_at ? max(1, (int) now()->diffInDays($document->expires_at, false)) : null,
        ]);

        return redirect()->route('contracts.templates')->with('status', 'Template saved.');
    }

    public function destroy(Request $request, ContractTemplate $template): RedirectResponse
    {
        Gate::authorize('delete', $template);
        if ($template->file_path) {
            Storage::disk('contracts')->delete($template->file_path);
        }
        $template->delete();

        return redirect()->route('contracts.templates')->with('status', 'Template deleted.');
    }
}
