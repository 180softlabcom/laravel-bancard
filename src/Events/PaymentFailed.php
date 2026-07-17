<?php

namespace Softlab180\Bancard\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $shopProcessId,
        public readonly array $response,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        // Referencia del comercio (multi-tenant): el listener sabe a qué tenant pertenece.
        // Null en single-tenant. Se propaga desde el BancardTenantContext resuelto.
        public readonly mixed $tenantRef = null,
    ) {}

    /**
     * Get the Bancard messages from the response.
     */
    public function getMessages(): array
    {
        return $this->response['messages'] ?? [];
    }

    /**
     * Get the first error key.
     */
    public function getErrorKey(): ?string
    {
        $messages = $this->getMessages();
        return $messages[0]['key'] ?? null;
    }

    /**
     * Check if the error is due to insufficient funds.
     */
    public function isInsufficientFunds(): bool
    {
        $key = $this->getErrorKey();
        return $key && str_contains($key, 'InsufficientFunds');
    }

    /**
     * Check if the error is due to card declined.
     */
    public function isCardDeclined(): bool
    {
        $key = $this->getErrorKey();
        return $key && (str_contains($key, 'CardDeclined') || str_contains($key, 'Declined'));
    }

    /**
     * Check if the error is due to expired card.
     */
    public function isExpiredCard(): bool
    {
        $key = $this->getErrorKey();
        return $key && str_contains($key, 'ExpiredCard');
    }
}
