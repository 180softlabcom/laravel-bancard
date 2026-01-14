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
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'alias_token',
    ];

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

        // expiration_date format: MM/YY or MMYY
        $expDate = str_replace('/', '', $this->expiration_date);
        $month = (int) substr($expDate, 0, 2);
        $year = (int) ('20' . substr($expDate, 2, 2));

        $expiry = \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth();

        return $expiry->isPast();
    }

    /**
     * Set this card as the default.
     */
    public function setAsDefault(): void
    {
        // Remove default from other cards
        static::where('user_id', $this->user_id)
            ->where('user_type', $this->user_type)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        // Set this card as default
        $this->update(['is_default' => true]);
    }
}
