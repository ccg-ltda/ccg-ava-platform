<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\ChannelAppearanceController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\ConversationActionController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoConversationController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SavedFilterController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceLogoController;
use App\Http\Controllers\WorkspaceMemberController;
use App\Http\Controllers\WorkspaceSearchController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/pre-login');

// Everything below requires an authenticated user AND a valid Workspace context
// (re-validated on every request by EnsureWorkspaceContext).
Route::middleware(['auth', 'verified', 'workspace'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('workspace.permission:view-dashboard')->name('dashboard');
});

Route::middleware(['auth', 'workspace'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('/settings', [SettingsController::class, 'index'])
        ->middleware('workspace.permission:manage-settings')->name('settings.index');
    Route::post('/settings', [SettingsController::class, 'update'])
        ->middleware('workspace.permission:manage-settings')->name('settings.update');
    Route::get('/workspace/logo', WorkspaceLogoController::class)->name('workspace.logo');
    // Text search behind the Workspace selector (what it may find depends on the purpose; see the controller).
    Route::get('/workspaces/search', WorkspaceSearchController::class)->middleware('throttle:60,1')->name('workspaces.search');
    // Integrations belong to the active Workspace; `manage-settings` governs them like the rest of Configuraciones.
    Route::middleware('workspace.permission:manage-settings')->prefix('integrations')->name('integrations.')->group(function () {
        Route::get('/', [IntegrationController::class, 'index'])->name('index');
        Route::post('/', [IntegrationController::class, 'store'])->name('store');
        Route::put('/n8n', [IntegrationController::class, 'updateN8n'])->name('n8n.update');
        Route::delete('/n8n', [IntegrationController::class, 'disconnectN8n'])->name('n8n.destroy');
        Route::put('/channels/{channel}', [IntegrationController::class, 'updateChannel'])->where('channel', '[a-z_]+')->name('channels.update');
        Route::put('/{integration}', [IntegrationController::class, 'update'])->whereNumber('integration')->name('update');
        Route::post('/{integration}/activate', [IntegrationController::class, 'activate'])->whereNumber('integration')->name('activate');
        Route::post('/{integration}/deactivate', [IntegrationController::class, 'deactivate'])->whereNumber('integration')->name('deactivate');
        // Each test is a real outbound request: keep it from being used to hammer a third party.
        Route::post('/{integration}/test', [IntegrationController::class, 'test'])->whereNumber('integration')->middleware('throttle:20,1')->name('test');
    });

    // Conversations hold what the contacts wrote: reading them is `view-conversations`; attending them (take, write, give
    // back to the AI, resolve) is `reply-conversations`; assigning them to another agent is `manage-conversations`.
    Route::middleware('workspace.permission:view-conversations')->prefix('conversations')->name('conversations.')->group(function () {
        Route::get('/', [ConversationController::class, 'index'])->name('index');
        Route::middleware('workspace.permission:reply-conversations')->group(function () {
            Route::post('/{conversation}/take', [ConversationActionController::class, 'take'])->whereNumber('conversation')->name('take');
            Route::post('/{conversation}/release', [ConversationActionController::class, 'release'])->whereNumber('conversation')->name('release');
            Route::post('/{conversation}/resolve', [ConversationActionController::class, 'resolve'])->whereNumber('conversation')->name('resolve');
            Route::post('/{conversation}/messages', [ConversationActionController::class, 'message'])->whereNumber('conversation')->middleware('throttle:60,1')->name('messages.store');
        });
        // Demo bench (404 outside demo environments): generate / reset / clean scenarios and simulate traffic on DEMO conversations.
        Route::middleware('workspace.permission:manage-conversations')->group(function () {
            Route::post('/demo/{action}', [DemoConversationController::class, 'manage'])->where('action', 'generate|reset|clean')->name('demo');
            Route::post('/{conversation}/simulate/incoming', [DemoConversationController::class, 'incoming'])->whereNumber('conversation')->name('simulate.incoming');
            Route::post('/{conversation}/simulate/ai-reply', [DemoConversationController::class, 'aiReply'])->whereNumber('conversation')->name('simulate.ai-reply');
            Route::post('/{conversation}/simulate/handoff', [DemoConversationController::class, 'handoff'])->whereNumber('conversation')->name('simulate.handoff');
            Route::post('/{conversation}/messages/{message}/simulate-status', [DemoConversationController::class, 'status'])->whereNumber(['conversation', 'message'])->name('simulate.status');
        });
        Route::post('/{conversation}/assign', [ConversationActionController::class, 'assign'])->whereNumber('conversation')->middleware('workspace.permission:manage-conversations')->name('assign');
    });

    // Chatbots belong to the active Workspace. Reading needs `view-chatbots`; changing anything needs `manage-chatbots`.
    Route::prefix('chatbots')->name('chatbots.')->group(function () {
        Route::middleware('workspace.permission:view-chatbots')->group(function () {
            Route::get('/', [ChatbotController::class, 'index'])->name('index');
            Route::get('/{chatbot}', [ChatbotController::class, 'show'])->whereNumber('chatbot')->name('show');
            Route::get('/{chatbot}/avatar', [ChatbotController::class, 'avatar'])->whereNumber('chatbot')->name('avatar');
        });
        // The conversations of a channel hold what the contacts wrote: their own permission.
        Route::get('/{chatbot}/channels/{channel}/conversations', [ConversationController::class, 'index'])
            ->whereNumber('chatbot')->where('channel', '[a-z_]+')->middleware('workspace.permission:view-conversations')->name('conversations');
        Route::middleware('workspace.permission:manage-chatbots')->group(function () {
            Route::post('/', [ChatbotController::class, 'store'])->name('store');
            // POST (not PUT) because the form may carry the avatar file.
            Route::post('/{chatbot}', [ChatbotController::class, 'update'])->whereNumber('chatbot')->name('update');
            Route::post('/{chatbot}/activate', [ChatbotController::class, 'activate'])->whereNumber('chatbot')->name('activate');
            Route::post('/{chatbot}/deactivate', [ChatbotController::class, 'deactivate'])->whereNumber('chatbot')->name('deactivate');
            Route::post('/{chatbot}/agent-token', [ChatbotController::class, 'generateAgentToken'])->whereNumber('chatbot')->middleware('throttle:10,1')->name('agent-token.generate');
            Route::delete('/{chatbot}/agent-token', [ChatbotController::class, 'revokeAgentToken'])->whereNumber('chatbot')->name('agent-token.revoke');
            Route::put('/{chatbot}/channels/{channel}', [ChatbotController::class, 'updateChannel'])->whereNumber('chatbot')->where('channel', '[a-z_]+')->name('channels.update');
            Route::put('/{chatbot}/channels/{channel}/appearance', [ChannelAppearanceController::class, 'update'])->whereNumber('chatbot')->where('channel', '[a-z_]+')->name('channels.appearance.update');
        });
    });

    // Auditoría reads the change history of the active Workspace; `manage-settings` governs it like the other admin pages.
    Route::middleware('workspace.permission:manage-settings')->prefix('audit')->name('audit.')->group(function () {
        Route::get('/', [AuditController::class, 'index'])->name('index');
        Route::get('/export', [AuditController::class, 'export'])->middleware('throttle:10,1')->name('export');
    });

    // Search filters a user saves per module. The module decides the permission; the filters are always the user's own in
    // the active Workspace (see SavedFilters), so there is no way to name another user's or Workspace's filter.
    Route::prefix('saved-filters/{scope}')->where(['scope' => '[a-z_]+'])->name('saved-filters.')->group(function () {
        Route::post('/', [SavedFilterController::class, 'store'])->middleware('throttle:30,1')->name('store');
        Route::put('/{filter}', [SavedFilterController::class, 'update'])->whereNumber('filter')->middleware('throttle:30,1')->name('update');
        Route::delete('/{filter}', [SavedFilterController::class, 'destroy'])->whereNumber('filter')->name('destroy');
    });

    Route::get('/users', [UserController::class, 'index'])
        ->middleware('workspace.permission:manage-users')->name('users.index');
    Route::post('/users', [UserController::class, 'store'])
        ->middleware('workspace.permission:manage-users')->name('users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])
        ->middleware('workspace.permission:manage-users')->name('users.update');
    // Users are never deleted: they are deactivated (and can be reactivated) so history and ID stay.
    Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])
        ->middleware('workspace.permission:manage-users')->name('users.deactivate');
    Route::post('/users/{user}/activate', [UserController::class, 'activate'])
        ->middleware('workspace.permission:manage-users')->name('users.activate');

    // Workspaces are listed inside /users. WorkspaceAdministration decides who may act on which one.
    Route::middleware('workspace.permission:manage-users')->group(function () {
        Route::post('/workspaces', [WorkspaceController::class, 'store'])->middleware('can:manage-workspaces')->name('workspaces.store');
        Route::put('/workspaces/{workspace}', [WorkspaceController::class, 'update'])->middleware('can:manage-workspaces')->name('workspaces.update');
        Route::post('/workspaces/{workspace}/deactivate', [WorkspaceController::class, 'deactivate'])->middleware('can:manage-workspaces')->name('workspaces.deactivate');
        Route::post('/workspaces/{workspace}/activate', [WorkspaceController::class, 'activate'])->middleware('can:manage-workspaces')->name('workspaces.activate');
        Route::post('/organizations', [OrganizationController::class, 'store'])->middleware('can:manage-workspaces')->name('organizations.store');
        Route::put('/organizations/{organization}', [OrganizationController::class, 'update'])->middleware('can:manage-workspaces')->name('organizations.update');
        Route::post('/organizations/{organization}/deactivate', [OrganizationController::class, 'deactivate'])->middleware('can:manage-workspaces')->name('organizations.deactivate');
        Route::post('/organizations/{organization}/activate', [OrganizationController::class, 'activate'])->middleware('can:manage-workspaces')->name('organizations.activate');
        Route::post('/workspaces/{workspace}/members', [WorkspaceMemberController::class, 'store'])->middleware('can:manage-workspaces')->name('workspaces.members.store');
        Route::put('/workspaces/{workspace}/members/{user}', [WorkspaceMemberController::class, 'update'])->name('workspaces.members.update');
        Route::delete('/workspaces/{workspace}/members/{user}', [WorkspaceMemberController::class, 'destroy'])->name('workspaces.members.destroy');
    });

    // The role catalog is shared by every Workspace: superusers only.
    Route::middleware(['workspace.permission:manage-users', 'can:manage-roles'])->group(function () {
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    });
});

require __DIR__.'/auth.php';
