<?php

use Illuminate\Support\Facades\Route;
use Softlab180\Bancard\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Bancard Webhook Routes
|--------------------------------------------------------------------------
|
| These routes handle incoming webhooks from Bancard.
| They are registered without CSRF protection since Bancard cannot
| send CSRF tokens with their requests.
|
*/

Route::prefix(config('bancard.webhook.route_prefix', 'webhooks/bancard'))
    ->middleware(config('bancard.webhook.middleware', []))
    ->group(function () {
        // Payment confirmation webhook (Single Buy)
        Route::post('/payment', [WebhookController::class, 'handlePayment'])
            ->name('bancard.webhook.payment');

        // Card registration webhook (Zimple/Cards)
        Route::post('/card-registration', [WebhookController::class, 'handleCardRegistration'])
            ->name('bancard.webhook.card-registration');

        // Charge with token webhook (3DS confirmation)
        Route::post('/charge', [WebhookController::class, 'handleChargeWithToken'])
            ->name('bancard.webhook.charge');
    });
