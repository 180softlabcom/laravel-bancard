<?php

namespace Softlab180\Bancard\Services;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Softlab180\Bancard\Contracts\BancardIdempotencyStore;
use Softlab180\Bancard\Contracts\Payable;
use Softlab180\Bancard\Exceptions\BancardException;
use Softlab180\Bancard\Exceptions\BancardUnknownOutcomeException;
use Softlab180\Bancard\Models\BancardTransaction;

class BancardVPOSService
{
    protected string $publicKey;
    protected string $privateKey;
    protected string $environment;
    protected string $baseUrl;
    protected string $checkoutUrl;

    /** Toggles por-tenant (p.ej. enable_3ds). Vacío = cae a config('bancard.*') global. */
    protected array $flags = [];

    public function __construct(
        ?string $publicKey = null,
        ?string $privateKey = null,
        string $environment = 'staging',
        array $flags = []
    ) {
        $this->publicKey = $publicKey ?? config('bancard.public_key');
        $this->privateKey = $privateKey ?? config('bancard.private_key');
        $this->environment = $environment;
        $this->flags = $flags;

        $this->baseUrl = config('bancard.urls')[$environment]
            ?? 'https://vpos.infonet.com.py:8888';
        $this->checkoutUrl = config('bancard.checkout_urls')[$environment]
            ?? 'https://vpos.infonet.com.py:8888/checkout';
    }

    /**
     * Construye una instancia con las llaves de un comercio resuelto (multi-tenant).
     *
     * El webhook usa ESTA vía —no el singleton global del container— para validar el
     * token/consultar la confirmación con la llave del comercio dueño del callback.
     */
    public static function forContext(\Softlab180\Bancard\Tenancy\BancardTenantContext $context): self
    {
        return new self($context->publicKey, $context->privateKey, $context->environment, $context->flags);
    }

