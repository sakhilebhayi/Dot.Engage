<?php

namespace App\Http\Controllers\Engage;

use App\Http\Controllers\Controller;

class LegalController extends Controller
{
    public function signatures()
    {
        return view('legal.signatures');
    }
}
