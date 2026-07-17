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
use Softlab180\Bancard\Exceptions\ConfirmationUnavailableException;
use Softlab180\Bancard\Models\BancardTransaction;
use Softlab180\Bancard\Models\SavedCard;
use Softlab180\Bancard\Services\BancardVPOSService;
use Softlab180\Bancard\Tenancy\BancardTenantContext;
use Softlab180\Bancard\Tenancy\BancardTenantResolver;
use Softlab180\Bancard\Tenancy\GlobalTenantResolver;

class WebhookController extends Controller
{
    /**
     * Confirmación de pago (Single Buy). Bancard usa UNA sola "URL de confirmación",
     * así que por acá también puede llegar un charge; ambas rutas comparten handler.
     */
    public function handlePayment(Request $request): JsonResponse
    {
        return $this->handleConfirmation($request, 'Bancard payment webhook received');
    }

    /**
     * Confirmación de pago con token (charge / 3DS). Mismo handler que /payment:
     * el tenant se resuelve por shop_process_id y la fórmula (confirm/charge) se
     * acepta indistintamente.
     */
    public function handleChargeWithToken(Request $request): JsonResponse
    {
        return $this->handleConfirmation($request, 'Bancard charge with token webhook received');
    }

    /**
     * Handler común de confirmación (single_buy + charge), multi-tenant.
     *
     * Flujo: resolver el comercio dueño del shop_process_id → construir un service
     * PER-TENANT con SUS llaves (nunca el singleton global) → verificar el callback
     * (token o re-query) con esa instancia → idempotencia → despachar el evento con
     * el tenantRef. Fail-safe: cualquier duda (tenant no resuelto, re-query caída) se
     * acusa 200 sin procesar y se deja para reconciliar; nunca 500 por esos casos.
     */
    protected function handleConfirmation(Request $request, string $logLabel): JsonResponse
    {
        $payload = $request->all();

        $this->logReceived($logLabel, $payload);

        try {
            $operation = $payload['operation'] ?? [];
            $shopProcessId = (string) ($operation['shop_process_id'] ?? '');

            // Resolver el tenant. Fail-safe: null (desconocido) o excepción (DB caída)
            // → 200 sin procesar; vPOS no reintenta confiable y un no-200 le hace dar
            // la confirmación por perdida; la reconciliación la recupera.
            $context = $this->resolveTenant($shopProcessId);

            if ($context === null) {
                return response()->json(['status' => 'success', 'unresolved' => true]);
            }

            // Instancia PER-TENANT: valida el token / consulta con la llave del comercio.
            $service = BancardVPOSService::forContext($context);

            try {
                [$isPaid, $confirmation] = $this->verifyConfirmation($service, $payload, $operation, $shopProcessId);
            } catch (BancardException $e) {
                // Token inválido (ambas fórmulas): no es un callback genuino. 200 rejected.
                Log::warning('Bancard webhook rejected (invalid token)', [
                    'shop_process_id' => $shopProcessId,
                    'error' => $e->getMessage(),
                ]);

                return response()->json(['status' => 'rejected'], 200);
            } catch (ConfirmationUnavailableException $e) {
                // Modo requery: Bancard no disponible/timeout → 200 + pending, sin evento.
                Log::warning('Bancard webhook: re-query no disponible; se deja pending', [
                    'shop_process_id' => $shopProcessId,
                    'error' => $e->getMessage(),
                ]);

                return response()->json(['status' => 'success', 'pending' => true]);
            }

            // Idempotencia: un callback reenviado por vPOS se acusa 200 sin re-despachar.
            if (! $this->claimTransaction($shopProcessId, $isPaid, $confirmation)) {
                return response()->json(['status' => 'success', 'duplicate' => true]);
            }

            // El evento ya quedó reclamado (idempotencia): un listener SÍNCRONO que lanza
            // NO debe convertir esto en 500, porque perdería el ack (vPOS no reintenta) y
            // la re-entrega caería en 'duplicate' → el evento nunca se re-despacharía. Se
            // loguea y se acusa 200; la robustez del listener (idealmente encolado e
            // idempotente) es responsabilidad del consumidor.
            try {
                if ($isPaid) {
                    PaymentSucceeded::dispatch(
                        shopProcessId: $shopProcessId,
                        response: ['confirmation' => $confirmation],
                        authorizationNumber: $confirmation['authorization_number'] ?? null,
                        ticketNumber: $confirmation['ticket_number'] ?? null,
                        tenantRef: $context->tenantRef,
                    );
                } else {
                    PaymentFailed::dispatch(
                        shopProcessId: $shopProcessId,
                        response: ['confirmation' => $confirmation],
                        errorCode: $confirmation['response_code'] ?? null,
                        errorMessage: $confirmation['extended_response_description'] ?? $confirmation['response_description'] ?? null,
                        tenantRef: $context->tenantRef,
                    );
                }
            } catch (\Throwable $e) {
                Log::error('Bancard webhook: un listener falló tras despachar el evento', [
                    'shop_process_id' => $shopProcessId,
                    'error' => $e->getMessage(),
                ]);
            }

            // vPOS exige HTTP 200 (aprobado o rechazado son resultados de negocio válidos).
            return response()->json(['status' => 'success']);
        } catch (\Throwable $e) {
            // Error genuinamente inesperado: 500 para que se investigue.
            Log::error('Bancard webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Verifica que la confirmación es genuina y devuelve [is_paid, confirmación cruda].
     *
     * - Modo 'token' (default): valida la firma MD5 (fórmula confirm o charge) con la
     *   instancia PER-TENANT. Devuelve el `operation` CRUDO como confirmación (así el
     *   listener conserva campos como card_masked_number/card_brand del single_buy, que
     *   el parseo estructurado descartaría). Lanza BancardException si el token no valida.
     * - Modo 'requery': ignora el payload y re-consulta el estado autoritativo a Bancard
     *   (`getPaymentConfirmation`) bajo el tenant, con timeout corto. Zero-trust sobre el
     *   estado, no solo sobre la firma. Lanza ConfirmationUnavailableException si la
     *   consulta falla/timeoutea (→ el webhook acusa 200 + pending).
     *
     * @return array{0: bool, 1: array<string,mixed>}
     */
    protected function verifyConfirmation(BancardVPOSService $service, array $payload, array $operation, string $shopProcessId): array
    {
        if (config('bancard.webhook_verification', 'token') === 'requery') {
            // Timeout acotado: la re-query corre DENTRO del ack de <30s. Un 0/negativo
            // sería Http::timeout(0) = espera INDEFINIDA en Guzzle; lo forzamos positivo
            // y por debajo de 30s.
            $timeout = (int) config('bancard.webhook_requery_timeout', 8);
            $timeout = $timeout > 0 ? min($timeout, 25) : 8;

            $result = $service->getPaymentConfirmation($shopProcessId, $timeout);

            if (! ($result['success'] ?? false)) {
                throw new ConfirmationUnavailableException((string) ($result['error'] ?? 'Confirmation re-query unavailable'));
            }

            return [(bool) ($result['is_paid'] ?? false), $result['confirmation'] ?? []];
        }

        // Modo token: processWebhook valida con la instancia PER-TENANT y normaliza. Le
        // mergeamos el operation CRUDO para conservar campos que el parseo estructurado
        // descarta (p.ej. card_masked_number/card_brand del single_buy); el normalizado
        // gana (is_paid, additional_data decodificado, security_information, etc.), así el
        // evento mantiene su forma histórica para listeners single-tenant existentes.
        // El alias_token del charge no viaja en el payload: se recupera de la transacción.
        $aliasToken = $operation['alias_token'] ?? $this->lookupAliasToken($shopProcessId);

        $normalized = $service->processWebhook($payload, $aliasToken);

        return [(bool) ($normalized['is_paid'] ?? false), array_merge($operation, $normalized)];
    }

    /**
     * Resuelve el comercio dueño del shop_process_id vía el resolver configurado.
     * Fail-safe: si el resolver lanza (p.ej. DB caída) → null (→ el webhook acusa 200
     * sin procesar y no despacha evento); nunca propaga un 500 por esto.
     */
    protected function resolveTenant(string $shopProcessId): ?BancardTenantContext
    {
        if ($shopProcessId === '') {
            return null;
        }

        try {
            return $this->tenantResolver()->resolveByShopProcessId($shopProcessId);
        } catch (\Throwable $e) {
            Log::error('Bancard: tenant resolver falló; se acusa 200 sin procesar', [
                'shop_process_id' => $shopProcessId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * El resolver configurado (`bancard.tenant_resolver`: class-string o instancia), o
     * GlobalTenantResolver (llaves globales) si no hay ninguno → single-tenant intacto.
     */
    protected function tenantResolver(): BancardTenantResolver
    {
        $resolver = config('bancard.tenant_resolver');

        if ($resolver instanceof BancardTenantResolver) {
            return $resolver;
        }

        if (is_string($resolver) && $resolver !== '') {
            return app($resolver);
        }

        return new GlobalTenantResolver();
    }

    /**
     * Handle Bancard webhook for card registration (catastro).
     *
     * NOTA: el catastro NO tiene webhook estándar servidor-a-servidor (se completa con
     * el iframe + syncBancardCards()). Este handler se mantiene por compatibilidad con
     * integraciones no estándar; el soporte per-tenant del catastro es Front 1b.
     */
    public function handleCardRegistration(Request $request): JsonResponse
    {
        $payload = $request->all();

        $this->logReceived('Bancard card registration webhook received', $payload);

        // SEGURIDAD (deshabilitado por default): el catastro NO tiene webhook estándar
        // servidor-a-servidor, y este callback NO está autenticado (la fórmula del token
        // de cards/new no está en la spec). Procesarlo permitiría que un POST no
        // autenticado escriba una tarjeta (asociando un alias_token arbitrario a un
        // user_id) y dispare CardRegistered. Por eso, por default, NO se procesa: se
        // acusa 200 y se ignora. El flujo recomendado es syncBancardCards() (pull
        // autenticado). Un integrador con una integración no estándar debe habilitarlo
        // explícitamente (BANCARD_CARD_REGISTRATION_WEBHOOK=true) Y protegerlo con su
        // propia autenticación (IP allow-list / verificación de firma) vía middleware.
        if (! config('bancard.card_registration_webhook_enabled', false)) {
            Log::info('Bancard card registration webhook deshabilitado por default; usá syncBancardCards()', [
                'shop_process_id' => $payload['operation']['shop_process_id'] ?? null,
            ]);

            return response()->json(['status' => 'success', 'disabled' => true]);
        }

        try {
            $operation = $payload['operation'] ?? [];

            if (($operation['response_code'] ?? '') === '00') {
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
     * Recupera el alias_token de la transacción registrada al cobrar (charge). El
     * token del webhook de charge se firma con el alias_token, que Bancard NO manda
     * en el payload; sin persistencia no se puede validar (devuelve null → el charge
     * cae en la rama de token inválido). Un single_buy no tiene alias (devuelve null).
     */
    protected function lookupAliasToken(string $shopProcessId): ?string
    {
        if ($shopProcessId === '' || ! config('bancard.persist_transactions', true)) {
            return null;
        }

        try {
            return BancardTransaction::where('shop_process_id', $shopProcessId)->value('alias_token');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Reclama atómicamente el shop_process_id para idempotencia. Devuelve true si
     * es la primera vez que se procesa, false si es un callback duplicado/reenvío.
     * Si la persistencia está desactivada o falla, procesa igual (sin dedup) — el
     * consumidor con idempotencia propia la enchufa en su listener.
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
     * NOTA: soporta un único modelo de usuario (config bancard.user_model). El scoping
     * per-tenant de tarjetas es Front 1b.
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
