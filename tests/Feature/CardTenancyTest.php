<?php

namespace Softlab180\Bancard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Softlab180\Bancard\Models\SavedCard;
use Softlab180\Bancard\Tenancy\BancardTenantContext;
use Softlab180\Bancard\Tests\Fixtures\TestUser;
use Softlab180\Bancard\Tests\TestCase;

/**
 * Front 1b — catastro/tarjetas per-tenant. El catastro es consumer-initiated, así que
 * el trait acepta un BancardTenantContext explícito: opera con las llaves del comercio
 * y scopea las tarjetas por la columna de tenant.
 */
class CardTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_per_tenant_usa_las_llaves_del_comercio_y_persiste_el_tenant_ref(): void
    {
        $commerceKey = 'COMMERCE_A_PRIVATE_KEY';
        // Global distinta, para probar que NO se usa la global.
        config(['bancard.private_key' => 'GLOBAL_KEY']);

        Http::fake(['*/vpos/api/0.3/users/*/cards' => Http::response([
            'status' => 'success',
            'cards' => [[
                'alias_token' => 'alias_A',
                'card_masked_number' => '5418********0014',
                'card_brand' => 'MasterCard',
                'card_id' => 1,
            ]],
        ])]);

        $ctx = new BancardTenantContext('pub_A', $commerceKey, 'production', [], 'commerce-A');
        $cards = (new TestUser())->syncBancardCards($ctx);

        $this->assertCount(1, $cards);

        // La request de users_cards se firmó con la llave del COMERCIO (no la global).
        Http::assertSent(fn ($r) => ($r->data()['operation']['token'] ?? null)
            === md5($commerceKey.'1'.'request_user_cards'));

        // La tarjeta se persistió scopeada al comercio.
        $this->assertDatabaseHas('bancard_saved_cards', [
            'alias_token' => 'alias_A',
            'tenant_ref' => 'commerce-A',
        ]);
    }

    public function test_las_tarjetas_se_scopean_por_tenant(): void
    {
        // Mismo usuario, dos comercios.
        SavedCard::create(['user_type' => 'test-user', 'user_id' => 1, 'alias_token' => 'a_A', 'tenant_ref' => 'commerce-A', 'is_default' => true]);
        SavedCard::create(['user_type' => 'test-user', 'user_id' => 1, 'alias_token' => 'a_B', 'tenant_ref' => 'commerce-B', 'is_default' => true]);

        $user = new TestUser();
        $ctxA = new BancardTenantContext('p', 'k', 'production', [], 'commerce-A');
        $ctxB = new BancardTenantContext('p', 'k', 'production', [], 'commerce-B');

        $this->assertSame('a_A', $user->defaultBancardCard($ctxA)->alias_token);
        $this->assertSame('a_B', $user->defaultBancardCard($ctxB)->alias_token);
        $this->assertSame(1, $user->bancardCardsCount($ctxA));
        $this->assertSame(1, $user->bancardCardsCount($ctxB));
        // Sin tenant → todas.
        $this->assertSame(2, $user->bancardCardsCount());
    }

    public function test_charge_default_card_refresca_el_alias_y_usa_las_llaves_del_comercio(): void
    {
        config(['bancard.persist_transactions' => false, 'bancard.private_key' => 'GLOBAL_KEY']);
        $commerceKey = 'COMMERCE_A_PRIVATE_KEY';

        // El alias guardado es EFÍMERO (TTL minutos): el paquete debe pedir uno fresco a
        // Bancard antes de cobrar, matcheando por identidad estable (card_id).
        SavedCard::create([
            'user_type' => 'test-user', 'user_id' => 1,
            'alias_token' => 'alias_viejo', 'card_id' => '77',
            'card_masked_number' => '5418********0014', 'expiration_date' => '08/26',
            'tenant_ref' => 'commerce-A', 'is_default' => true,
        ]);

        Http::fake([
            '*/vpos/api/0.3/users/*/cards' => Http::response(['status' => 'success', 'cards' => [
                ['alias_token' => 'alias_fresco', 'card_id' => 77, 'card_masked_number' => '5418********0014', 'expiration_date' => '08/26'],
            ]]),
            '*/vpos/api/0.3/charge' => Http::response(['confirmation' => ['response' => 'S', 'response_code' => '00']]),
        ]);

        $ctx = new BancardTenantContext('pub_A', $commerceKey, 'production', [], 'commerce-A');
        (new TestUser())->chargeDefaultCard(new CardTenancyPayable(), tenant: $ctx);

        // Se cobró con el alias FRESCO (no el guardado), firmado con la llave del comercio.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/charge')
            && ($r->data()['operation']['token'] ?? null)
            === md5($commerceKey.$r->data()['operation']['shop_process_id'].'charge'.'50000.00'.'PYG'.'alias_fresco'));

        // Y el users_cards previo se firmó con la llave del comercio (no la global).
        Http::assertSent(fn ($r) => str_contains($r->url(), '/cards')
            && ($r->data()['operation']['token'] ?? null) === md5($commerceKey.'1'.'request_user_cards'));
    }

    public function test_set_as_default_no_desmarca_la_default_de_otro_comercio(): void
    {
        // Aislamiento: elegir la default en un comercio NO debe tocar la de otro.
        SavedCard::create(['user_type' => 'test-user', 'user_id' => 1, 'alias_token' => 'a_A', 'tenant_ref' => 'commerce-A', 'is_default' => true]);
        $b = SavedCard::create(['user_type' => 'test-user', 'user_id' => 1, 'alias_token' => 'a_B', 'tenant_ref' => 'commerce-B', 'is_default' => false]);

        $b->setAsDefault();

        $this->assertDatabaseHas('bancard_saved_cards', ['alias_token' => 'a_A', 'is_default' => true]);
        $this->assertDatabaseHas('bancard_saved_cards', ['alias_token' => 'a_B', 'is_default' => true]);
    }

    public function test_scoping_funciona_con_una_columna_de_tenant_personalizada(): void
    {
        // Escenario real de un consumidor (rga): su tabla ya tiene 'commerce_id', así que
        // apunta bancard.saved_cards_tenant_column ahí en vez de usar 'tenant_ref'. Todo el
        // scoping (sync, query per-tenant y mass-assignment) debe respetar esa columna.
        Schema::table('bancard_saved_cards', fn ($t) => $t->string('commerce_id')->nullable());
        config(['bancard.saved_cards_tenant_column' => 'commerce_id']);

        Http::fake(['*/vpos/api/0.3/users/*/cards' => Http::response([
            'status' => 'success',
            'cards' => [['alias_token' => 'alias_A', 'card_id' => 1]],
        ])]);

        $ctxA = new BancardTenantContext('pub', 'k', 'production', [], 'commerce-A');
        (new TestUser())->syncBancardCards($ctxA);

        // El sync scopeó por commerce_id (no tenant_ref).
        $this->assertDatabaseHas('bancard_saved_cards', [
            'alias_token' => 'alias_A',
            'commerce_id' => 'commerce-A',
        ]);

        // La query per-tenant lo encuentra por esa columna, y aísla de otro comercio.
        $user = new TestUser();
        $this->assertSame(1, $user->bancardCardsCount($ctxA));
        $this->assertSame(0, $user->bancardCardsCount(
            new BancardTenantContext('pub', 'k', 'production', [], 'commerce-OTHER')
        ));

        // Nit: el mass-assignment ($fillable dinámico) respeta la columna configurada —
        // create() con 'commerce_id' NO la descarta (si lo hiciera, quedaría tenant NULL).
        $mass = SavedCard::create([
            'user_type' => 'test-user',
            'user_id' => 2,
            'alias_token' => 'alias_mass',
            'commerce_id' => 'commerce-A',
        ]);
        $this->assertSame('commerce-A', $mass->fresh()->commerce_id);
    }

    public function test_set_as_default_single_tenant_desmarca_las_demas(): void
    {
        // No-breaking: en single-tenant (tenant_ref null) sigue desmarcando las otras.
        SavedCard::create(['user_type' => 'test-user', 'user_id' => 1, 'alias_token' => 'c1', 'is_default' => true]);
        $c2 = SavedCard::create(['user_type' => 'test-user', 'user_id' => 1, 'alias_token' => 'c2', 'is_default' => false]);

        $c2->setAsDefault();

        $this->assertDatabaseHas('bancard_saved_cards', ['alias_token' => 'c1', 'is_default' => false]);
        $this->assertDatabaseHas('bancard_saved_cards', ['alias_token' => 'c2', 'is_default' => true]);
    }
}

class CardTenancyPayable implements \Softlab180\Bancard\Contracts\Payable
{
    public function getPayableId(): int|string { return 1; }
    public function getPayableAmount(): float|int { return 50000; }
    public function getPayableCurrency(): string { return 'PYG'; }
    public function getPayableDescription(): string { return 'Test'; }
    public function storeBancardPayment(array $paymentData): void {}
    public function markAsPaid(array $confirmationData): void {}
    public function markAsFailed(array $errorData): void {}
}
