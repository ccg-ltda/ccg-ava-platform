<?php

namespace App\Http\Controllers;

use App\Services\WorkspaceSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The logo of the active Workspace, for any signed-in member (the sidebar shows it to every role). */
class WorkspaceLogoController extends Controller
{
    public function __invoke(Request $request, WorkspaceSettingsService $settings): Response|StreamedResponse
    {
        return $settings->logoResponse($request->attributes->get('workspace'), $request);
    }
}
