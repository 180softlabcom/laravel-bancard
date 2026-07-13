<?php

namespace Softlab180\Bancard\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Softlab180\Bancard\Contracts\Payable;
use Softlab180\Bancard\Exceptions\BancardException;
use Softlab180\Bancard\Models\BancardTransaction;

class BancardVPOSService
{
    protected string $publicKey;
    protected string $privateKey;
    protected string $environment;
    protected string $baseUrl;
    protected string $checkoutUrl;

    public function __construct(
        ?string $publicKey = null,
        ?string $privateKey = null,
        string $environment = 'staging'
    ) {
        $this->publicKey = $publicKey ?? config('bancard.public_key');
        $this->privateKey = $privateKey ?? config('bancard.private_key');
        $this->environment = $environment;

        $this->baseUrl = config('bancard.urls')[$environment]
            ?? 'https://vpos.infonet.com.py:8888';
        $this->checkoutUrl = config('bancard.checkout_urls')[$environment]
            ?? 'https://vpos.infonet.com.py:8888/checkout';
    }

    /*
    |--------------------------------------------------------------------------
    | SINGLE BUY (Pago Ocasional)
    |--------------------------------------------------------------------------
    */

    /**
     * Create a single buy payment (occasional payment without saved card).
     */
    public function createSingleBuy(
        Payable $payable,
        ?string $description = null,
        ?string $returnUrl = null,
        ?string $cancelUrl = null
    ): array {
        $shopProcessId = $this->generateShopProcessId();
        $amount = $this->formatAmount($payable->getPayableAmount());
        $currency = $payable->getPayableCurrency() ?: config('bancard.currency', 'PYG');

        $token = $this->generateToken([
            $this->privateKey,
            $shopProcessId,
            $amount,
            $currency,
        ]);

        $frontendUrl = rtrim((string) config('bancard.frontend_url'), '/');
        $appendSpid = fn (string $url): string => $url.(str_contains($url, '?') ? '&' : '?').'shop_process_id='.urlencode($shopProcessId);
        $returnUrl = $returnUrl ?? $appendSpid($frontendUrl.config('bancard.return_url'));
        $cancelUrl = $cancelUrl ?? $appendSpid($frontendUrl.config('bancard.cancel_url'));

        $requestData = [
            'public_key' => $this->publicKey,
            'operation' => [
                'token' => $token,
                'shop_process_id' => $shopProcessId,
                'amount' => $amount,
                'currency' => $currency,
                'additional_data' => '',
                'description' => $description ?? $payable->getPayableDescription(),
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ],
        ];

        $this->logRequest('single_buy', $requestData);

        try {
            $response = Http::timeout(30)
                ->post($this->baseUrl . '/vpos/api/0.3/single_buy', $requestData);

            $responseData = $response->json();

            $this->logResponse('single_buy', $responseData);

            if (($responseData['status'] ?? '') !== 'success') {
                throw new BancardException(
                    $this->getErrorMessage($responseData),
                    $responseData
                );
            }

            $processId = $responseData['process_id'];

            $this->recordTransaction($payable, $shopProcessId, $processId, $amount, $currency, 'single_buy');

            return [
                'success' => true,
                'shop_process_id' => $shopProcessId,
                'process_id' => $processId,
                'amount' => $amount,
                'currency' => $currency,
                'iframe_url' => $this->buildCheckoutUrl($processId),
                'checkout_js_url' => $this->buildCheckoutScriptUrl($processId),
                'expires_at' => now()->addMinutes(config('bancard.payment_expiration_minutes', 30)),
                'raw_response' => $responseData,
            ];

        } catch (Exception $e) {
            Log::error('Bancard single_buy error', [
                'message' => $e->getMessage(),
                'shop_process_id' => $shopProcessId,
            ]);

            throw new BancardException(
                'Error creating payment: ' . $e->getMessage(),
                [],
                $e
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CHARGE WITH TOKEN (Pago con Tarjeta Guardada)
    |--------------------------------------------------------------------------
    */

    /**
     * Charge using a saved card token.
     */
    public function chargeWithToken(
        Payable $payable,
        string $aliasToken,
        int $numberOfPayments = 1,
        ?string $description = null,
        ?string $returnUrl = null
    ): array {
        $shopProcessId = $this->generateShopProcessId();
        $amount = $this->formatAmount($payable->getPayableAmount());
        $currency = $payable->getPayableCurrency() ?: config('bancard.currency', 'PYG');

        $token = $this->generateToken([
            $this->privateKey,
            $shopProcessId,
            'charge',
            $amount,
            $currency,
            $aliasToken,
        ]);

        $frontendUrl = config('bancard.frontend_url');
        $returnUrl = $returnUrl ?? $frontendUrl . config('bancard.return_url') . '?shop_process_id=' . $shopProcessId;

        $operation = [
            'token' => $token,
            'shop_process_id' => $shopProcessId,
            'amount' => $amount,
            'number_of_payments' => $numberOfPayments,
            'currency' => $currency,
            'additional_data' => '',
            'description' => $description ?? $payable->getPayableDescription(),
            'return_url' => $returnUrl,
            'alias_token' => $aliasToken,
        ];

        // extra_response_attributes habilita el flujo 3DS: Bancard devuelve
        // confirmation.process_id para levantar el iframe de desafío. Es OPT-IN
        // (bancard.enable_3ds) porque REQUIERE que Bancard tenga habilitado el
        // producto 3DS para el comercio: enviarlo sin ese permiso hace que Bancard
        // RECHACE la operación ("parámetro extra no habilitado", con riesgo en
        // producción — reportado por Bancard en homologación). Un comercio sin 3DS
        // NO debe enviarlo. Para el flujo 3DS, la spec (pág. 37) pide enviarlo siempre.
        if (config('bancard.enable_3ds', false)) {
            $operation['extra_response_attributes'] = ['confirmation.process_id'];
        }

        $requestData = [
            'public_key' => $this->publicKey,
            'operation' => $operation,
        ];

        $this->logRequest('charge', $requestData);

        try {
            $response = Http::timeout(30)
                ->post($this->baseUrl . '/vpos/api/0.3/charge', $requestData);

            $responseData = $response->json();

            $this->logResponse('charge', $responseData);

            $confirmation = $responseData['confirmation'] ?? [];

            $this->recordTransaction($payable, $shopProcessId, $confirmation['process_id'] ?? null, $amount, $currency, 'charge');

            // Check if 3DS is required
            if (isset($confirmation['process_id']) && !empty($confirmation['process_id']) && empty($confirmation['response'])) {
                return [
                    'success' => true,
                    'requires_3ds' => true,
                    'shop_process_id' => $shopProcessId,
                    'process_id' => $confirmation['process_id'],
                    'checkout_js_url' => $this->buildCheckoutScriptUrl($confirmation['process_id']),
                    'raw_response' => $responseData,
                ];
            }

            // Direct payment (no 3DS)
            $isSuccessful = ($confirmation['response'] ?? '') === 'S'
                && ($confirmation['response_code'] ?? '') === '00';

            if ($isSuccessful) {
                return [
                    'success' => true,
                    'requires_3ds' => false,
                    'payment_completed' => true,
                    'shop_process_id' => $shopProcessId,
                    'authorization_number' => $confirmation['authorization_number'] ?? null,
                    'ticket_number' => $confirmation['ticket_number'] ?? null,
                    'response_code' => $confirmation['response_code'] ?? null,
                    'response_description' => $confirmation['response_description'] ?? null,
                    'raw_response' => $responseData,
                ];
            }

            // Payment rejected
            return [
                'success' => false,
                'requires_3ds' => false,
                'shop_process_id' => $shopProcessId,
                'error' => $confirmation['response_description'] ?? 'Payment rejected',
                'response_code' => $confirmation['response_code'] ?? null,
                'raw_response' => $responseData,
            ];

        } catch (Exception $e) {
            Log::error('Bancard charge error', [
                'message' => $e->getMessage(),
                'shop_process_id' => $shopProcessId,
            ]);

            throw new BancardException(
                'Error charging card: ' . $e->getMessage(),
                [],
                $e
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CARD REGISTRATION (Catastro de Tarjetas)
    |--------------------------------------------------------------------------
    */

    /**
     * Initiate card registration for a user.
     */
    public function initiateCardRegistration(
        int|string $userId,
        int $cardId,
        string $userPhone,
        string $userEmail,
        ?string $returnUrl = null
    ): array {
        $token = $this->generateToken([
            $this->privateKey,
            $cardId,
            $userId,
            'request_new_card',
        ]);

        $frontendUrl = config('bancard.frontend_url');
        $returnUrl = $returnUrl ?? $frontendUrl . '/card-registration/result';

        $requestData = [
            'public_key' => $this->publicKey,
            'operation' => [
                'token' => $token,
                'card_id' => $cardId,
                'user_id' => (string) $userId,
                'user_cell_phone' => $this->normalizePhone($userPhone),
                'user_mail' => strtolower(trim($userEmail)),
                'return_url' => $returnUrl,
            ],
        ];

        $this->logRequest('cards/new', $requestData);

        try {
            $response = Http::timeout(30)
                ->post($this->baseUrl . '/vpos/api/0.3/cards/new', $requestData);

            $responseData = $response->json();

            $this->logResponse('cards/new', $responseData);

            if (($responseData['status'] ?? '') !== 'success') {
                throw new BancardException(
                    $this->getErrorMessage($responseData),
                    $responseData
                );
            }

            $processId = $responseData['process_id'];

            return [
                'success' => true,
                'process_id' => $processId,
                'user_id' => $userId,
                'card_id' => $cardId,
                'checkout_js_url' => $this->buildCheckoutScriptUrl($processId),
                'raw_response' => $responseData,
            ];

        } catch (Exception $e) {
            Log::error('Bancard card registration error', [
                'message' => $e->getMessage(),
                'user_id' => $userId,
            ]);

            throw new BancardException(
                'Error initiating card registration: ' . $e->getMessage(),
                [],
                $e
            );
        }
    }

    /**
     * Get user's saved cards from Bancard.
     */
    public function getUserCards(int|string $userId): array
    {
        $token = $this->generateToken([
            $this->privateKey,
            $userId,
            'request_user_cards',
        ]);

        $requestData = [
            'public_key' => $this->publicKey,
            'operation' => [
                'token' => $token,
                'extra_response_attributes' => ['cards.bancard_proccessed'],
            ],
        ];

        $this->logRequest('users/cards', $requestData);

        try {
            $response = Http::timeout(30)
                ->post($this->baseUrl . '/vpos/api/0.3/users/' . $userId . '/cards', $requestData);

            $responseData = $response->json();

            $this->logResponse('users/cards', $responseData);

            if (($responseData['status'] ?? '') !== 'success') {
                // No cards is not an error
                if (str_contains($responseData['messages'][0]['dsc'] ?? '', 'no tiene tarjetas')) {
                    return [
                        'success' => true,
                        'cards' => [],
                    ];
                }

                throw new BancardException(
                    $this->getErrorMessage($responseData),
                    $responseData
                );
            }

            return [
                'success' => true,
                'cards' => $responseData['cards'] ?? [],
                'raw_response' => $responseData,
            ];

        } catch (BancardException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Bancard get cards error', [
                'message' => $e->getMessage(),
                'user_id' => $userId,
            ]);

            throw new BancardException(
                'Error getting user cards: ' . $e->getMessage(),
                [],
                $e
            );
        }
    }

    /**
     * Delete a saved card.
     */
    public function deleteCard(int|string $userId, string $aliasToken): array
    {
        $token = $this->generateToken([
            $this->privateKey,
            'delete_card',
            $userId,
            $aliasToken,
        ]);

        $requestData = [
            'public_key' => $this->publicKey,
            'operation' => [
                'token' => $token,
                'alias_token' => $aliasToken,
            ],
        ];

        $this->logRequest('users/cards/delete', $requestData);

        try {
            $response = Http::timeout(30)
                ->delete($this->baseUrl . '/vpos/api/0.3/users/' . $userId . '/cards', $requestData);

            $responseData = $response->json();

            $this->logResponse('users/cards/delete', $responseData);

            if (($responseData['status'] ?? '') !== 'success') {
                throw new BancardException(
                    $this->getErrorMessage($responseData),
                    $responseData
                );
            }

            return [
                'success' => true,
                'raw_response' => $responseData,
            ];

        } catch (BancardException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Bancard delete card error', [
                'message' => $e->getMessage(),
                'user_id' => $userId,
            ]);

            throw new BancardException(
                'Error deleting card: ' . $e->getMessage(),
                [],
                $e
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PAYMENT CONFIRMATION
    |--------------------------------------------------------------------------
    */

    /**
     * Get payment confirmation status.
     */
    public function getPaymentConfirmation(string $shopProcessId): array
    {
        $token = $this->generateToken([
            $this->privateKey,
            $shopProcessId,
            'get_confirmation',
        ]);

        $requestData = [
            'public_key' => $this->publicKey,
            'operation' => [
                'token' => $token,
                'shop_process_id' => $shopProcessId,
            ],
        ];

        $this->logRequest('single_buy/confirmations', $requestData);

        try {
            $response = Http::timeout(30)
                ->post($this->baseUrl . '/vpos/api/0.3/single_buy/confirmations', $requestData);

            $responseData = $response->json();

            $this->logResponse('single_buy/confirmations', $responseData);

            if (($responseData['status'] ?? '') !== 'success') {
                return [
                    'success' => false,
                    'error' => $this->getErrorMessage($responseData),
                    'raw_response' => $responseData,
                ];
            }

            $confirmation = $responseData['confirmation'] ?? [];

            return [
                'success' => true,
                'is_paid' => ($confirmation['response'] ?? '') === 'S'
                    && ($confirmation['response_code'] ?? '') === '00',
                'confirmation' => $confirmation,
                'raw_response' => $responseData,
            ];

        } catch (Exception $e) {
            Log::error('Bancard get confirmation error', [
                'message' => $e->getMessage(),
                'shop_process_id' => $shopProcessId,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK
    |--------------------------------------------------------------------------
    */

    /**
     * Rollback a payment.
     */
    public function rollbackPayment(string $shopProcessId): array
    {
        $token = $this->generateToken([
            $this->privateKey,
            $shopProcessId,
            'rollback',
            '0.00',
        ]);

        $requestData = [
            'public_key' => $this->publicKey,
            'operation' => [
                'token' => $token,
                'shop_process_id' => $shopProcessId,
            ],
        ];

        $this->logRequest('single_buy/rollback', $requestData);

        try {
            $response = Http::timeout(30)
                ->post($this->baseUrl . '/vpos/api/0.3/single_buy/rollback', $requestData);

            $responseData = $response->json();

            $this->logResponse('single_buy/rollback', $responseData);

            if (($responseData['status'] ?? '') === 'success') {
                return [
                    'success' => true,
                    'message' => 'Rollback completed successfully',
                    'raw_response' => $responseData,
                ];
            }

            return [
                'success' => false,
                'error' => $this->getErrorMessage($responseData),
                'raw_response' => $responseData,
            ];

        } catch (Exception $e) {
            Log::error('Bancard rollback error', [
                'message' => $e->getMessage(),
                'shop_process_id' => $shopProcessId,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | WEBHOOK PROCESSING
    |--------------------------------------------------------------------------
    */

    /**
     * Process webhook callback from Bancard.
     */
    public function processWebhook(array $payload): array
    {
        $operation = $payload['operation'] ?? [];

        if (empty($operation)) {
            throw new BancardException('Invalid webhook payload: missing operation');
        }

        $shopProcessId = (string) ($operation['shop_process_id'] ?? '');

        if (empty($shopProcessId)) {
            throw new BancardException('Invalid webhook payload: missing shop_process_id');
        }

        // Validate token
        if (!$this->validateWebhookToken($operation)) {
            throw new BancardException('Invalid webhook token');
        }

        $isSuccessful = ($operation['response'] ?? '') === 'S'
            && ($operation['response_code'] ?? '') === '00';

        return [
            'success' => true,
            'shop_process_id' => $shopProcessId,
            'is_paid' => $isSuccessful,
            'response_code' => $operation['response_code'] ?? null,
            'response_description' => $operation['response_description'] ?? null,
            // Motivo legible/detallado del resultado (p.ej. "VALOR INCORRECTO DEL CVV2").
            'extended_response_description' => $operation['extended_response_description'] ?? null,
            'response_details' => $operation['response_details'] ?? null,
            'authorization_number' => $operation['authorization_number'] ?? null,
            'ticket_number' => $operation['ticket_number'] ?? null,
            'amount' => $operation['amount'] ?? null,
            'currency' => $operation['currency'] ?? null,
            'security_information' => $operation['security_information'] ?? null,
            'additional_data' => json_decode($operation['additional_data'] ?? '{}', true),
        ];
    }

    /**
     * Validate webhook token.
     */
    protected function validateWebhookToken(array $operation): bool
    {
        $receivedToken = $operation['token'] ?? '';
        $shopProcessId = (string) ($operation['shop_process_id'] ?? '');
        $amount = $operation['amount'] ?? '';
        $currency = $operation['currency'] ?? 'PYG';

        // Formula for single_buy confirm
        $confirmToken = $this->generateToken([
            $this->privateKey,
            $shopProcessId,
            'confirm',
            $amount,
            $currency,
        ]);

        if ($receivedToken !== '' && hash_equals($confirmToken, $receivedToken)) {
            return true;
        }

        // Formula for charge (token payment) - would need alias_token from order
        // This is handled in the application layer

        Log::warning('Bancard webhook token validation failed', [
            'shop_process_id' => $shopProcessId,
            'received_token' => substr($receivedToken, 0, 10) . '...',
        ]);

        return false;
    }

    /**
     * Validate webhook token for charge operations (needs alias_token).
     */
    public function validateChargeWebhookToken(array $operation, string $aliasToken): bool
    {
        $receivedToken = $operation['token'] ?? '';
        $shopProcessId = (string) ($operation['shop_process_id'] ?? '');
        $amount = $operation['amount'] ?? '';
        $currency = $operation['currency'] ?? 'PYG';

        $chargeToken = $this->generateToken([
            $this->privateKey,
            $shopProcessId,
            'charge',
            $amount,
            $currency,
            $aliasToken,
        ]);

        return $receivedToken !== '' && hash_equals($chargeToken, $receivedToken);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Registra la operación localmente (idempotencia + conciliación) e invoca el
     * hook storeBancardPayment() del Payable. Best-effort: nunca bloquea el pago.
     */
    protected function recordTransaction(Payable $payable, string $shopProcessId, ?string $processId, string $amount, string $currency, string $type): void
    {
        if (config('bancard.persist_transactions', true)) {
            try {
                BancardTransaction::updateOrCreate(
                    ['shop_process_id' => $shopProcessId],
                    [
                        'process_id' => $processId,
                        'type' => $type,
                        'status' => 'pending',
                        'amount' => $amount,
                        'currency' => $currency,
                        'payable_type' => $payable::class,
                        'payable_id' => (string) $payable->getPayableId(),
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning('Bancard: no se pudo registrar la transacción', [
                    'shop_process_id' => $shopProcessId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $payable->storeBancardPayment([
                'shop_process_id' => $shopProcessId,
                'process_id' => $processId,
                'amount' => $amount,
                'currency' => $currency,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Bancard: storeBancardPayment() del Payable falló', [
                'shop_process_id' => $shopProcessId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate a unique shop process ID.
     */
    protected function generateShopProcessId(): string
    {
        // shop_process_id es la clave de idempotencia y del token de confirmación;
        // no admite colisiones. time().rand() colisiona bajo ráfaga, así que generamos
        // un id numérico de 15 dígitos con entropía CSPRNG (6 de tiempo + 9 aleatorios).
        return substr((string) time(), -6).str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    }

    /**
     * Generate token for Bancard operations.
     */
    protected function generateToken(array $parts): string
    {
        return md5(implode('', $parts));
    }

    /**
     * Format amount for Bancard (2 decimal places).
     */
    protected function formatAmount(float|int $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Build checkout iframe URL.
     */
    protected function buildCheckoutUrl(string $processId): string
    {
        // Bancard sirve el checkout en /checkout/{process_id} (igual que el servicio
        // de producción). El antiguo /new?process_id= devolvía HTTP 404.
        return $this->checkoutUrl . '/' . $processId;
    }

    /**
     * Build checkout script URL for embedding.
     */
    protected function buildCheckoutScriptUrl(?string $processId = null): string
    {
        // SDK estático de Bancard: mismo archivo en staging y producción (solo cambia
        // el host). El process_id NO va como query string del .js; se pasa en el front a
        // Bancard.Checkout.createForm(container, process_id). La ruta /js/...-v2.js daba 404.
        $version = config('bancard.checkout_script_version', '4.0.0');

        return $this->checkoutUrl . '/javascript/dist/bancard-checkout-' . $version . '.js';
    }

    /**
     * Normalize phone number for Paraguay.
     */
    protected function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Remove country code if present
        if (str_starts_with($phone, '595')) {
            $phone = substr($phone, 3);
        }

        // Remove leading 0 if present
        if (str_starts_with($phone, '0')) {
            $phone = substr($phone, 1);
        }

        return $phone;
    }

    /**
     * Get error message from Bancard response.
     */
    protected function getErrorMessage(array $response): string
    {
        if (isset($response['messages']) && is_array($response['messages'])) {
            $messages = array_map(fn($m) => $m['dsc'] ?? $m['key'] ?? '', $response['messages']);
            return implode('. ', array_filter($messages));
        }

        return $response['message'] ?? 'Unknown Bancard error';
    }

    /**
     * Log request to Bancard.
     */
    protected function logRequest(string $endpoint, array $data): void
    {
        if (config('bancard.webhook.log_payloads', true)) {
            // Remove sensitive data
            $safeData = $data;
            if (isset($safeData['operation']['token'])) {
                $safeData['operation']['token'] = substr($safeData['operation']['token'], 0, 10) . '...';
            }

            Log::info('Bancard request', [
                'endpoint' => $endpoint,
                'data' => $safeData,
            ]);
        }
    }

    /**
     * Log response from Bancard.
     */
    protected function logResponse(string $endpoint, array $data): void
    {
        if (config('bancard.webhook.log_payloads', true)) {
            Log::info('Bancard response', [
                'endpoint' => $endpoint,
                'status' => $data['status'] ?? 'unknown',
                'data' => $data,
            ]);
        }
    }

    /**
     * Check if a payment has expired.
     */
    public function isPaymentExpired(\DateTimeInterface $expiresAt): bool
    {
        return now()->isAfter($expiresAt);
    }

    /**
     * Get the base URL for the current environment.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Get the checkout URL for the current environment.
     */
    public function getCheckoutUrl(): string
    {
        return $this->checkoutUrl;
    }
}
