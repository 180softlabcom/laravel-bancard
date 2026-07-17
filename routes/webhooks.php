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
        // UN SOLO webhook. Bancard usa una única "URL de confirmación" (la que cargás en
        // el portal de comercios) y por ahí llegan AMBOS tipos de callback: pago ocasional
        // (single_buy) y pago con token/3DS (charge). No hay webhooks separados por tipo.
        // El catastro NO tiene webhook: es local (iframe + syncBancardCards()).
        Route::post('/confirmation', [WebhookController::class, 'handleConfirmation'])
            ->name('bancard.webhook.confirmation');
    });
