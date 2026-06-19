<?php

namespace Softlab180\Bancard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Registro local de cada operación iniciada con Bancard (single_buy / charge).
 *
 * Sirve para idempotencia (deduplicar callbacks reenviados por vPOS) y para
 * conciliación posterior (get_confirmation / rollback). La clave es
 * `shop_process_id` (única).
 */
class BancardTransaction extends Model
{
    protected $table = 'bancard_transactions';

    protected $fillable = [
        'shop_process_id',
        'process_id',
        'type',
        'status',
        'amount',
        'currency',
        'payable_type',
        'payable_id',
        'authorization_number',
        'ticket_number',
        'last_payload',
        'processed_at',
    ];

    protected $casts = [
        'last_payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isProcessed(): bool
    {
        return $this->status !== 'pending';
    }
}
