<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ContractPdfController extends Controller
{
    /**
     * Stream a contract PDF from the private disk to the authenticated user.
     *
     * The file is never written to a public path; it streams through this
     * controller so authorization is enforced on every download.
     */
    public function __invoke(Request $request, Contract $contract): Response
    {
        Gate::authorize('view', $contract);

        $path = $contract->file_path;

        abort_unless(
            $path && Storage::disk('contracts')->exists($path),
            404,
            'Contract file not found.'
        );

        $filename = 'contract_'.$contract->id.'_'.str($contract->title)->slug().'.pdf';
        $contents = Storage::disk('contracts')->get($path);

        return response((string) $contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }
}
