<?php

namespace Softlab180\Bancard\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Softlab180\Bancard\Models\SavedCard;

class CardRegistered
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int|string $userId,
        public readonly int $cardId,
        public readonly array $response,
        public readonly ?SavedCard $savedCard = null,
    ) {}

    /**
     * Get the alias token from the response.
     */
    public function getAliasToken(): ?string
    {
        return $this->response['alias_token'] ?? null;
    }

    /**
     * Get the masked card number.
     */
    public function getMaskedNumber(): ?string
    {
        return $this->response['card_masked_number'] ?? null;
    }

    /**
     * Get the card brand.
     */
    public function getCardBrand(): ?string
    {
        return $this->response['card_brand'] ?? null;
    }

    /**
     * Get the card type (debit/credit).
     */
    public function getCardType(): ?string
    {
        return $this->response['card_type'] ?? null;
    }

    /**
     * Get the expiration date.
     */
    public function getExpirationDate(): ?string
    {
        return $this->response['expiration_date'] ?? null;
    }
}
