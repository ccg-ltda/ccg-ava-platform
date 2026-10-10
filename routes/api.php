<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentMessageController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Controllers\WidgetController;
use Illuminate\Support\Facades\Route;

/*
 * The only JSON surfaces of Ava (mounted under /api, no session, no CSRF).
 *  - /api/agent/*   used by an automation (n8n) with the access token of ONE chatbot: it reads the chatbot's
 *                   configuration and reports the messages and delivery states of its channels.
 *  - /api/widget/*  public, used by the web widget; the public key in the URL names ONE chatbot channel.
 *  - /api/webhooks/whatsapp  Meta's webhook (404 until META_APP_SECRET and META_WEBHOOK_VERIFY_TOKEN are set); every event is
 *                   authenticated by its signature, and the Workspace comes from Ava's own records of the number.
 */
Route::prefix('webhooks')->name('webhooks.')->middleware('throttle:600,1')->group(function () {
    Route::get('/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('whatsapp.verify');
    Route::post('/whatsapp', [WhatsAppWebhookController::class, 'receive'])->name('whatsapp.receive');
});

Route::middleware(['throttle:agent', 'agent'])->prefix('agent')->name('agent.')->group(function () {
    Route::get('/config', [AgentController::class, 'config'])->name('config');
    Route::post('/messages', [AgentMessageController::class, 'store'])->name('messages.store');
    Route::post('/messages/status', [AgentMessageController::class, 'status'])->name('messages.status');
    Route::post('/conversations/authorize', [AgentMessageController::class, 'authorizeReply'])->name('conversations.authorize');
    Route::post('/conversations/handoff', [AgentMessageController::class, 'handoff'])->name('conversations.handoff');
});

Route::prefix('widget/{key}')->where(['key' => '[A-Za-z0-9]{32}'])->name('widget.')->group(function () {
    Route::get('/config', [WidgetController::class, 'config'])->middleware('throttle:widget-config')->name('config');
    Route::get('/avatar', [WidgetController::class, 'avatar'])->middleware('throttle:widget-config')->name('avatar');
    Route::get('/messages', [WidgetController::class, 'poll'])->middleware('throttle:widget-poll')->name('poll');
    Route::post('/messages', [WidgetController::class, 'message'])->middleware('throttle:widget-messages')->name('message');
});
