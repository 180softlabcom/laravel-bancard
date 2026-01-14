<?php

namespace Softlab180\Bancard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Softlab180\Bancard\Events\CardDeleted;
use Softlab180\Bancard\Events\CardRegistered;
use Softlab180\Bancard\Events\PaymentFailed;
use Softlab180\Bancard\Events\PaymentSucceeded;
use Softlab180\Bancard\Facades\Bancard;
use Softlab180\Bancard\Models\SavedCard;

class WebhookController extends Controller
{
    /**
     * Handle Bancard webhook for payment confirmation.
     */
    public function handlePayment(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('Bancard payment webhook received', ['payload' => $payload]);

        try {
            $result = Bancard::processWebhook($payload);

            if ($result['status'] === 'success') {
                $operation = $result['operation'] ?? [];

                PaymentSucceeded::dispatch(
                    shopProcessId: $operation['shop_process_id'] ?? '',
                    response: $result,
                    authorizationNumber: $operation['authorization_number'] ?? null,
                    ticketNumber: $operation['ticket_number'] ?? null,
                );

                return response()->json(['status' => 'ok']);
            }

            PaymentFailed::dispatch(
                shopProcessId: $payload['operation']['shop_process_id'] ?? '',
                response: $result,
                errorCode: $result['messages'][0]['key'] ?? null,
                errorMessage: $result['messages'][0]['dsc'] ?? null,
            );

            return response()->json(['status' => 'error'], 400);
        } catch (\Exception $e) {
            Log::error('Bancard webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle Bancard webhook for card registration (Zimple).
     */
    public function handleCardRegistration(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('Bancard card registration webhook received', ['payload' => $payload]);

        try {
            $operation = $payload['operation'] ?? [];
            $status = $operation['response_code'] ?? '';

            if ($status === '00' || $status === 'success') {
                // Extract card data from payload
                $userId = $this->extractUserIdFromShopProcessId($operation['shop_process_id'] ?? '');
                $cardId = $this->extractCardIdFromShopProcessId($operation['shop_process_id'] ?? '');

                // Save the card if configured
                $savedCard = null;
                if (config('bancard.auto_save_cards', true)) {
                    $savedCard = $this->saveCard($userId, $cardId, $operation);
                }

                CardRegistered::dispatch(
                    userId: $userId,
                    cardId: $cardId,
                    response: $operation,
                    savedCard: $savedCard,
                );

                return response()->json(['status' => 'ok']);
            }

            return response()->json(['status' => 'error'], 400);
        } catch (\Exception $e) {
            Log::error('Bancard card registration webhook failed', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle charge with token webhook (for 3DS confirmation).
     */
    public function handleChargeWithToken(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('Bancard charge with token webhook received', ['payload' => $payload]);

        try {
            $operation = $payload['operation'] ?? [];
            $aliasToken = $operation['alias_token'] ?? '';

            // Validate the token
            if (!Bancard::validateChargeWebhookToken($operation, $aliasToken)) {
                Log::warning('Invalid token in charge webhook', ['operation' => $operation]);
                return response()->json(['status' => 'error', 'message' => 'Invalid token'], 401);
            }

            $responseCode = $operation['response_code'] ?? '';

            if ($responseCode === '00') {
                PaymentSucceeded::dispatch(
                    shopProcessId: $operation['shop_process_id'] ?? '',
                    response: ['confirmation' => $operation],
                    authorizationNumber: $operation['authorization_number'] ?? null,
                    ticketNumber: $operation['ticket_number'] ?? null,
                );

                return response()->json(['status' => 'ok']);
            }

            PaymentFailed::dispatch(
                shopProcessId: $operation['shop_process_id'] ?? '',
                response: $payload,
                errorCode: $responseCode,
                errorMessage: $operation['response_description'] ?? null,
            );

            return response()->json(['status' => 'error'], 400);
        } catch (\Exception $e) {
            Log::error('Bancard charge with token webhook failed', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Extract user ID from shop_process_id.
     * Expected format: user_{userId}_card_{cardId}_{timestamp}
     */
    protected function extractUserIdFromShopProcessId(string $shopProcessId): int|string
    {
        if (preg_match('/user_(\d+)_/', $shopProcessId, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Extract card ID from shop_process_id.
     * Expected format: user_{userId}_card_{cardId}_{timestamp}
     */
    protected function extractCardIdFromShopProcessId(string $shopProcessId): int
    {
        if (preg_match('/card_(\d+)_/', $shopProcessId, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Save the card to the database.
     */
    protected function saveCard(int|string $userId, int $cardId, array $operation): ?SavedCard
    {
        if (!$userId || !isset($operation['alias_token'])) {
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
