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

    // Atributo id seteado para que la relación morphMany (que usa el atributo, no
    // getKey()) resuelva la clave del padre en un modelo no persistido.
    protected $attributes = ['id' => 1];

    public function getKey()
    {
        return 1;
    }

    public function getMorphClass(): string
    {
        return 'test-user';
    }
}
