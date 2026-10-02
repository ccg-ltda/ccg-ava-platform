<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/pre-login');

// Everything below requires an authenticated user AND a valid Workspace context
// (re-validated on every request by EnsureWorkspaceContext).
Route::middleware(['auth', 'verified', 'workspace'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

Route::middleware(['auth', 'workspace'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/settings', [SettingsController::class, 'index'])
        ->middleware('workspace.permission:manage-settings')->name('settings.index');
    Route::get('/integrations', [IntegrationController::class, 'index'])
        ->middleware('workspace.permission:manage-settings')->name('integrations.index');

    Route::get('/users', [UserController::class, 'index'])
        ->middleware('workspace.permission:manage-users')->name('users.index');
    Route::post('/users', [UserController::class, 'store'])
        ->middleware('workspace.permission:manage-users')->name('users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])
        ->middleware('workspace.permission:manage-users')->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])
        ->middleware('workspace.permission:manage-users')->name('users.destroy');

    // The role catalog is shared by every Workspace: superusers only.
    Route::middleware(['workspace.permission:manage-users', 'can:manage-roles'])->group(function () {
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });
});

require __DIR__.'/auth.php';
