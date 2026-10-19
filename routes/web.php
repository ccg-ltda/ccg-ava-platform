<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\BookableResourceController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ChannelAppearanceController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\ConversationActionController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoConversationController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\ModuleCategoryController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfessionalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SavedFilterController;
use App\Http\Controllers\ScheduleExceptionController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SupportAccessController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceLogoController;
use App\Http\Controllers\WorkspaceMemberController;
use App\Http\Controllers\WorkspacePlanController;
use App\Http\Controllers\WorkspaceSearchController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/pre-login');

// Public legal texts (no session): linked from the access screens and the account-recovery forms.
Route::get('/legal', [LegalController::class, 'index'])->name('legal.index');
Route::get('/legal/{slug}', [LegalController::class, 'show'])->where('slug', '[a-z-]+')->name('legal.show');

// The open booking page of a Workspace (no session): the key in the URL is the only thing the visitor chooses. Its data comes from /api/booking/{key}/....
Route::get('/book/{key}', [PublicBookingController::class, 'page'])->where('key', '[A-Za-z0-9]{32}')->middleware('throttle:booking-config')->name('booking.public');

// Everything below requires an authenticated user AND a valid Workspace context
// (re-validated on every request by EnsureWorkspaceContext).
Route::middleware(['auth', 'verified', 'workspace'])->group(function () {
    // Dashboard: the executive and operational summary and the home page after login. Reportes: the detailed analytics.
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['workspace.module:dashboard', 'workspace.permission:view-dashboard'])->name('dashboard');
    Route::get('/reports', [ReportController::class, 'index'])->middleware(['workspace.module:reports', 'workspace.permission:view-dashboard'])->name('reports.index');
});

