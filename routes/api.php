<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentMessageController;
use App\Http\Controllers\WidgetController;
use Illuminate\Support\Facades\Route;

/*
 * The only JSON surfaces of Ava (mounted under /api, no session, no CSRF).
 *  - /api/agent/*   used by an automation (n8n) with the access token of ONE chatbot: it reads the chatbot's
 *                   configuration and reports the messages and delivery states of its channels.
 *  - /api/widget/*  public, used by the web widget; the public key in the URL names ONE chatbot channel.
 */
Route::middleware(['throttle:agent', 'agent'])->prefix('agent')->name('agent.')->group(function () {
    Route::get('/config', [AgentController::class, 'config'])->name('config');
    Route::post('/messages', [AgentMessageController::class, 'store'])->name('messages.store');
    Route::post('/messages/status', [AgentMessageController::class, 'status'])->name('messages.status');
});

Route::prefix('widget/{key}')->where(['key' => '[A-Za-z0-9]{32}'])->name('widget.')->group(function () {
    Route::get('/config', [WidgetController::class, 'config'])->middleware('throttle:widget-config')->name('config');
    Route::get('/avatar', [WidgetController::class, 'avatar'])->middleware('throttle:widget-config')->name('avatar');
    Route::post('/messages', [WidgetController::class, 'message'])->middleware('throttle:widget-messages')->name('message');
});
