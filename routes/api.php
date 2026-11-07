<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Stateless, authenticated with Sanctum. Signing, documents and meetings are web routes that check
| EngagementAccess themselves (outside parties have no account), so only the token user lives here.
*/

Route::middleware('auth:sanctum')->get('/user', fn (Request $request) => $request->user());
