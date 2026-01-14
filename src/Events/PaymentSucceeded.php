<?php

namespace Softlab180\Bancard\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentSucceeded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $shopProcessId,
        public readonly array $response,
        public readonly ?string $authorizationNumber = null,
        public readonly ?string $ticketNumber = null,
    ) {}

    /**
     * Get the amount from the response.
     */
    public function getAmount(): ?float
    {
        return $this->response['confirmation']['amount'] ?? null;
    }

    /**
     * Get the currency from the response.
     */
    public function getCurrency(): ?string
    {
        return $this->response['confirmation']['currency'] ?? null;
    }

    /**
     * Get the response code.
     */
    public function getResponseCode(): ?string
    {
        return $this->response['confirmation']['response_code'] ?? null;
    }

    /**
     * Get the response description.
     */
    public function getResponseDescription(): ?string
    {
        return $this->response['confirmation']['response_description'] ?? null;
    }
}
