<?php

namespace Softlab180\Bancard\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro liviano de idempotencia del webhook (una fila por shop_process_id). NO es
 * el registro de negocio (esa es BancardTransaction, opt-in vía persist_transactions):
 * acá solo vive lo mínimo para deduplicar callbacks y recuperar el alias_token del
 * charge. Ver EloquentIdempotencyStore / BancardIdempotencyStore.
 */
class ProcessedCallback extends Model
{
    protected $table = 'bancard_processed_callbacks';

    protected $fillable = [
        'shop_process_id',
        'alias_token',
        'claimed_at',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
    ];

    // El alias_token es una credencial (firma el token del webhook de charge): no exponerlo.
    protected $hidden = [
        'alias_token',
    ];
}
