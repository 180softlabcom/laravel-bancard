<?php

namespace Softlab180\Bancard\Contracts;

/**
 * Garantía de idempotencia del webhook, INDEPENDIENTE de persist_transactions.
 *
 * El token del webhook autentica el ORIGEN pero no firma response/response_code; la
 * protección contra "marcar pagado indebidamente" (reenvío de vPOS o replay de un
 * callback capturado) es deduplicar el shop_process_id. Antes ese dedup vivía en
 * bancard_transactions (solo con persist_transactions=true), así que un consumidor con
 * persist=false quedaba sin guard salvo que su listener fuera idempotente. Este store
 * hace la dedup SIEMPRE, sin obligar a adoptar la tabla de transacciones completa.
 *
 * Es inyectable (bancard.idempotency_store): un consumidor puede enchufar Redis, su
 * propia tabla, etc. El default es Eloquent (bancard_processed_callbacks).
 */
interface BancardIdempotencyStore
{
    /**
     * Reclama ATÓMICAMENTE el procesamiento de un shop_process_id. Devuelve true la
     * PRIMERA vez (seguí y despachá el evento) y false en cualquier reenvío (duplicado:
     * no re-despaches). Dos callbacks concurrentes con el mismo id: exactamente uno
     * obtiene true.
     */
    public function claim(string $shopProcessId): bool;

    /**
     * Registra, al COBRAR (charge), el alias_token con el que Bancard firmará el token
     * del webhook (no lo manda en el callback). Permite validar el charge en el webhook
     * sin depender de persist_transactions. Best-effort: nunca bloquea el cobro.
     */
    public function rememberAliasToken(string $shopProcessId, string $aliasToken): void;

    /**
     * Recupera el alias_token registrado al cobrar (o null si no hay).
     */
    public function aliasTokenFor(string $shopProcessId): ?string;
}
