<?php

namespace Softlab180\Bancard\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CardDeleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int|string $userId,
        public readonly string $aliasToken,
        public readonly array $response,
    ) {}

    /**
     * Check if the deletion was successful.
     */
    public function isSuccessful(): bool
    {
        return ($this->response['status'] ?? '') === 'success';
    }
}
