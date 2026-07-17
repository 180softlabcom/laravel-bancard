<?php

namespace Softlab180\Bancard\Idempotency;

use Illuminate\Support\Facades\Log;
use Softlab180\Bancard\Contracts\BancardIdempotencyStore;
use Softlab180\Bancard\Models\ProcessedCallback;

/**
 * Store de idempotencia por defecto, respaldado por la tabla bancard_processed_callbacks.
 * Portable (sqlite/mysql/pgsql): el claim es atómico vía insertOrIgnore + UPDATE
 * condicional (whereNull) — la DB serializa el UPDATE, así solo el primer callback gana.
 */
class EloquentIdempotencyStore implements BancardIdempotencyStore
{
    public function claim(string $shopProcessId): bool
    {
        if ($shopProcessId === '') {
            return true; // sin id no se puede deduplicar; fail-open (se procesa igual)
        }

        try {
            // Aseguramos que exista la fila (puede haberla creado rememberAliasToken al
            // cobrar). insertOrIgnore es atómico y no pisa la fila si ya está.
            ProcessedCallback::insertOrIgnore([
                'shop_process_id' => $shopProcessId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Claim atómico: solo el PRIMER callback pasa claimed_at de null a ahora. Dos
            // callbacks concurrentes con el mismo id → la DB serializa el UPDATE whereNull,
            // exactamente uno obtiene affected=1.
            $claimed = ProcessedCallback::where('shop_process_id', $shopProcessId)
                ->whereNull('claimed_at')
                ->update(['claimed_at' => now(), 'updated_at' => now()]);

            return $claimed > 0;
        } catch (\Throwable $e) {
            // Fail-open: si el store falla (DB caída), no perdemos el pago; se procesa sin
            // dedup (mismo criterio fail-safe que el resto del webhook).
            Log::warning('Bancard: claim de idempotencia falló; se procesa sin dedup', [
                'shop_process_id' => $shopProcessId,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    public function rememberAliasToken(string $shopProcessId, string $aliasToken): void
    {
        if ($shopProcessId === '' || $aliasToken === '') {
            return;
        }

        try {
            ProcessedCallback::updateOrCreate(
                ['shop_process_id' => $shopProcessId],
                ['alias_token' => $aliasToken]
            );
        } catch (\Throwable $e) {
            Log::warning('Bancard: no se pudo registrar el alias_token de idempotencia', [
                'shop_process_id' => $shopProcessId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function aliasTokenFor(string $shopProcessId): ?string
    {
        if ($shopProcessId === '') {
            return null;
        }

        try {
            $alias = ProcessedCallback::where('shop_process_id', $shopProcessId)->value('alias_token');

            return ($alias !== null && $alias !== '') ? (string) $alias : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
