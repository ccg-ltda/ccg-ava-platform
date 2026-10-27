<?php

namespace App\Http\Controllers;

use App\Services\HelpCenter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves the PDF manuals of the «Ayuda» panel to the members of a Workspace whose plan includes the manual's module. */
class HelpController extends Controller
{
    public function manual(Request $request, HelpCenter $help, string $key): BinaryFileResponse
    {
        $manual = $help->find($request, $key);
        abort_if($manual === null, 404);

        return response()->file($help->path($manual), ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="'.basename($manual['file']).'"', 'Cache-Control' => 'private, no-cache']);
    }
}
