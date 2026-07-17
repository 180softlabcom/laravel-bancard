<?php

namespace Softlab180\Bancard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Softlab180\Bancard\Events\CardRegistered;
use Softlab180\Bancard\Tests\TestCase;

/**
 * Seguridad del webhook de catastro: NO tiene webhook estándar y no está autenticado,
 * así que está DESHABILITADO por default (no debe escribir tarjetas ni disparar eventos
 * desde un POST no autenticado). Se habilita explícitamente para integraciones no estándar.
 */
class CardRegistrationWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['operation' => [
            'response_code' => '00',
            'user_id' => 123,
            'card_id' => 1,
            'alias_token' => 'alias-del-atacante',
            'card_masked_number' => '5418********0014',
            'card_brand' => 'Visa',
        ]];
    }

    public function test_deshabilitado_por_default_no_escribe_ni_dispara_evento(): void
    {
        // Default: card_registration_webhook_enabled = false → 200 sin procesar.
        Event::fake([CardRegistered::class]);

        $res = $this->postJson('/webhooks/bancard/card-registration', $this->payload());

        $res->assertOk()->assertJson(['status' => 'success', 'disabled' => true]);
        Event::assertNotDispatched(CardRegistered::class);
        $this->assertDatabaseCount('bancard_saved_cards', 0);
    }

    public function test_habilitado_explicitamente_procesa_el_catastro(): void
    {
        config(['bancard.card_registration_webhook_enabled' => true]);
        Event::fake([CardRegistered::class]);

        $res = $this->postJson('/webhooks/bancard/card-registration', $this->payload());

        $res->assertOk()->assertJson(['status' => 'success']);
        Event::assertDispatched(CardRegistered::class);
        $this->assertDatabaseHas('bancard_saved_cards', ['alias_token' => 'alias-del-atacante']);
    }
}
