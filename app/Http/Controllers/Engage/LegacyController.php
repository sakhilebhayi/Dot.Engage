<?php

namespace App\Http\Controllers\Engage;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Engagement;
use Illuminate\Http\Request;

/** Old addresses from before engagements: they land somewhere useful or explain what happened. */
class LegacyController extends Controller
{
    public function retiredSigningLink()
    {
        return response()->view('join.retired', [], 410);
    }

    /** /contracts/{id} is now the agreement tab of the engagement the document belongs to (team members only). */
    public function contract(Request $request, int $contract)
    {
        // The team scope is on purpose: another business's document id answers 404, not a redirect.
        $document = Contract::find($contract);
        $engagement = $document?->engagement_id ? Engagement::find($document->engagement_id) : null;
        abort_unless($engagement, 404);

        return redirect()->route('engagements.show', ['engagement' => $engagement, 'doc' => $document->id]);
    }
}
