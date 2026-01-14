<?php

namespace Softlab180\Bancard\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Softlab180\Bancard\Facades\Bancard;
use Softlab180\Bancard\Models\SavedCard;

/**
 * Trait to add Bancard saved cards functionality to a model.
 *
 * Add this trait to your User model to enable saved card features:
 *
 *     use Softlab180\Bancard\Traits\HasBancardCards;
 *
 *     class User extends Authenticatable
 *     {
 *         use HasBancardCards;
 *     }
 */
trait HasBancardCards
{
    /**
     * Get all saved Bancard cards for this user.
     */
    public function bancardCards(): MorphMany
    {
        return $this->morphMany(SavedCard::class, 'user');
    }

    /**
     * Get the default Bancard card.
     */
    public function defaultBancardCard(): ?SavedCard
    {
        return $this->bancardCards()->where('is_default', true)->first()
            ?? $this->bancardCards()->first();
    }

    /**
     * Initiate card registration process.
     *
     * @param int $cardId A unique identifier for this card registration attempt
     * @param string|null $returnUrl URL to redirect after registration
     * @return array Contains process_id and redirect_url
     */
    public function registerBancardCard(
        int $cardId,
        ?string $returnUrl = null
    ): array {
        $phone = $this->phone ?? $this->phone_number ?? '';
        $email = $this->email ?? '';

        return Bancard::initiateCardRegistration(
            userId: $this->getKey(),
            cardId: $cardId,
            userPhone: $phone,
            userEmail: $email,
            returnUrl: $returnUrl
        );
    }

    /**
     * Get all registered cards from Bancard API.
     */
    public function getBancardCards(): array
    {
        return Bancard::getUserCards($this->getKey());
    }

    /**
     * Delete a saved card.
     */
    public function deleteBancardCard(string $aliasToken): array
    {
        // Delete from Bancard
        $result = Bancard::deleteCard($this->getKey(), $aliasToken);

        // Delete local record
        $this->bancardCards()->where('alias_token', $aliasToken)->delete();

        return $result;
    }

    /**
     * Charge the default card.
     *
     * @param \Softlab180\Bancard\Contracts\Payable $payable
     * @param int $numberOfPayments Number of installments (1 = single payment)
     * @param string|null $description Custom description
     * @param string|null $returnUrl Return URL for 3DS
     * @return array
     */
    public function chargeDefaultCard(
        $payable,
        int $numberOfPayments = 1,
        ?string $description = null,
        ?string $returnUrl = null
    ): array {
        $card = $this->defaultBancardCard();

        if (!$card) {
            throw new \RuntimeException('No hay tarjeta guardada para este usuario.');
        }

        return Bancard::chargeWithToken(
            payable: $payable,
            aliasToken: $card->alias_token,
            numberOfPayments: $numberOfPayments,
            description: $description,
            returnUrl: $returnUrl
        );
    }

    /**
     * Charge a specific saved card.
     */
    public function chargeBancardCard(
        SavedCard $card,
        $payable,
        int $numberOfPayments = 1,
        ?string $description = null,
        ?string $returnUrl = null
    ): array {
        return Bancard::chargeWithToken(
            payable: $payable,
            aliasToken: $card->alias_token,
            numberOfPayments: $numberOfPayments,
            description: $description,
            returnUrl: $returnUrl
        );
    }

    /**
     * Check if the user has any saved cards.
     */
    public function hasBancardCards(): bool
    {
        return $this->bancardCards()->exists();
    }

    /**
     * Get the count of saved cards.
     */
    public function bancardCardsCount(): int
    {
        return $this->bancardCards()->count();
    }
}
