<?php

namespace App\Http\Controllers\Engage;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MeetingController extends Controller
{
    public function index(Request $request)
    {
        return view('meetings.index', ['meetings' => collect()]);
    }

    public function instant(Request $request)
    {
        return back();
    }

    public function room(Request $request, string $roomKey)
    {
        abort(404);
    }
}
