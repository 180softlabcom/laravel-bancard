<?php

namespace Softlab180\Bancard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Softlab180\Bancard\Events\CardRegistered;
use Softlab180\Bancard\Events\PaymentFailed;
use Softlab180\Bancard\Events\PaymentSucceeded;
use Softlab180\Bancard\Exceptions\BancardException;
use Softlab180\Bancard\Facades\Bancard;
use Softlab180\Bancard\Models\BancardTransaction;
use Softlab180\Bancard\Models\SavedCard;

class WebhookController extends Controller
{
    /**
     * Handle Bancard webhook for payment confirmation (Single Buy).
     */
    public function handlePayment(Request $request): JsonResponse
    {
        $payload = $request->all();

        $this->logReceived('Bancard payment webhook received', $payload);

        try {
            $result = Bancard::processWebhook($payload);

            // Idempotencia: si este shop_process_id ya fue procesado (callback
            // reenviado por vPOS), acusar 200 sin volver a despachar el evento.
            if (! $this->claimTransaction((string) ($result['shop_process_id'] ?? ''), (bool) ($result['is_paid'] ?? false), $result)) {
                return response()->json(['status' => 'success', 'duplicate' => true]);
            }

            // processWebhook() devuelve un array PLANO con 'is_paid' (bool) y los
            // campos en la raíz; NO trae 'status' ni 'operation'.
            if ($result['is_paid'] ?? false) {
                PaymentSucceeded::dispatch(
                    shopProcessId: (string) ($result['shop_process_id'] ?? ''),
                    response: ['confirmation' => $result],
                    authorizationNumber: $result['authorization_number'] ?? null,
                    ticketNumber: $result['ticket_number'] ?? null,
                );
            } else {
                PaymentFailed::dispatch(
                    shopProcessId: (string) ($result['shop_process_id'] ?? ''),
                    response: ['confirmation' => $result],
                    errorCode: $result['response_code'] ?? null,
                    errorMessage: $result['extended_response_description'] ?? $result['response_description'] ?? null,
                );
            }

            // Bancard exige HTTP 200 para acusar recibo. vPOS no reintenta de forma
            // confiable: un no-200 hace que dé la confirmación por perdida. Tanto un pago
            // aprobado como uno rechazado son resultados de negocio válidos que ya quedaron
            // registrados vía evento, así que ambos se acusan con 200.
            return response()->json(['status' => 'success']);
        } catch (BancardException $e) {
            // Token/payload inválido (posible spoof o mala configuración): no es un
            // callback genuino de Bancard, así que no despachamos ningún evento.
            Log::warning('Bancard payment webhook rejected (invalid token/payload)', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return response()->json(['status' => 'rejected'], 200);
        } catch (\Throwable $e) {
            // Error genuino del servidor: 500 para que se investigue.
            Log::error('Bancard webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Handle Bancard webhook for card registration (catastro).
     */
    public function handleCardRegistration(Request $request): JsonResponse
    {
        $payload = $request->all();

        $this->logReceived('Bancard card registration webhook received', $payload);

        try {
            $operation = $payload['operation'] ?? [];

            // TODO[seguridad]: validar el token del callback de catastro ANTES de confiar
            // en el payload. La fórmula del token de "cards/new" NO está en el PDF
            // disponible; debe confirmarse contra la doc oficial de Catastro de Bancard e
            // implementarse en BancardVPOSService::validateCardRegistrationToken().
            // Mientras tanto el endpoint depende del middleware configurado (ver config).

            if (($operation['response_code'] ?? '') === '00') {
                // Bancard devuelve user_id/card_id como campos discretos en el callback
                // (los mismos que enviamos en initiateCardRegistration), NO dentro de un
                // shop_process_id compuesto.
                $userId = (int) ($operation['user_id'] ?? 0);
                $cardId = (int) ($operation['card_id'] ?? 0);

                $savedCard = null;
                if (config('bancard.auto_save_cards', true)) {
                    try {
                        $savedCard = $this->saveCard($userId, $cardId, $operation);
                    } catch (\Throwable $e) {
                        // La tarjeta ya quedó registrada en Bancard; logueamos para
                        // conciliar sin devolver 500 (no perder el callback).
                        Log::error('Bancard saveCard failed (card registered at Bancard)', [
                            'error' => $e->getMessage(),
                            'user_id' => $userId,
                            'card_id' => $cardId,
                        ]);
                    }
                }

                CardRegistered::dispatch(
                    userId: $userId,
                    cardId: $cardId,
                    response: $operation,
                    savedCard: $savedCard,
                );
            } else {
                Log::info('Bancard card registration not approved', ['operation' => $operation]);
            }

            // Acusar recibo con 200 en cualquier resultado de negocio (vPOS no reintenta).
            return response()->json(['status' => 'success']);
        } catch (\Throwable $e) {
            Log::error('Bancard card registration webhook failed', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Handle charge with token webhook (for 3DS confirmation).
     */
    public function handleChargeWithToken(Request $request): JsonResponse
    {
        $payload = $request->all();

        $this->logReceived('Bancard charge with token webhook received', $payload);

        try {
            $operation = $payload['operation'] ?? [];
            $aliasToken = $operation['alias_token'] ?? '';

            if (! Bancard::validateChargeWebhookToken($operation, $aliasToken)) {
                // Token inválido: no es un callback genuino. Logueamos y acusamos 200
                // (un no-200 podría hacer perder un callback legítimo por misconfig).
                Log::warning('Invalid token in charge webhook', ['operation' => $operation]);

                return response()->json(['status' => 'rejected'], 200);
            }

            $isApproved = ($operation['response_code'] ?? '') === '00';

            // Idempotencia: deduplicar callbacks reenviados.
            if (! $this->claimTransaction((string) ($operation['shop_process_id'] ?? ''), $isApproved, $operation)) {
                return response()->json(['status' => 'success', 'duplicate' => true]);
            }

            if ($isApproved) {
                PaymentSucceeded::dispatch(
                    shopProcessId: (string) ($operation['shop_process_id'] ?? ''),
                    response: ['confirmation' => $operation],
                    authorizationNumber: $operation['authorization_number'] ?? null,
                    ticketNumber: $operation['ticket_number'] ?? null,
                );
            } else {
                PaymentFailed::dispatch(
                    shopProcessId: (string) ($operation['shop_process_id'] ?? ''),
                    response: ['confirmation' => $operation],
                    errorCode: $operation['response_code'] ?? null,
                    errorMessage: $operation['extended_response_description'] ?? $operation['response_description'] ?? null,
                );
            }

            return response()->json(['status' => 'success']);
        } catch (\Throwable $e) {
            Log::error('Bancard charge with token webhook failed', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Loguea la recepción del webhook. El payload completo solo se loguea si
     * `bancard.webhook.log_payloads` está activo (BANCARD_LOG_WEBHOOKS); si no,
     * loguea únicamente el shop_process_id (higiene en producción).
     */
    protected function logReceived(string $label, array $payload): void
    {
        if (config('bancard.webhook.log_payloads', true)) {
            Log::info($label, ['payload' => $payload]);
        } else {
            Log::info($label, ['shop_process_id' => $payload['operation']['shop_process_id'] ?? null]);
        }
    }

    /**
     * Reclama atómicamente el shop_process_id para idempotencia. Devuelve true si
     * es la primera vez que se procesa, false si es un callback duplicado/reenvío.
     * Si la persistencia está desactivada o falla, procesa igual (sin dedup).
     */
    protected function claimTransaction(string $shopProcessId, bool $isPaid, array $payload): bool
    {
        if ($shopProcessId === '' || ! config('bancard.persist_transactions', true)) {
            return true;
        }

        try {
            BancardTransaction::firstOrCreate(
                ['shop_process_id' => $shopProcessId],
                ['status' => 'pending']
            );

            // UPDATE atómico: solo el primer callback transiciona desde 'pending'.
            $claimed = BancardTransaction::where('shop_process_id', $shopProcessId)
                ->where('status', 'pending')
                ->update([
                    'status' => $isPaid ? 'paid' : 'failed',
                    'authorization_number' => $payload['authorization_number'] ?? null,
                    'ticket_number' => $payload['ticket_number'] ?? null,
                    'last_payload' => json_encode($payload),
                    'processed_at' => now(),
                ]);

            return $claimed > 0;
        } catch (\Throwable $e) {
            Log::warning('Bancard: claim de transacción falló; se procesa sin dedup', [
                'shop_process_id' => $shopProcessId,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Persist a registered card.
     *
     * NOTA: soporta un único modelo de usuario (config bancard.user_model). Para
     * múltiples modelos / morph map habría que propagar el morph class desde el alta.
     */
    protected function saveCard(int|string $userId, int $cardId, array $operation): ?SavedCard
    {
        if (! $userId || ! isset($operation['alias_token'])) {
            return null;
        }

        $userType = config('bancard.user_model', 'App\\Models\\User');

        return SavedCard::updateOrCreate(
            [
                'user_id' => $userId,
                'user_type' => $userType,
                'alias_token' => $operation['alias_token'],
            ],
            [
                'card_masked_number' => $operation['card_masked_number'] ?? null,
                'card_brand' => $operation['card_brand'] ?? null,
                'card_type' => $operation['card_type'] ?? null,
                'expiration_date' => $operation['expiration_date'] ?? null,
                'card_id' => $cardId,
            ]
        );
    }
}