Route::middleware(['auth', 'workspace'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/communications', [LegalController::class, 'updateCommunications'])->name('profile.communications');

    Route::get('/settings', [SettingsController::class, 'index'])
        ->middleware(['workspace.module:settings', 'workspace.permission:manage-settings'])->name('settings.index');
    Route::post('/settings', [SettingsController::class, 'update'])
        ->middleware(['workspace.module:settings', 'workspace.permission:manage-settings'])->name('settings.update');
    Route::get('/workspace/logo', WorkspaceLogoController::class)->name('workspace.logo');
    // Text search behind the Workspace selector (what it may find depends on the purpose; see the controller).
    Route::get('/workspaces/search', WorkspaceSearchController::class)->middleware('throttle:60,1')->name('workspaces.search');
    // Integrations belong to the active Workspace; `manage-settings` governs them like the rest of Configuraciones.
    Route::middleware(['workspace.module:integrations', 'workspace.permission:manage-settings'])->prefix('integrations')->name('integrations.')->group(function () {
        Route::get('/', [IntegrationController::class, 'index'])->name('index');
        Route::post('/', [IntegrationController::class, 'store'])->name('store');
        Route::put('/n8n', [IntegrationController::class, 'updateN8n'])->name('n8n.update');
        Route::delete('/n8n', [IntegrationController::class, 'disconnectN8n'])->name('n8n.destroy');
        Route::put('/providers/{provider}', [IntegrationController::class, 'updateProvider'])->where('provider', '[a-z_]+')->middleware('workspace.module:calendar_providers')->name('providers.update');
        Route::put('/channels/{channel}', [IntegrationController::class, 'updateChannel'])->where('channel', '[a-z_]+')->name('channels.update');
        Route::put('/{integration}', [IntegrationController::class, 'update'])->whereNumber('integration')->name('update');
        Route::post('/{integration}/activate', [IntegrationController::class, 'activate'])->whereNumber('integration')->name('activate');
        Route::post('/{integration}/deactivate', [IntegrationController::class, 'deactivate'])->whereNumber('integration')->name('deactivate');
        // Each test is a real outbound request: keep it from being used to hammer a third party.
        Route::post('/{integration}/test', [IntegrationController::class, 'test'])->whereNumber('integration')->middleware('throttle:20,1')->name('test');
    });

    // Conversations hold what the contacts wrote: reading them is `view-conversations`; attending them (take, write, give
    // back to the AI, resolve) is `reply-conversations`; assigning them to another agent is `manage-conversations`.
    Route::middleware(['workspace.module:conversations', 'workspace.permission:view-conversations'])->prefix('conversations')->name('conversations.')->group(function () {
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
    Route::middleware('workspace.module:assistants')->prefix('chatbots')->name('chatbots.')->group(function () {
        Route::middleware('workspace.permission:view-chatbots')->group(function () {
            Route::get('/', [ChatbotController::class, 'index'])->name('index');
            Route::get('/{chatbot}', [ChatbotController::class, 'show'])->whereNumber('chatbot')->name('show');
            Route::get('/{chatbot}/avatar', [ChatbotController::class, 'avatar'])->whereNumber('chatbot')->name('avatar');
        });
        // The conversations of a channel hold what the contacts wrote: their own permission.
        Route::get('/{chatbot}/channels/{channel}/conversations', [ConversationController::class, 'index'])
            ->whereNumber('chatbot')->where('channel', '[a-z_]+')->middleware(['workspace.module:conversations', 'workspace.permission:view-conversations'])->name('conversations');
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

    // Catálogo de productos and Pedidos (E-commerce and Delivery). Reading is `view-catalog` / `view-orders`; changing the catalog is `manage-catalog`; placing,
    // editing and cancelling an order is `manage-orders`; moving it along the flow is `process-orders` (cancelling is re-checked inside as `manage-orders`).
    Route::middleware(['workspace.module:product_catalog', 'workspace.permission:view-catalog'])->prefix('catalog')->name('catalog.')->group(function () {
        Route::get('/', [ProductController::class, 'index'])->name('index');
        Route::middleware('workspace.permission:manage-catalog')->group(function () {
            Route::post('/', [ProductController::class, 'store'])->name('store');
            Route::put('/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('update');
            Route::post('/{product}/activate', [ProductController::class, 'activate'])->whereNumber('product')->name('activate');
            Route::post('/{product}/deactivate', [ProductController::class, 'deactivate'])->whereNumber('product')->name('deactivate');
        });
    });

    Route::middleware(['workspace.module:orders', 'workspace.permission:view-orders'])->prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('show');
        Route::middleware('workspace.permission:manage-orders')->group(function () {
            Route::post('/', [OrderController::class, 'store'])->middleware('throttle:60,1')->name('store');
            Route::put('/{order}', [OrderController::class, 'update'])->whereNumber('order')->name('update');
        });
        Route::post('/{order}/status', [OrderController::class, 'transition'])->whereNumber('order')->middleware('workspace.permission:process-orders,manage-orders')->name('transition');
    });

    // Servicios y Reservas. Reading is `view-bookings`; configuring the catalog, professionals, schedules and rules is `manage-services`;
    // operating appointments is `manage-bookings`. Each page also needs its own module in the plan of the Workspace.
    Route::middleware(['workspace.module:service_catalog', 'workspace.permission:view-bookings'])->prefix('services')->name('services.')->group(function () {
        Route::get('/', [ServiceController::class, 'index'])->name('index');
        Route::middleware('workspace.permission:manage-services')->group(function () {
            Route::post('/', [ServiceController::class, 'store'])->name('store');
            Route::put('/{service}', [ServiceController::class, 'update'])->whereNumber('service')->name('update');
            Route::post('/{service}/activate', [ServiceController::class, 'activate'])->whereNumber('service')->name('activate');
            Route::post('/{service}/deactivate', [ServiceController::class, 'deactivate'])->whereNumber('service')->name('deactivate');
        });
    });

    // Sedes: where the business attends. Same permissions as the catalog; the module is `locations`.
    Route::middleware(['workspace.module:locations', 'workspace.permission:view-bookings'])->prefix('locations')->name('locations.')->group(function () {
        Route::get('/', [LocationController::class, 'index'])->name('index');
        Route::middleware('workspace.permission:manage-services')->group(function () {
            Route::post('/', [LocationController::class, 'store'])->name('store');
            Route::put('/{location}', [LocationController::class, 'update'])->whereNumber('location')->name('update');
            Route::post('/{location}/activate', [LocationController::class, 'activate'])->whereNumber('location')->name('activate');
            Route::post('/{location}/deactivate', [LocationController::class, 'deactivate'])->whereNumber('location')->name('deactivate');
        });
    });

    Route::middleware(['workspace.module:professionals', 'workspace.permission:view-bookings'])->prefix('professionals')->name('professionals.')->group(function () {
        Route::get('/', [ProfessionalController::class, 'index'])->name('index');
        Route::middleware('workspace.permission:manage-services')->group(function () {
            Route::post('/', [ProfessionalController::class, 'store'])->name('store');
            Route::put('/{professional}', [ProfessionalController::class, 'update'])->whereNumber('professional')->name('update');
            Route::post('/{professional}/activate', [ProfessionalController::class, 'activate'])->whereNumber('professional')->name('activate');
            Route::post('/{professional}/deactivate', [ProfessionalController::class, 'deactivate'])->whereNumber('professional')->name('deactivate');
            Route::post('/resources', [BookableResourceController::class, 'store'])->name('resources.store');
            Route::put('/resources/{resource}', [BookableResourceController::class, 'update'])->whereNumber('resource')->name('resources.update');
            Route::post('/resources/{resource}/activate', [BookableResourceController::class, 'activate'])->whereNumber('resource')->name('resources.activate');
            Route::post('/resources/{resource}/deactivate', [BookableResourceController::class, 'deactivate'])->whereNumber('resource')->name('resources.deactivate');
            Route::post('/exceptions', [ScheduleExceptionController::class, 'store'])->name('exceptions.store');
            Route::put('/exceptions/{exception}', [ScheduleExceptionController::class, 'update'])->whereNumber('exception')->name('exceptions.update');
            Route::delete('/exceptions/{exception}', [ScheduleExceptionController::class, 'destroy'])->whereNumber('exception')->name('exceptions.destroy');
        });
    });

    Route::middleware(['workspace.module:bookings', 'workspace.permission:view-bookings'])->prefix('bookings')->name('bookings.')->group(function () {
        Route::get('/', [BookingController::class, 'index'])->name('index');
        Route::get('/availability', [BookingController::class, 'availability'])->middleware('throttle:120,1')->name('availability');
        Route::put('/settings', [BookingController::class, 'updateSettings'])->middleware('workspace.permission:manage-services')->name('settings.update');
        Route::put('/settings/notifications', [BookingController::class, 'updateNotifications'])->middleware('workspace.permission:manage-services')->name('settings.notifications');
        Route::post('/settings/public-key', [BookingController::class, 'rotateKey'])->middleware(['workspace.permission:manage-services', 'throttle:10,1'])->name('settings.rotate-key');
        Route::middleware('workspace.permission:manage-bookings')->group(function () {
            Route::post('/', [BookingController::class, 'store'])->middleware('throttle:60,1')->name('store');
            Route::post('/{appointment}/status', [BookingController::class, 'status'])->whereNumber('appointment')->name('status');
            Route::post('/{appointment}/reschedule', [BookingController::class, 'reschedule'])->whereNumber('appointment')->name('reschedule');
            Route::post('/{appointment}/cancel', [BookingController::class, 'cancel'])->whereNumber('appointment')->name('cancel');
            Route::post('/{appointment}/refresh', [BookingController::class, 'refresh'])->whereNumber('appointment')->name('refresh');
            Route::post('/{appointment}/customer-confirmation', [BookingController::class, 'customerConfirmation'])->whereNumber('appointment')->name('customer-confirmation');
        });
    });

    // Clientes: the customer records of the Workspace. Reading is `view-customers`; creating and editing is `manage-customers`.
    Route::middleware(['workspace.module:customers', 'workspace.permission:view-customers'])->prefix('customers')->name('customers.')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::get('/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('show');
        Route::middleware('workspace.permission:manage-customers')->group(function () {
            Route::post('/', [CustomerController::class, 'store'])->middleware('throttle:60,1')->name('store');
            Route::put('/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('update');
            Route::post('/{customer}/activate', [CustomerController::class, 'activate'])->whereNumber('customer')->name('activate');
            Route::post('/{customer}/deactivate', [CustomerController::class, 'deactivate'])->whereNumber('customer')->name('deactivate');
            Route::post('/{customer}/anonymize', [CustomerController::class, 'anonymize'])->whereNumber('customer')->middleware('throttle:20,1')->name('anonymize');
        });
    });

    // Campañas: templates and campaigns. Reading is `view-campaigns`; everything that changes them is `manage-campaigns`. Nothing here sends a message.
    Route::middleware(['workspace.module:campaigns', 'workspace.permission:view-campaigns'])->prefix('campaigns')->name('campaigns.')->group(function () {
        Route::get('/', [CampaignController::class, 'index'])->name('index');
        Route::get('/{campaign}', [CampaignController::class, 'show'])->whereNumber('campaign')->name('show');
        Route::middleware('workspace.permission:manage-campaigns')->group(function () {
            Route::post('/', [CampaignController::class, 'store'])->name('store');
            Route::put('/{campaign}', [CampaignController::class, 'update'])->whereNumber('campaign')->name('update');
            Route::post('/{campaign}/prepare', [CampaignController::class, 'prepare'])->whereNumber('campaign')->middleware('throttle:20,1')->name('prepare');
            Route::post('/{campaign}/simulate', [CampaignController::class, 'simulate'])->whereNumber('campaign')->middleware('throttle:20,1')->name('simulate');
            Route::post('/{campaign}/cancel', [CampaignController::class, 'cancel'])->whereNumber('campaign')->name('cancel');
            Route::post('/templates', [MessageTemplateController::class, 'store'])->name('templates.store');
            Route::put('/templates/{template}', [MessageTemplateController::class, 'update'])->whereNumber('template')->name('templates.update');
            Route::post('/templates/{template}/activate', [MessageTemplateController::class, 'activate'])->whereNumber('template')->name('templates.activate');
            Route::post('/templates/{template}/deactivate', [MessageTemplateController::class, 'deactivate'])->whereNumber('template')->name('templates.deactivate');
        });
    });

    // Auditoría reads the change history of the active Workspace; `manage-settings` governs it like the other admin pages.
    Route::middleware(['workspace.module:audit', 'workspace.permission:manage-settings'])->prefix('audit')->name('audit.')->group(function () {
        Route::get('/', [AuditController::class, 'index'])->name('index');
        Route::get('/export', [AuditController::class, 'export'])->middleware(['workspace.permission:export-audit', 'throttle:10,1'])->name('export');
    });

    // Search filters a user saves per module. The module decides the permission; the filters are always the user's own in
    // the active Workspace (see SavedFilters), so there is no way to name another user's or Workspace's filter.
    Route::prefix('saved-filters/{scope}')->where(['scope' => '[a-z_]+'])->name('saved-filters.')->group(function () {
        Route::post('/', [SavedFilterController::class, 'store'])->middleware('throttle:30,1')->name('store');
        Route::put('/{filter}', [SavedFilterController::class, 'update'])->whereNumber('filter')->middleware('throttle:30,1')->name('update');
        Route::delete('/{filter}', [SavedFilterController::class, 'destroy'])->whereNumber('filter')->name('destroy');
    });

    // Usuarios y roles: `manage-users` opens the module; each action also needs its own permission (create, edit, assign roles,
    // activate / deactivate), so a role can be given exactly the ones it needs.
    Route::middleware(['workspace.module:users_roles', 'workspace.permission:manage-users'])->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->middleware('workspace.permission:create-users')->name('users.store');
        // Editing the account needs `edit-users`; changing the role or the Workspace needs `assign-roles` (UserController checks which one applies).
        Route::put('/users/{user}', [UserController::class, 'update'])->middleware('workspace.permission:edit-users,assign-roles')->name('users.update');
        // Users are never deleted: they are deactivated (and can be reactivated) so history and ID stay.
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->middleware('workspace.permission:deactivate-users')->name('users.deactivate');
        Route::post('/users/{user}/activate', [UserController::class, 'activate'])->middleware('workspace.permission:deactivate-users')->name('users.activate');
    });

    // Workspaces are listed inside /users. WorkspaceAdministration decides who may act on which one.
    Route::middleware(['workspace.module:users_roles', 'workspace.permission:manage-users'])->group(function () {
        Route::post('/workspaces', [WorkspaceController::class, 'store'])->middleware('can:manage-workspaces')->name('workspaces.store');
        Route::put('/workspaces/{workspace}', [WorkspaceController::class, 'update'])->middleware('can:manage-workspaces')->name('workspaces.update');
        Route::post('/workspaces/{workspace}/deactivate', [WorkspaceController::class, 'deactivate'])->middleware('can:manage-workspaces')->name('workspaces.deactivate');
        Route::post('/workspaces/{workspace}/activate', [WorkspaceController::class, 'activate'])->middleware('can:manage-workspaces')->name('workspaces.activate');
        Route::post('/organizations', [OrganizationController::class, 'store'])->middleware('can:manage-workspaces')->name('organizations.store');
        Route::put('/organizations/{organization}', [OrganizationController::class, 'update'])->middleware('can:manage-workspaces')->name('organizations.update');
        Route::post('/organizations/{organization}/deactivate', [OrganizationController::class, 'deactivate'])->middleware('can:manage-workspaces')->name('organizations.deactivate');
        Route::post('/organizations/{organization}/activate', [OrganizationController::class, 'activate'])->middleware('can:manage-workspaces')->name('organizations.activate');
        Route::post('/workspaces/{workspace}/members', [WorkspaceMemberController::class, 'store'])->middleware(['can:manage-workspaces', 'workspace.permission:assign-roles'])->name('workspaces.members.store');
        Route::put('/workspaces/{workspace}/members/{user}', [WorkspaceMemberController::class, 'update'])->middleware('workspace.permission:assign-roles')->name('workspaces.members.update');
        Route::delete('/workspaces/{workspace}/members/{user}', [WorkspaceMemberController::class, 'destroy'])->middleware('workspace.permission:assign-roles')->name('workspaces.members.destroy');
    });

    // Planes and the module catalog: CCG's commercial administration. Only superusers working inside the administrative
    // Workspace whose role grants the permission of the area; a client Workspace can neither read nor change them, whatever
    // its role or plan. `manage-plans` = plans and their assignment; `manage-modules` = modules and categories.
    Route::get('/plans', [PlanController::class, 'index'])->middleware('workspace.platform:manage-plans,manage-modules')->name('plans.index');

    Route::middleware('workspace.platform:manage-plans')->group(function () {
        Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
        Route::get('/plans/{plan}', [PlanController::class, 'show'])->whereNumber('plan')->name('plans.show');
        Route::put('/plans/{plan}', [PlanController::class, 'update'])->whereNumber('plan')->name('plans.update');
        Route::put('/plans/{plan}/modules', [PlanController::class, 'updateModules'])->whereNumber('plan')->name('plans.modules.update');
        Route::post('/plans/{plan}/activate', [PlanController::class, 'activate'])->whereNumber('plan')->name('plans.activate');
        Route::post('/plans/{plan}/deactivate', [PlanController::class, 'deactivate'])->whereNumber('plan')->name('plans.deactivate');
        Route::put('/workspaces/{workspace}/plan', [WorkspacePlanController::class, 'update'])->whereNumber('workspace')->name('workspaces.plan.update');
    });

    Route::middleware('workspace.platform:manage-modules')->group(function () {
        Route::post('/modules', [ModuleController::class, 'store'])->name('modules.store');
        Route::put('/modules/{module}', [ModuleController::class, 'update'])->whereNumber('module')->name('modules.update');
        Route::post('/modules/{module}/activate', [ModuleController::class, 'activate'])->whereNumber('module')->name('modules.activate');
        Route::post('/modules/{module}/deactivate', [ModuleController::class, 'deactivate'])->whereNumber('module')->name('modules.deactivate');

        Route::post('/module-categories', [ModuleCategoryController::class, 'store'])->name('module-categories.store');
        Route::put('/module-categories/{category}', [ModuleCategoryController::class, 'update'])->whereNumber('category')->name('module-categories.update');
        Route::post('/module-categories/{category}/activate', [ModuleCategoryController::class, 'activate'])->whereNumber('category')->name('module-categories.activate');
        Route::post('/module-categories/{category}/deactivate', [ModuleCategoryController::class, 'deactivate'])->whereNumber('category')->name('module-categories.deactivate');
    });

    // Temporary, justified access of a platform administrator to the private data of another Workspace. Being superuser is not enough:
    // it needs `access-private-data` and to work inside the administrative Workspace (App\Privacy\SupportAccess).
    Route::middleware('workspace.permission:access-private-data')->prefix('support-access')->name('support-access.')->group(function () {
        Route::post('/', [SupportAccessController::class, 'store'])->middleware('throttle:20,1')->name('store');
        Route::delete('/{grant}', [SupportAccessController::class, 'destroy'])->whereNumber('grant')->name('destroy');
    });

    // The role catalog is shared by every Workspace: superusers only.
    Route::middleware(['workspace.module:users_roles', 'workspace.permission:manage-users', 'can:manage-roles'])->group(function () {
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    });
});

require __DIR__.'/auth.php';
