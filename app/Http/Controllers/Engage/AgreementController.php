<?php

namespace App\Http\Controllers\Engage;

use App\Http\Controllers\Controller;
use App\Models\Engagement;
use Illuminate\Http\Request;

class AgreementController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->currentTeam, 403);

        return view('agreements.index', [
            'agreements' => Engagement::where('team_id', $request->user()->currentTeam->id)->where('state', 'completed')->with('parties')->orderByDesc('completed_at')->paginate(25),
        ]);
    }
}
