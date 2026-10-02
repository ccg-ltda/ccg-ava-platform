<?php

namespace App\Http\Controllers;

use App\Services\WorkspaceReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Reportes: the home page after login.
     */
    public function index(Request $request, WorkspaceReport $report): Response
    {
        return Inertia::render('Reports/Index', $report->for(
            $request->attributes->get('workspace'),
            $request->attributes->get('workspace_role'),
        ));
    }
}
