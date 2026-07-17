<?php

namespace Softlab180\Bancard\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Softlab180\Bancard\Facades\Bancard;
use Softlab180\Bancard\Models\SavedCard;
use Softlab180\Bancard\Services\BancardVPOSService;
use Softlab180\Bancard\Tenancy\BancardTenantContext;

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
 *
 * Multi-tenant: el catastro/charge es iniciado por el consumidor (que ya conoce el
 * comercio), así que cada método acepta un `?BancardTenantContext $tenant` explícito.
 * Con $tenant: opera contra las llaves de ESE comercio y scopea las tarjetas por la
 * columna `bancard.saved_cards_tenant_column`. Sin $tenant (null): single-tenant, usa
 * las llaves globales y no scopea — comportamiento idéntico al anterior.
 */
trait HasBancardCards
{
    /**
     * Get all saved Bancard cards for this user (sin scope de tenant).
     */
    public function bancardCards(): MorphMany
    {
        return $this->morphMany(SavedCard::class, 'user');
    }

    /**
     * Get the default Bancard card (scopeado por tenant si se provee).
     */
    public function defaultBancardCard(?BancardTenantContext $tenant = null): ?SavedCard
    {
        return $this->bancardCardsQuery($tenant)->where('is_default', true)->first()
            ?? $this->bancardCardsQuery($tenant)->first();
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
        ?string $returnUrl = null,
        ?BancardTenantContext $tenant = null
    ): array {
        $phone = $this->phone ?? $this->phone_number ?? '';
        $email = $this->email ?? '';

        return $this->bancardService($tenant)->initiateCardRegistration(
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
    public function getBancardCards(?BancardTenantContext $tenant = null): array
    {
        return $this->bancardService($tenant)->getUserCards($this->getKey());
    }

    /**
     * Sincroniza las tarjetas catastradas desde Bancard hacia la base local.
     *
     * Este es el flujo de catastro DOCUMENTADO por Bancard: tras el éxito del
     * iframe (`Bancard.Cards.createForm` → mensaje `add_new_card_success`), el
     * comercio invoca `users_cards` para obtener el `alias_token` y persistir la
     * tarjeta. No depende de un webhook (Bancard no envía callback de catastro).
     *
     * Usa getMorphClass()/getKey() para respetar morph maps y soportar múltiples
     * modelos de usuario. Con $tenant, consulta con las llaves de ese comercio y
     * persiste el tenantRef en la columna de scoping.
     *
     * @return \Illuminate\Support\Collection<int, SavedCard>
     */
    public function syncBancardCards(?BancardTenantContext $tenant = null): \Illuminate\Support\Collection
    {
        $result = $this->bancardService($tenant)->getUserCards($this->getKey());

        $tenantColumn = $this->bancardTenantColumn();
        $tenantRef = $tenant?->tenantRef;

        return collect($result['cards'] ?? [])
            ->filter(fn ($card) => ! empty($card['alias_token']))
            ->map(function (array $card) use ($tenantColumn, $tenantRef) {
                $model = SavedCard::firstOrNew([
                    'user_type' => $this->getMorphClass(),
                    'user_id' => $this->getKey(),
                    'alias_token' => $card['alias_token'],
                ]);

                $model->fill([
                    'card_masked_number' => $card['card_masked_number'] ?? null,
                    'card_brand' => $card['card_brand'] ?? null,
                    'card_type' => $card['card_type'] ?? null,
                    'expiration_date' => $card['expiration_date'] ?? null,
                    'card_id' => $card['card_id'] ?? null,
                ]);

                // Set directo (bypassa $fillable): soporta una columna de tenant configurable.
                if ($tenantRef !== null) {
                    $model->setAttribute($tenantColumn, $tenantRef);
                }

                $model->save();

                return $model;
            })
            ->values();
    }

    /**
     * Delete a saved card.
     */
    public function deleteBancardCard(string $aliasToken, ?BancardTenantContext $tenant = null): array
    {
        // Delete from Bancard (con las llaves del comercio si se provee tenant)
        $result = $this->bancardService($tenant)->deleteCard($this->getKey(), $aliasToken);

        // Delete local record (scopeado por tenant)
        $this->bancardCardsQuery($tenant)->where('alias_token', $aliasToken)->delete();

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
        ?string $returnUrl = null,
        ?BancardTenantContext $tenant = null
    ): array {
        $card = $this->defaultBancardCard($tenant);

        if (!$card) {
            throw new \RuntimeException('No hay tarjeta guardada para este usuario.');
        }

        return $this->bancardService($tenant)->chargeWithToken(
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
        ?string $returnUrl = null,
        ?BancardTenantContext $tenant = null
    ): array {
        return $this->bancardService($tenant)->chargeWithToken(
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
    public function hasBancardCards(?BancardTenantContext $tenant = null): bool
    {
        return $this->bancardCardsQuery($tenant)->exists();
    }

    /**
     * Get the count of saved cards.
     */
    public function bancardCardsCount(?BancardTenantContext $tenant = null): int
    {
        return $this->bancardCardsQuery($tenant)->count();
    }

    /**
     * Service Bancard a usar: per-tenant si se provee el context (sus llaves), o el
     * singleton global (single-tenant) si no.
     */
    protected function bancardService(?BancardTenantContext $tenant): BancardVPOSService
    {
        return $tenant !== null
            ? BancardVPOSService::forContext($tenant)
            : app(BancardVPOSService::class);
    }

    /**
     * Columna de scoping por comercio (configurable).
     */
    protected function bancardTenantColumn(): string
    {
        return config('bancard.saved_cards_tenant_column', 'tenant_ref');
    }

    /**
     * Query de tarjetas del usuario, scopeada por tenant si se provee.
     */
    protected function bancardCardsQuery(?BancardTenantContext $tenant): MorphMany
    {
        $query = $this->bancardCards();

        if ($tenant !== null) {
            // Usa el scope del modelo (misma resolución de columna que setAsDefault).
            $query->forTenant($tenant->tenantRef);
        }

        return $query;
    }
}
