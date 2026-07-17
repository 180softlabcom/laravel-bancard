<?php

namespace Softlab180\Bancard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SavedCard extends Model
{
    protected $table = 'bancard_saved_cards';

    protected $fillable = [
        'user_type',
        'user_id',
        'alias_token',
        'card_masked_number',
        'card_brand',
        'card_type',
        'expiration_date',
        'card_id',
        'is_default',
        'metadata',
        'tenant_ref',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'alias_token',
    ];

    public function __construct(array $attributes = [])
    {
        // La columna de scoping por comercio es CONFIGURABLE (bancard.saved_cards_tenant_column).
        // El default 'tenant_ref' ya está en $fillable, pero un consumidor que reutiliza otra
        // (p.ej. 'commerce_id') la perdería en el mass-assignment (create()/fill()) si no la
        // agregamos acá → tarjeta guardada con tenant NULL = fuga cross-tenant. Se agrega
        // dinámicamente para que el mass-assignment respete la columna real.
        $column = config('bancard.saved_cards_tenant_column', 'tenant_ref');

        if (is_string($column) && $column !== '' && ! in_array($column, $this->fillable, true)) {
            $this->fillable[] = $column;
        }

        parent::__construct($attributes);
    }

    /**
     * Get the parent user model (polymorphic).
     */
    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to find cards for a specific user.
     */
    public function scopeForUser($query, $userId, ?string $userType = null)
    {
        $query->where('user_id', $userId);

        if ($userType) {
            $query->where('user_type', $userType);
        }

        return $query;
    }

    /**
     * Scope to get the default card.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope por comercio (multi-tenant). Usa la columna configurada en
     * bancard.saved_cards_tenant_column (default 'tenant_ref').
     */
    public function scopeForTenant($query, $tenantRef, ?string $column = null)
    {
        return $query->where($column ?? config('bancard.saved_cards_tenant_column', 'tenant_ref'), $tenantRef);
    }

    /**
     * Get the masked card number in display format.
     */
    public function getDisplayNumberAttribute(): string
    {
        return $this->card_masked_number ?? '**** **** **** ****';
    }

    /**
     * Get the card brand icon name.
     */
    public function getBrandIconAttribute(): string
    {
        return match (strtolower($this->card_brand ?? '')) {
            'visa' => 'visa',
            'mastercard', 'master' => 'mastercard',
            'amex', 'american express' => 'amex',
            'diners', 'diners club' => 'diners',
            default => 'credit-card',
        };
    }

    /**
     * Check if the card is expired.
     */
    public function isExpired(): bool
    {
        if (!$this->expiration_date) {
            return false;
        }

        // expiration_date format: M/YY, MM/YY, MYY, or MMYY
        if (str_contains($this->expiration_date, '/')) {
            // Format: M/YY or MM/YY
            [$month, $year] = explode('/', $this->expiration_date);
            $month = (int) $month;
            $year = (int) ('20' . $year);
        } else {
            // Format: MYY or MMYY (assume last 2 chars are year)
            $expDate = $this->expiration_date;
            $year = (int) ('20' . substr($expDate, -2));
            $month = (int) substr($expDate, 0, -2);
        }

        $expiry = \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth();

        return $expiry->isPast();
    }

    /**
     * Set this card as the default.
     */
    public function setAsDefault(): void
    {
        // Desmarcar las OTRAS tarjetas del usuario — scopeado por comercio (multi-tenant):
        // elegir la default en un comercio NO debe tocar la default de otro comercio.
        $column = config('bancard.saved_cards_tenant_column', 'tenant_ref');
        $tenantValue = $this->getAttribute($column);

        $query = static::where('user_id', $this->user_id)
            ->where('user_type', $this->user_type)
            ->where('id', '!=', $this->id);

        // single-tenant: tenant_ref es null en todas → desmarca dentro de ese scope null.
        $tenantValue === null
            ? $query->whereNull($column)
            : $query->where($column, $tenantValue);

        $query->update(['is_default' => false]);

        // Marcar esta como default
        $this->update(['is_default' => true]);
    }
}
