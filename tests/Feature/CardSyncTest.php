<?php

namespace Softlab180\Bancard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Softlab180\Bancard\Tests\Fixtures\TestUser;
use Softlab180\Bancard\Tests\TestCase;

/**
 * Flujo de catastro DOCUMENTADO: tras el iframe (add_new_card_success) el comercio
 * llama users_cards y persiste el alias_token. syncBancardCards() hace eso.
 */
class CardSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_persiste_las_tarjetas_de_users_cards_con_el_morph_correcto(): void
    {
        Http::fake([
            '*/vpos/api/0.3/users/*/cards' => Http::response([
                'status' => 'success',
                'cards' => [
                    [
                        'alias_token' => 'alias_abc',
                        'card_masked_number' => '5418********0014',
                        'card_brand' => 'MasterCard',
                        'card_type' => 'credit',
                        'expiration_date' => '08/28',
                        'card_id' => 1,
                    ],
                ],
            ], 200),
        ]);

        $cards = (new TestUser())->syncBancardCards();

        $this->assertCount(1, $cards);
        $this->assertDatabaseHas('bancard_saved_cards', [
            'user_type' => 'test-user',
            'user_id' => 1,
            'alias_token' => 'alias_abc',
            'card_brand' => 'MasterCard',
        ]);
    }
}
