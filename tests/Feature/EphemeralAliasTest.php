<?php

namespace Softlab180\Bancard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Softlab180\Bancard\Models\SavedCard;
use Softlab180\Bancard\Tests\Fixtures\TestUser;
use Softlab180\Bancard\Tests\TestCase;

/**
 * El alias_token de Bancard es EFÍMERO: "alias token temporal", con validez para UNA sola
 * operación y TTL del orden de minutos (doc eCommerce Bancard, pág. 35). Por eso el paquete
 * NUNCA reusa el alias guardado: antes de cada charge/delete pide uno fresco con users_cards
 * y matchea la tarjeta por su identidad estable (card_id). El guardado es solo referencia.
 */
class EphemeralAliasTest extends TestCase
{
    use RefreshDatabase;

    public function test_charge_pide_un_alias_fresco_y_no_usa_el_guardado(): void
    {
        config(['bancard.persist_transactions' => false]);

        SavedCard::create([
            'user_type' => 'test-user', 'user_id' => 1,
            'alias_token' => 'alias_viejo', 'card_id' => '3',
            'card_masked_number' => '5200********0007', 'expiration_date' => '05/30',
            'is_default' => true,
        ]);

        Http::fake([
            '*/vpos/api/0.3/users/*/cards' => Http::response(['status' => 'success', 'cards' => [
                ['alias_token' => 'alias_fresco', 'card_id' => 3, 'card_masked_number' => '5200********0007', 'expiration_date' => '05/30'],
            ]]),
            '*/vpos/api/0.3/charge' => Http::response(['confirmation' => ['response' => 'S', 'response_code' => '00']]),
        ]);

        (new TestUser())->chargeDefaultCard(new EphemeralPayable());

        // El charge se hizo con el alias FRESCO (no 'alias_viejo').
        Http::assertSent(fn ($r) => str_contains($r->url(), '/charge')
            && ($r->data()['operation']['alias_token'] ?? null) === 'alias_fresco');
    }

    public function test_delete_refresca_el_alias_y_borra_con_el_fresco(): void
    {
        SavedCard::create([
            'user_type' => 'test-user', 'user_id' => 1,
            'alias_token' => 'alias_viejo', 'card_id' => '5',
            'card_masked_number' => '4111********1111', 'expiration_date' => '10/27',
        ]);

        Http::fake(['*/vpos/api/0.3/users/*/cards' => Http::response(['status' => 'success', 'cards' => [
            ['alias_token' => 'alias_fresco', 'card_id' => 5, 'card_masked_number' => '4111********1111', 'expiration_date' => '10/27'],
        ]])]);

        $result = (new TestUser())->deleteBancardCard('alias_viejo');

        $this->assertTrue($result['success']);
        // El DELETE a Bancard usó el alias FRESCO, no el guardado.
        Http::assertSent(fn ($r) => $r->method() === 'DELETE'
            && ($r->data()['operation']['alias_token'] ?? null) === 'alias_fresco');
        // El registro local se borró.
        $this->assertDatabaseMissing('bancard_saved_cards', ['card_id' => '5', 'user_id' => 1]);
    }

    public function test_delete_es_idempotente_si_la_tarjeta_ya_no_esta_en_bancard(): void
    {
        SavedCard::create([
            'user_type' => 'test-user', 'user_id' => 1,
            'alias_token' => 'alias_viejo', 'card_id' => '5',
            'card_masked_number' => '4111********1111', 'expiration_date' => '10/27',
        ]);

        // Bancard ya no tiene la tarjeta (lista vacía) → no hay alias vigente que borrar.
        Http::fake(['*/vpos/api/0.3/users/*/cards' => Http::response(['status' => 'success', 'cards' => []])]);

        $result = (new TestUser())->deleteBancardCard('alias_viejo');

        $this->assertTrue($result['success']);
        // No se mandó ningún DELETE a Bancard; igual se limpió lo local.
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
        $this->assertDatabaseMissing('bancard_saved_cards', ['card_id' => '5', 'user_id' => 1]);
    }

    public function test_sync_no_duplica_la_tarjeta_al_reingresar_con_alias_nuevo(): void
    {
        // El alias cambia en cada users_cards (efímero); el sync deduplica por card_id
        // (identidad estable), no por alias, así que re-sincronizar NO crea una fila nueva.
        Http::fake(['*/vpos/api/0.3/users/*/cards' => Http::sequence()
            ->push(['status' => 'success', 'cards' => [['alias_token' => 'alias_1', 'card_id' => 9, 'card_masked_number' => '5100********0001', 'expiration_date' => '01/29']]])
            ->push(['status' => 'success', 'cards' => [['alias_token' => 'alias_2', 'card_id' => 9, 'card_masked_number' => '5100********0001', 'expiration_date' => '01/29']]]),
        ]);

        $user = new TestUser();
        $user->syncBancardCards();
        $user->syncBancardCards();

        // Una sola fila para card_id 9, con el alias más reciente.
        $this->assertSame(1, SavedCard::where('user_id', 1)->where('card_id', '9')->count());
        $this->assertDatabaseHas('bancard_saved_cards', ['card_id' => '9', 'alias_token' => 'alias_2']);
    }
}

class EphemeralPayable implements \Softlab180\Bancard\Contracts\Payable
{
    public function getPayableId(): int|string { return 1; }
    public function getPayableAmount(): float|int { return 60000; }
    public function getPayableCurrency(): string { return 'PYG'; }
    public function getPayableDescription(): string { return 'Test efimero'; }
    public function storeBancardPayment(array $paymentData): void {}
    public function markAsPaid(array $confirmationData): void {}
    public function markAsFailed(array $errorData): void {}
}