    /**
     * Lee un flag por-tenant (p.ej. enable_3ds) de la instancia; si el comercio no lo
     * define, cae al $default (típicamente config('bancard.*') global → single-tenant).
     */
    public function flag(string $key, mixed $default = null): mixed
    {
        return $this->flags[$key] ?? $default;
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

        // El shop_process_id se genera acá (el caller no puede incluirlo), así que
        // el paquete DEBE inyectarlo en la URL de retorno —tanto en la default como
        // en la que provea el caller—: es el único identificador de la transacción
        // en el retorno del browser (contexto cross-site, sin cookie de sesión).
        $frontendUrl = rtrim((string) config('bancard.frontend_url'), '/');
        $returnUrl = $this->appendShopProcessId($returnUrl ?? $frontendUrl.config('bancard.return_url'), $shopProcessId);
        $cancelUrl = $this->appendShopProcessId($cancelUrl ?? $frontendUrl.config('bancard.cancel_url'), $shopProcessId);

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
            $response = $this->http()
                ->post($this->baseUrl . '/vpos/api/0.3/single_buy', $requestData);

            $responseData = $this->decodeResponse('single_buy', $response, $shopProcessId);

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

        } catch (BancardUnknownOutcomeException $e) {
            throw $e;
        } catch (\Throwable $e) {
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

        $frontendUrl = rtrim((string) config('bancard.frontend_url'), '/');
        $returnUrl = $this->appendShopProcessId($returnUrl ?? $frontendUrl.config('bancard.return_url'), $shopProcessId);

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
        // El flag se lee por-tenant (flags del context); fallback a config global.
        if ($this->flag('enable_3ds', config('bancard.enable_3ds', false))) {
            $operation['extra_response_attributes'] = ['confirmation.process_id'];
        }

        $requestData = [
            'public_key' => $this->publicKey,
            'operation' => $operation,
        ];

        $this->logRequest('charge', $requestData);

        // Si la transacción ya se registró, un error posterior no la vuelve a registrar.
        $recorded = false;

        try {
            $response = $this->http()
                ->post($this->baseUrl . '/vpos/api/0.3/charge', $requestData);

            $responseData = $this->decodeResponse('charge', $response, $shopProcessId);

            // Una respuesta de Bancard es: "confirmation" como objeto (el resultado del cobro),
            // o un error explícito ("status": "error" con su lista "messages", p.ej.
            // InvalidTokenError). Cualquier otro JSON — de un proxy, "status" numérico,
            // "success" sin "confirmation" — no dice si se cobró: es desconocido, NO un rechazo
            // (tratarlo como rechazo invita a reintentar y cobrar dos veces).
            $hasConfirmation = is_array($responseData['confirmation'] ?? null);
            $isBancardError = ($responseData['status'] ?? null) === 'error'
                && is_array($responseData['messages'] ?? null);

            if (! $hasConfirmation && ! $isBancardError) {
                throw new BancardUnknownOutcomeException(
                    "Bancard respondió un JSON que no es una respuesta de cobro en charge (HTTP {$response->status()}): "
                    .'no se sabe si el cobro se procesó.',
                    'charge',
                    $response->status(),
                    $this->bodySnippet($response->body()),
                    $shopProcessId,
                );
            }

            $confirmation = $hasConfirmation ? $responseData['confirmation'] : [];
            $processId = is_string($confirmation['process_id'] ?? null) && $confirmation['process_id'] !== ''
                ? $confirmation['process_id']
                : null;

            $this->recordTransaction($payable, $shopProcessId, $processId, $amount, $currency, 'charge', $aliasToken);
            $recorded = true;

            // Check if 3DS is required
            if ($processId !== null && empty($confirmation['response'])) {
                return [
                    'success' => true,
                    'requires_3ds' => true,
                    'shop_process_id' => $shopProcessId,
                    'process_id' => $processId,
                    'checkout_js_url' => $this->buildCheckoutScriptUrl($processId),
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

            // Payment rejected (Bancard lo dijo explícitamente)
            return [
                'success' => false,
                'requires_3ds' => false,
                'shop_process_id' => $shopProcessId,
                'error' => is_string($confirmation['response_description'] ?? null)
                    ? $confirmation['response_description']
                    : ($isBancardError ? $this->getErrorMessage($responseData) : 'Payment rejected'),
                'response_code' => $confirmation['response_code'] ?? null,
                'raw_response' => $responseData,
            ];

        } catch (BancardUnknownOutcomeException $e) {
            if (! $recorded) {
                $this->recordUnknownCharge($payable, $shopProcessId, $amount, $currency, $aliasToken, $e->getMessage());
            }

            throw $e;
        } catch (ConnectionException|TransferException $e) {
            // Timeout o conexión cortada: el cobro pudo haberse enviado y hecho. (En Laravel
            // ≤11, un corte a mitad de la respuesta — cURL 56 — llega como RequestException de
            // Guzzle, no como ConnectionException: por eso también TransferException.)
            if (! $recorded) {
                $this->recordUnknownCharge($payable, $shopProcessId, $amount, $currency, $aliasToken, $e->getMessage());
            }

            throw new BancardUnknownOutcomeException(
                'No hubo respuesta de Bancard al cobrar (timeout o conexión cortada): el cobro pudo haberse hecho. '
                .'Verificá con getPaymentConfirmation() antes de reintentar.',
                'charge',
                null,
                null,
                $shopProcessId,
                $e,
            );
        } catch (\Throwable $e) {
            // Todo lo que hay en este bloque ocurre durante o después de enviar el cobro: si
            // algo falla, no sabemos si se cobró. Dirección segura: desconocido, no "fallo".
            if (! $recorded) {
                $this->recordUnknownCharge($payable, $shopProcessId, $amount, $currency, $aliasToken, $e->getMessage());
            }

            throw new BancardUnknownOutcomeException(
                'Error al procesar el cobro ('.$e->getMessage().'): el cobro pudo haberse hecho. '
                .'Verificá con getPaymentConfirmation() antes de reintentar.',
                'charge',
                null,
                null,
                $shopProcessId,
                $e,
            );
        }
    }

    /**
     * Charge con resultado DESCONOCIDO: se registra igual (transacción pending + alias en el
     * idempotency store + storeBancardPayment del Payable). Así el webhook de ese cobro, si
     * llega, puede validar el token (necesita el alias), y el consumidor tiene el
     * shop_process_id para conciliar con getPaymentConfirmation().
     */
    protected function recordUnknownCharge(Payable $payable, string $shopProcessId, string $amount, string $currency, string $aliasToken, string $reason): void
    {
        Log::error('Bancard charge: resultado DESCONOCIDO', [
            'shop_process_id' => $shopProcessId,
            'reason' => $reason,
        ]);

        $this->recordTransaction($payable, $shopProcessId, null, $amount, $currency, 'charge', $aliasToken);
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
            $response = $this->http()
                ->post($this->baseUrl . '/vpos/api/0.3/cards/new', $requestData);

            $responseData = $this->decodeResponse('cards/new', $response);

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

        } catch (BancardUnknownOutcomeException $e) {
            throw $e;
        } catch (\Throwable $e) {
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
            $response = $this->http()
                ->post($this->baseUrl . '/vpos/api/0.3/users/' . $userId . '/cards', $requestData);

            $responseData = $this->decodeResponse('users/cards', $response);

            if (($responseData['status'] ?? '') !== 'success') {
                // No cards is not an error
                if (str_contains($this->getErrorMessage($responseData), 'no tiene tarjetas')) {
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

            // Solo tarjetas que son objetos: un "cards" que no es lista (o con elementos que
            // no son objetos) no debe reventar a quien las recorre con `array $card`.
            $cards = is_array($responseData['cards'] ?? null)
                ? array_values(array_filter($responseData['cards'], 'is_array'))
                : [];

            return [
                'success' => true,
                'cards' => $cards,
                'raw_response' => $responseData,
            ];

        } catch (BancardException $e) {
            throw $e;
        } catch (\Throwable $e) {
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
     * Devuelve un `alias_token` FRESCO para una tarjeta del usuario, pedido a Bancard en el
     * momento (`users_cards`).
     *
     * El `alias_token` es EFÍMERO: la spec (pág. 35) lo describe como "alias token temporal",
     * con validez para UNA sola operación y TTL del orden de minutos. Por eso hay que pedir
     * uno nuevo ANTES de cada charge/delete — el guardado se vence. Matchea la tarjeta por
     * identidad ESTABLE: `card_id` (preferido) o `card_masked_number` + `expiration_date`.
     *
     * Pensado para consumidores que usan el service directo (sin el trait `HasBancardCards`):
     *
     *     $alias = $service->freshAliasToken($userId, ['card_id' => $cardId]);
     *     // $alias === null → la tarjeta ya no está catastrada
     *     $service->chargeWithToken($payable, $alias, ...);   // o ->deleteCard($userId, $alias)
     *
     * Devuelve null si la tarjeta ya no está catastrada en Bancard.
     *
     * @param  array{card_id?: mixed, card_masked_number?: ?string, expiration_date?: ?string}  $cardIdentity
     */
    public function freshAliasToken(int|string $userId, array $cardIdentity): ?string
    {
        $result = $this->getUserCards($userId);

        $wantedId = $cardIdentity['card_id'] ?? null;

        $match = collect($result['cards'] ?? [])->first(function ($c) use ($cardIdentity, $wantedId) {
            if (! is_array($c)) {
                return false;
            }

            if (is_scalar($wantedId) && (string) $wantedId !== ''
                && is_scalar($c['card_id'] ?? null) && (string) $c['card_id'] === (string) $wantedId) {
                return true;
            }

            return ! empty($cardIdentity['card_masked_number'])
                && ($c['card_masked_number'] ?? null) === $cardIdentity['card_masked_number']
                && ($c['expiration_date'] ?? null) === ($cardIdentity['expiration_date'] ?? null);
        });

        return is_string($match['alias_token'] ?? null) && $match['alias_token'] !== '' ? $match['alias_token'] : null;
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
            $response = $this->http()
                ->delete($this->baseUrl . '/vpos/api/0.3/users/' . $userId . '/cards', $requestData);

            $responseData = $this->decodeResponse('users/cards/delete', $response);

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
        } catch (\Throwable $e) {
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
     *
     * Sirve para single_buy Y charge (operación común, spec pág. 44/55). El $timeout
     * (segundos) permite acotar la espera cuando se usa como verificación DENTRO del
     * webhook (modo 'requery'), que debe acusar en <30s; default 30s para uso normal.
     */
    public function getPaymentConfirmation(string $shopProcessId, ?int $timeout = null): array
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
            $response = $this->http($timeout ?? 30)
                ->post($this->baseUrl . '/vpos/api/0.3/single_buy/confirmations', $requestData);

            $responseData = $this->decodeResponse('single_buy/confirmations', $response, $shopProcessId);

            if (($responseData['status'] ?? '') !== 'success') {
                return [
                    'success' => false,
                    'error' => $this->getErrorMessage($responseData),
                    'raw_response' => $responseData,
                ];
            }

            $confirmation = is_array($responseData['confirmation'] ?? null) ? $responseData['confirmation'] : [];

            return [
                'success' => true,
                'is_paid' => ($confirmation['response'] ?? '') === 'S'
                    && ($confirmation['response_code'] ?? '') === '00',
                'confirmation' => $confirmation,
                'raw_response' => $responseData,
            ];

        } catch (BancardUnknownOutcomeException $e) {
            // No se pudo leer el estado (cuerpo no JSON). No es "no pagado": es desconocido.
            return [
                'success' => false,
                'outcome' => 'unknown',
                'error' => $e->getMessage(),
                'http_status' => $e->httpStatus,
                'raw_body' => $e->rawBody,
            ];
        } catch (\Throwable $e) {
            Log::error('Bancard get confirmation error', [
                'message' => $e->getMessage(),
                'shop_process_id' => $shopProcessId,
            ]);

            return [
                'success' => false,
                'outcome' => 'unknown',
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
     *
     * Nunca lanza. Devuelve siempre `success` y `outcome`:
     * - 'rolled_back'         → Bancard aceptó la reversa ("status": "success").
     * - 'already_rolled_back' → AlreadyRollbackedError: ya había un pedido de reversa previo.
     *                           NO reembolsar a mano: el pago ya se está reversando.
     * - 'rejected'            → Bancard contestó "status": "error" (ver `bancard_key`: p.ej.
     *                           TransactionAlreadyConfirmed = ya cuponado, hay que pedir la
     *                           anulación manual; PaymentNotFoundError = el cliente no pagó).
     * - 'unknown'             → no hubo una respuesta confiable (cuerpo no JSON, sin "status",
     *                           timeout). NO es un rechazo: el pago pudo haberse reversado.
     *                           Trae `http_status` y `raw_body`. Verificar antes de reembolsar.
     * (Claves de error: doc eCommerce Bancard, pág. 51-54.)
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
            $response = $this->http()
                ->post($this->baseUrl . '/vpos/api/0.3/single_buy/rollback', $requestData);

            $responseData = $this->decodeResponse('single_buy/rollback', $response, $shopProcessId);

            $status = $responseData['status'] ?? null;
            $keys = $this->messageKeys($responseData);

            if ($status === 'success') {
                return [
                    'success' => true,
                    'outcome' => 'rolled_back',
                    'message' => 'Rollback completed successfully',
                    'bancard_key' => $keys[0] ?? null,
                    'raw_response' => $responseData,
                ];
            }

            // Un rechazo de Bancard trae "status": "error" y su lista "messages" (doc pág.
            // 53-54). Cualquier otro JSON — de un proxy, "status" numérico — no dice si se
            // reversó: es desconocido. Tratarlo como rechazo invita a reembolsar dos veces.
            if ($status !== 'error' || ! is_array($responseData['messages'] ?? null)) {
                return $this->unknownRollbackResult(
                    $shopProcessId,
                    'respuesta JSON que no es de Bancard',
                    $response->status(),
                    $this->bodySnippet($response->body()),
                );
            }

            $alreadyRolledBack = in_array('AlreadyRollbackedError', $keys, true);

            return [
                'success' => false,
                'outcome' => $alreadyRolledBack ? 'already_rolled_back' : 'rejected',
                'error' => $this->getErrorMessage($responseData),
                'bancard_key' => $alreadyRolledBack ? 'AlreadyRollbackedError' : ($keys[0] ?? null),
                'raw_response' => $responseData,
            ];

        } catch (BancardUnknownOutcomeException $e) {
            return $this->unknownRollbackResult($shopProcessId, 'la respuesta no es JSON', $e->httpStatus, $e->rawBody);
        } catch (ConnectionException|TransferException $e) {
            // Timeout o conexión cortada: el pedido pudo haber llegado a Bancard.
            return $this->unknownRollbackResult($shopProcessId, 'sin respuesta: '.$e->getMessage(), null, null);
        } catch (\Throwable $e) {
            return $this->unknownRollbackResult($shopProcessId, 'error inesperado: '.$e->getMessage(), null, null);
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
    public function processWebhook(array $payload, ?string $aliasToken = null): array
    {
        $operation = $payload['operation'] ?? [];

        if (empty($operation)) {
            throw new BancardException('Invalid webhook payload: missing operation');
        }

        $shopProcessId = (string) ($operation['shop_process_id'] ?? '');

        if (empty($shopProcessId)) {
            throw new BancardException('Invalid webhook payload: missing shop_process_id');
        }

        // Validate token — aceptamos ambas fórmulas (confirm/charge) porque Bancard
        // usa una sola URL de confirmación para single_buy y charge. El $aliasToken
        // (para la fórmula charge) lo provee el WebhookController desde la
        // transacción guardada, ya que Bancard no lo manda en el payload.
        if (!$this->validateConfirmationToken($operation, $aliasToken)) {
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
     * Valida el token de una confirmación aceptando CUALQUIERA de las dos
     * fórmulas: single_buy ("confirm") o charge/3DS ("charge" + alias_token).
     *
     * Bancard usa UNA sola "URL de confirmación" en el portal del comercio y por
     * ahí llegan AMBOS tipos de callback (single_buy y charge). Si un endpoint
     * valida una sola fórmula, el otro tipo se rechaza por "token inválido":
     * p.ej. una confirmación de single_buy APROBADA que cae en el handler de
     * charge se responde "rejected", el evento nunca se dispara y la orden queda
     * impaga pese al cobro real (y Bancard puede revertirla). Aceptar ambas
     * fórmulas hace el webhook robusto sin importar qué URL configure el portal.
     *
     * El $aliasToken del callback de charge NO viene en el payload (Bancard no lo
     * envía), así que el caller (WebhookController) lo recupera de la transacción
     * registrada al cobrar y lo pasa acá. Si no se provee, se intenta con el del
     * payload (por compatibilidad).
     */
    public function validateConfirmationToken(array $operation, ?string $aliasToken = null): bool
    {
        if ($this->matchesConfirmToken($operation)) {
            return true;
        }

        // Fórmula de charge/3DS: necesita el alias_token con el que se firmó.
        $aliasToken = $aliasToken ?? ($operation['alias_token'] ?? '');

        return $aliasToken !== '' && $this->validateChargeWebhookToken($operation, $aliasToken);
    }

    /**
     * Validate webhook token (fórmula single_buy "confirm").
     *
     * @deprecated Usar validateConfirmationToken(), que acepta ambas fórmulas
     *             (confirm/charge) porque Bancard usa una sola URL de confirmación.
     */
    protected function validateWebhookToken(array $operation): bool
    {
        if ($this->matchesConfirmToken($operation)) {
            return true;
        }

        Log::warning('Bancard webhook token validation failed', [
            'shop_process_id' => (string) ($operation['shop_process_id'] ?? ''),
            'received_token' => substr((string) ($operation['token'] ?? ''), 0, 10) . '...',
        ]);

        return false;
    }

    /**
     * Comparación pura (sin logging) del token contra la fórmula single_buy
     * "confirm": md5(private_key + shop_process_id + "confirm" + amount + currency).
     */
    protected function matchesConfirmToken(array $operation): bool
    {
        // Fail-closed: sin secreto no se puede autenticar. Sin este guard, una private
        // key vacía (comercio mal configurado, o env sin setear → (string) null = '')
        // haría el token forjable con datos públicos (md5(''+shop_process_id+...)).
        if ($this->privateKey === '') {
            return false;
        }

        $receivedToken = $operation['token'] ?? '';
        $shopProcessId = (string) ($operation['shop_process_id'] ?? '');
        $amount = $operation['amount'] ?? '';
        $currency = $operation['currency'] ?? 'PYG';

        $confirmToken = $this->generateToken([
            $this->privateKey,
            $shopProcessId,
            'confirm',
            $amount,
            $currency,
        ]);

        return $receivedToken !== '' && hash_equals($confirmToken, $receivedToken);
    }

    /**
     * Validate webhook token for charge operations (needs alias_token).
     */
    public function validateChargeWebhookToken(array $operation, string $aliasToken): bool
    {
        // Fail-closed: sin secreto no se puede autenticar (ver matchesConfirmToken).
        if ($this->privateKey === '') {
            return false;
        }

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
    protected function recordTransaction(Payable $payable, string $shopProcessId, ?string $processId, string $amount, string $currency, string $type, ?string $aliasToken = null): void
    {
        // Idempotencia: registrar el alias_token del charge SIEMPRE (aun con
        // persist_transactions=false), para poder validar el token del webhook de charge
        // sin la tabla de transacciones completa. Best-effort: nunca bloquea el cobro.
        if ($aliasToken !== null && $aliasToken !== '') {
            try {
                app(BancardIdempotencyStore::class)->rememberAliasToken($shopProcessId, $aliasToken);
            } catch (\Throwable $e) {
                // ignorado a propósito: el store es best-effort en el camino de cobro
            }
        }

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
                        // Guardado para validar el token del webhook de charge (que se
                        // firma con el alias_token y no viene en el payload del callback).
                        'alias_token' => $aliasToken,
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
        // un id numérico de 15 dígitos con entropía CSPRNG.
        //
        // CRÍTICO: el primer dígito NO puede ser 0. Bancard devuelve el
        // shop_process_id como NÚMERO JSON en el webhook de confirmación, lo que
        // descarta el cero inicial ("053708855743773" → 53708855743773). Con el
        // cero perdido, el token recalculado no coincide (Invalid token → un pago
        // aprobado se rechaza, con riesgo de rollback) y falla el lookup de la
        // transacción. Por eso el primer dígito es 1-9 (round-trip por número sin
        // pérdida; 15 dígitos < 2^53, dentro del rango seguro de enteros JS/JSON).
        return (string) random_int(1, 9)
            .substr((string) time(), -5)
            .str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
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
     * Agrega el shop_process_id como query param a una URL de retorno/cancelación.
     *
     * El paquete genera el shop_process_id, así que es quien debe inyectarlo en la
     * URL de retorno: en el retorno del browser (contexto cross-site del iframe /
     * redirect de Bancard) no viaja la cookie de sesión, por lo que el query param
     * es el único identificador de la transacción del que dispone el comercio.
     *
     * Usa el separador correcto (`?` o `&`) y es idempotente: si la URL ya trae
     * `shop_process_id=`, la devuelve sin cambios.
     */
    protected function appendShopProcessId(string $url, string $shopProcessId): string
    {
        if (str_contains($url, 'shop_process_id=')) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'shop_process_id='.urlencode($shopProcessId);
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
        // Solo textos: un "dsc" o "message" que no es string (respuesta rara) no debe
        // convertirse en un TypeError / "Array to string conversion".
        if (is_array($response['messages'] ?? null)) {
            $messages = array_map(function ($m) {
                if (is_string($m)) {
                    return $m;
                }
                if (is_array($m)) {
                    foreach (['dsc', 'key'] as $field) {
                        if (is_string($m[$field] ?? null) && $m[$field] !== '') {
                            return $m[$field];
                        }
                    }
                }

                return '';
            }, $response['messages']);

            $text = implode('. ', array_filter($messages));

            if ($text !== '') {
                return $text;
            }
        }

        return is_string($response['message'] ?? null) ? $response['message'] : 'Unknown Bancard error';
    }

    /**
     * Las claves estructuradas de `messages[].key` de una respuesta de Bancard (p.ej.
     * "AlreadyRollbackedError"), solo las que son texto, en orden.
     *
     * @return list<string>
     */
    protected function messageKeys(array $response): array
    {
        if (! is_array($response['messages'] ?? null)) {
            return [];
        }

        $keys = [];
        foreach ($response['messages'] as $message) {
            if (is_array($message) && is_string($message['key'] ?? null)) {
                $keys[] = $message['key'];
            }
        }

        return $keys;
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
     * Cliente HTTP para vPOS. Es el ÚNICO lugar donde se arma: todas las operaciones salen
     * por acá, con la misma versión de HTTP.
     */
    protected function http(int $timeout = 30): PendingRequest
    {
        return Http::timeout($timeout)->withOptions(['version' => $this->httpVersion()]);
    }

    /** Se avisa una sola vez por proceso que se cayó a HTTP/1.1. */
    private static bool $http2FallbackLogged = false;

    /**
     * Versión de HTTP para los pedidos a vPOS: bancard.http_version (default '2.0').
     *
     * Cloudflare, delante de vPOS, bloquea HTTP/1.1 con la huella TLS de OpenSSL 3.0
     * (Ubuntu 22/24, Forge); HTTP/2 pasa. Pero si el curl del servidor no soporta HTTP/2,
     * Guzzle NO cae solo a 1.1: lanza ConnectException antes de enviar (CurlFactory). Por
     * eso se chequea acá y, sin soporte, se usa '1.1'. Se devuelve como texto porque
     * Guzzle compara la versión como string ('2.0' / '1.1').
     */
    protected function httpVersion(): string
    {
        $wanted = (string) config('bancard.http_version', '2.0');

        if (! in_array($wanted, ['2', '2.0'], true)) {
            return '1.1';
        }

        if (! $this->curlSupportsHttp2()) {
            if (! self::$http2FallbackLogged) {
                self::$http2FallbackLogged = true;
                Log::warning('Bancard: el curl de este servidor no soporta HTTP/2; se usa HTTP/1.1 hacia vPOS. '
                    .'Cloudflare puede bloquear esos pedidos (403): conviene instalar curl con HTTP/2.');
            }

            return '1.1';
        }

        return '2.0';
    }

    /**
     * ¿El libcurl de PHP soporta HTTP/2? Sin la extensión curl, Guzzle usa el stream handler,
     * que tampoco soporta HTTP/2.
     */
    protected function curlSupportsHttp2(): bool
    {
        if (! function_exists('curl_version') || ! defined('CURL_VERSION_HTTP2')) {
            return false;
        }

        $info = curl_version();

        return is_array($info) && (($info['features'] ?? 0) & CURL_VERSION_HTTP2) === CURL_VERSION_HTTP2;
    }

    /**
     * Decodifica la respuesta de Bancard a array. Es el ÚNICO punto por donde pasa el cuerpo
     * de toda respuesta: `$response->json()` da null (o un escalar) cuando el cuerpo no es un
     * objeto JSON — una página de error HTML de un proxy, un cuerpo vacío — y pasar eso a
     * código tipado `array` era un TypeError que se escapaba del `catch (Exception)` como 500
     * (rollback en producción, 2026-09-29). Si no es un objeto JSON, lanza
     * BancardUnknownOutcomeException con el código HTTP y un fragmento del cuerpo.
     */
    protected function decodeResponse(string $endpoint, Response $response, ?string $shopProcessId = null): array
    {
        try {
            $data = $response->json();
        } catch (\Throwable) {
            $data = null;
        }

        if (! is_array($data)) {
            $rawBody = $this->bodySnippet($response->body());

            Log::warning('Bancard: la respuesta no es JSON; resultado desconocido', [
                'endpoint' => $endpoint,
                'http_status' => $response->status(),
                'shop_process_id' => $shopProcessId,
                'body' => $rawBody,
            ]);

            throw new BancardUnknownOutcomeException(
                "Bancard respondió algo que no es JSON en {$endpoint} (HTTP {$response->status()}): "
                .'no se sabe si la operación se procesó.',
                $endpoint,
                $response->status(),
                $rawBody,
                $shopProcessId,
            );
        }

        $this->logResponse($endpoint, $data);

        return $data;
    }

    /**
     * Fragmento legible y seguro de un cuerpo no JSON, para logs y para devolver al llamador:
     * sin etiquetas HTML, espacios colapsados, recortado, y con cualquier hash de 32 hex
     * (el formato de los tokens md5 del paquete) reemplazado, por si una página de error
     * repitiera el pedido enviado.
     */
    protected function bodySnippet(string $body, int $max = 500): string
    {
        // Un cuerpo que no es UTF-8 válido (p.ej. latin1) haría que preg_replace con /u
        // devuelva null y se pierda el diagnóstico: se convierte antes.
        if (! mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
        }

        $text = strip_tags($body);
        $text = preg_replace('/\b[a-f0-9]{32}\b/i', '[redactado]', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }

    /**
     * Resultado de rollbackPayment() cuando NO se sabe si Bancard reversó: success=false,
     * pero con outcome 'unknown' para que no se confunda con un rechazo explícito.
     */
    protected function unknownRollbackResult(string $shopProcessId, string $reason, ?int $httpStatus, ?string $rawBody): array
    {
        Log::error('Bancard rollback: resultado DESCONOCIDO', [
            'shop_process_id' => $shopProcessId,
            'http_status' => $httpStatus,
            'reason' => $reason,
        ]);

        return [
            'success' => false,
            'outcome' => 'unknown',
            'error' => 'No se pudo confirmar si Bancard anuló el pago ('.$reason.'). '
                .'Verificá el estado con getPaymentConfirmation() o en el portal de Bancard antes de reembolsar.',
            'http_status' => $httpStatus,
            'raw_body' => $rawBody,
        ];
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
