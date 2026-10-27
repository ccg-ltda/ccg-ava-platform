<?php

use App\Http\Controllers\AgentBookingController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentKnowledgeController;
use App\Http\Controllers\AgentMessageController;
use App\Http\Controllers\AgentOrderController;
use App\Http\Controllers\PublicBookingController;
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
    // The webhook of ONE Workspace's number: the key is the public, non-secret part generated with its WhatsApp integration (the secrets stay in the integration).
    Route::get('/whatsapp/{key}', [WhatsAppWebhookController::class, 'verifyIntegration'])->where('key', '[a-f0-9]{48}')->middleware('throttle:whatsapp-integration')->name('whatsapp.integration.verify');
    Route::post('/whatsapp/{key}', [WhatsAppWebhookController::class, 'receiveIntegration'])->where('key', '[a-f0-9]{48}')->middleware('throttle:whatsapp-integration')->name('whatsapp.integration.receive');
});

// The token names a chatbot, so its Workspace's plan must include Asistentes; reporting or authorizing conversation traffic
// also needs Conversaciones. (The public widget and the Meta webhook check the plan where they resolve the chatbot.)
Route::middleware(['throttle:agent', 'agent', 'workspace.module:assistants'])->prefix('agent')->name('agent.')->group(function () {
    Route::get('/config', [AgentController::class, 'config'])->name('config');
    // Search in the knowledge base of THIS assistant (traced like the other tools; it needs only the module Asistentes).
    Route::get('/knowledge/search', [AgentKnowledgeController::class, 'search'])->middleware('agent.trace')->name('knowledge.search');
    Route::middleware('workspace.module:conversations')->group(function () {
        Route::post('/messages', [AgentMessageController::class, 'store'])->name('messages.store');
        Route::post('/messages/status', [AgentMessageController::class, 'status'])->name('messages.status');
        Route::post('/conversations/authorize', [AgentMessageController::class, 'authorizeReply'])->name('conversations.authorize');
        Route::post('/conversations/handoff', [AgentMessageController::class, 'handoff'])->name('conversations.handoff');

        // Booking tools of Ava Chat: they also need the plan of the Workspace to include Servicios y Reservas (every module the booking page needs).
        Route::middleware(['workspace.module:service_catalog', 'workspace.module:professionals', 'workspace.module:bookings', 'agent.trace'])->prefix('booking')->name('booking.')->group(function () {
            Route::get('/services', [AgentBookingController::class, 'services'])->name('services');
            Route::get('/professionals', [AgentBookingController::class, 'professionals'])->name('professionals');
            Route::get('/locations', [AgentBookingController::class, 'locations'])->name('locations');
            Route::get('/availability', [AgentBookingController::class, 'availability'])->name('availability');
            Route::get('/appointments', [AgentBookingController::class, 'index'])->name('appointments');
            Route::post('/appointments', [AgentBookingController::class, 'book'])->name('book');
            Route::post('/appointments/{appointment}/cancel', [AgentBookingController::class, 'cancel'])->whereNumber('appointment')->name('cancel');
            Route::post('/appointments/{appointment}/confirm', [AgentBookingController::class, 'confirm'])->whereNumber('appointment')->name('confirm');
            Route::post('/appointments/{appointment}/reschedule', [AgentBookingController::class, 'reschedule'])->whereNumber('appointment')->name('reschedule');
        });

        // Order tools of Ava Chat (Delivery and E-commerce): the plan must include the catalog and Pedidos. They read the catalog, place an order and read the
        // orders of THIS conversation; the assistant never moves an order along its flow or cancels it.
        Route::middleware(['workspace.module:product_catalog', 'workspace.module:orders', 'agent.trace'])->prefix('orders')->name('orders.')->group(function () {
            Route::get('/options', [AgentOrderController::class, 'options'])->name('options');
            Route::get('/products', [AgentOrderController::class, 'products'])->name('products');
            Route::get('/', [AgentOrderController::class, 'index'])->name('index');
            Route::post('/', [AgentOrderController::class, 'store'])->name('store');
        });
    });
});

// The open booking page of a Workspace (Servicios y Reservas): no session, no CSRF. The key names the Workspace; creating, cancelling and
// moving a booking are rate limited per visitor and key, and a booking is managed only with the link returned when it was made.
Route::prefix('booking/{key}')->where(['key' => '[A-Za-z0-9]{32}'])->name('public-booking.')->group(function () {
    Route::get('/config', [PublicBookingController::class, 'config'])->middleware('throttle:booking-config')->name('config');
    Route::get('/services', [PublicBookingController::class, 'services'])->middleware('throttle:booking-config')->name('services');
    Route::get('/professionals', [PublicBookingController::class, 'professionals'])->middleware('throttle:booking-config')->name('professionals');
    Route::get('/availability', [PublicBookingController::class, 'availability'])->middleware('throttle:booking-availability')->name('availability');
    Route::post('/appointments', [PublicBookingController::class, 'book'])->middleware('throttle:booking-write')->name('book');
    Route::get('/appointments/{token}', [PublicBookingController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:booking-config')->name('show');
    Route::post('/appointments/{token}/confirm', [PublicBookingController::class, 'confirm'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:booking-write')->name('confirm');
    Route::post('/appointments/{token}/cancel', [PublicBookingController::class, 'cancel'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:booking-write')->name('cancel');
    Route::post('/appointments/{token}/reschedule', [PublicBookingController::class, 'reschedule'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:booking-write')->name('reschedule');
});

Route::prefix('widget/{key}')->where(['key' => '[A-Za-z0-9]{32}'])->name('widget.')->group(function () {
    Route::get('/config', [WidgetController::class, 'config'])->middleware('throttle:widget-config')->name('config');
    Route::get('/avatar', [WidgetController::class, 'avatar'])->middleware('throttle:widget-config')->name('avatar');
    Route::get('/messages', [WidgetController::class, 'poll'])->middleware('throttle:widget-poll')->name('poll');
    Route::post('/messages', [WidgetController::class, 'message'])->middleware('throttle:widget-messages')->name('message');
});
