<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Dashboard', [
            'stats' => [
                'users' => $workspace->users()->count(),
                'roles' => Role::count(),
                'permissions' => Permission::count(),
            ],
        ]);
    }
}
