<?php

namespace Softlab180\Bancard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Softlab180\Bancard\Contracts\BancardIdempotencyStore;
use Softlab180\Bancard\Events\PaymentFailed;
use Softlab180\Bancard\Events\PaymentSucceeded;
use Softlab180\Bancard\Exceptions\BancardException;
use Softlab180\Bancard\Exceptions\ConfirmationUnavailableException;
use Softlab180\Bancard\Models\BancardTransaction;
use Softlab180\Bancard\Services\BancardVPOSService;
use Softlab180\Bancard\Tenancy\BancardTenantContext;
use Softlab180\Bancard\Tenancy\BancardTenantResolver;
use Softlab180\Bancard\Tenancy\GlobalTenantResolver;

class WebhookController extends Controller
{
    /**
     * Webhook ÚNICO de confirmación (single_buy + charge/3DS), multi-tenant.
     *
     * Bancard usa una sola "URL de confirmación" (portal de comercios) y por ahí llegan
     * AMBOS tipos de callback; no hay webhooks separados por tipo. Flujo: resolver el
     * comercio dueño del shop_process_id → construir un service PER-TENANT con SUS llaves
     * (nunca el singleton global) → verificar el callback (token o re-query) con esa
     * instancia → idempotencia → despachar el evento con el tenantRef. Fail-safe:
     * cualquier duda (tenant no resuelto, re-query caída) se acusa 200 sin procesar y se
     * deja para reconciliar; nunca 500 por esos casos.
     */
    public function handleConfirmation(Request $request): JsonResponse
    {
        $payload = $request->all();

        $this->logReceived('Bancard confirmation webhook received', $payload);

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

            // Idempotencia SIEMPRE activa (independiente de persist_transactions): un
            // callback reenviado por vPOS —o un replay— se acusa 200 sin re-despachar.
            // Cierra H2: el consumidor ya no depende de la idempotencia de su listener.
            if (! $this->idempotencyStore()->claim($shopProcessId)) {
                return response()->json(['status' => 'success', 'duplicate' => true]);
            }

            // Registro de negocio completo (opcional, persist_transactions=true): estado
            // final + payload para conciliación. NO es el gate de dedup (de eso ya se
            // encargó el store) — solo bookkeeping.
            $this->recordOutcome($shopProcessId, $isPaid, $confirmation);

            // El evento ya quedó reclamado (idempotencia): un listener SÍNCRONO que lanza
            // NO debe convertir esto en 500, porque perdería el ack (vPOS no reintenta) y
            // la re-entrega caería en 'duplicate' → el evento nunca se re-despacharía. Se
            // loguea y se acusa 200; la robustez del listener (idealmente encolado e
            // idempotente) es responsabilidad del consumidor.
            try {
                // Args POSICIONALES, no nombrados: el trait Dispatchable de Laravel ≤12
                // (incluido v12.39.0) declara `dispatch()` sin parámetros y lee los args con
                // func_get_args() → un arg nombrado lanza "Unknown named parameter" en tiempo
                // de llamada (Laravel 13 lo hizo variádico y por eso los tests en testbench 13
                // no lo detectaban). Posicional funciona en TODO el rango soportado (^9..^13).
                if ($isPaid) {
                    PaymentSucceeded::dispatch(
                        $shopProcessId,
                        ['confirmation' => $confirmation],
                        $confirmation['authorization_number'] ?? null,
                        $confirmation['ticket_number'] ?? null,
                        $context->tenantRef,
                    );
                } else {
                    PaymentFailed::dispatch(
                        $shopProcessId,
                        ['confirmation' => $confirmation],
                        $confirmation['response_code'] ?? null,
                        $confirmation['extended_response_description'] ?? $confirmation['response_description'] ?? null,
                        $context->tenantRef,
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
                'payload' => $this->maskedPayload($payload),
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
     * Loguea la recepción del webhook. El payload completo solo se loguea si
     * `bancard.webhook.log_payloads` está activo (BANCARD_LOG_WEBHOOKS); si no,
     * loguea únicamente el shop_process_id (higiene en producción).
     */
    protected function logReceived(string $label, array $payload): void
    {
        if (config('bancard.webhook.log_payloads', true)) {
            Log::info($label, ['payload' => $this->maskedPayload($payload)]);
        } else {
            Log::info($label, ['shop_process_id' => $payload['operation']['shop_process_id'] ?? null]);
        }
    }

    /**
     * Devuelve una copia del payload con operation.token ENMASCARADO. El token del
     * webhook (md5(private_key + shop_process_id + "confirm"/"charge" + amount + currency
     * [+ alias_token])) es una credencial reutilizable por transacción: no depende del
     * resultado, así que quien lo lea de los logs podría forjar un callback "pagado"
     * válido. Nunca loguearlo en claro (igual que logRequest en el saliente).
     */
    protected function maskedPayload(array $payload): array
    {
        if (isset($payload['operation']['token']) && is_string($payload['operation']['token'])) {
            $payload['operation']['token'] = substr($payload['operation']['token'], 0, 10).'...';
        }

        return $payload;
    }

    /**
     * Recupera el alias_token con el que se firma el token del webhook de charge (Bancard
     * NO lo manda en el payload). Lo guarda el idempotency_store al cobrar, así que se
     * valida el charge SIN depender de persist_transactions. Fallback: transacciones
     * viejas (previas al store) lo tienen en bancard_transactions (persist=true). Un
     * single_buy no tiene alias → null (cae en la fórmula "confirm").
     */
    protected function lookupAliasToken(string $shopProcessId): ?string
    {
        if ($shopProcessId === '') {
            return null;
        }

        $alias = $this->idempotencyStore()->aliasTokenFor($shopProcessId);

        if ($alias !== null) {
            return $alias;
        }

        // Compat: charges registrados antes del store guardan el alias en bancard_transactions.
        if (config('bancard.persist_transactions', true)) {
            try {
                return BancardTransaction::where('shop_process_id', $shopProcessId)->value('alias_token');
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Registra el RESULTADO del pago en bancard_transactions (solo si persist=true). Es
     * bookkeeping/conciliación para el consumidor que optó por la tabla completa; la
     * idempotencia ya la garantizó el idempotency_store, así que acá no hay gate. Un
     * charge crea la fila 'pending' al cobrar (updateOrCreate), un single_buy quizás no
     * exista → se crea con el estado final. Best-effort: nunca rompe el ack del webhook.
     */
    protected function recordOutcome(string $shopProcessId, bool $isPaid, array $payload): void
    {
        if ($shopProcessId === '' || ! config('bancard.persist_transactions', true)) {
            return;
        }

        try {
            BancardTransaction::updateOrCreate(
                ['shop_process_id' => $shopProcessId],
                [
                    'status' => $isPaid ? 'paid' : 'failed',
                    'authorization_number' => $payload['authorization_number'] ?? null,
                    'ticket_number' => $payload['ticket_number'] ?? null,
                    'last_payload' => json_encode($payload),
                    'processed_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Bancard: no se pudo registrar el resultado de la transacción', [
                'shop_process_id' => $shopProcessId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Store de idempotencia del webhook (dedup + alias_token del charge), resuelto del
     * contenedor (bancard.idempotency_store; default Eloquent).
     */
    protected function idempotencyStore(): BancardIdempotencyStore
    {
        return app(BancardIdempotencyStore::class);
    }
}
