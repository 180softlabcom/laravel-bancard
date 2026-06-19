<?php

namespace Softlab180\Bancard\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Softlab180\Bancard\Traits\HasBancardCards;

/**
 * Modelo de prueba para el flujo de catastro (no se persiste; solo provee
 * getKey()/getMorphClass() para que SavedCard se asocie polimórficamente).
 */
class TestUser extends Model
{
    use HasBancardCards;

    public function getKey()
    {
        return 1;
    }

    public function getMorphClass(): string
    {
        return 'test-user';
    }
}
