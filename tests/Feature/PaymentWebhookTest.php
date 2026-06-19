<?php

namespace Softlab180\Bancard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Softlab180\Bancard\Events\PaymentFailed;
use Softlab180\Bancard\Events\PaymentSucceeded;
use Softlab180\Bancard\Tests\TestCase;

/**
 * Regresión del bug crítico del webhook: un pago aprobado real (#2235 de prod)
 * disparaba PaymentFailed y respondía 400 porque el controller leía
 * $result['status'] (clave inexistente). Estos tests usan el payload real.
 */
class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $priv = 'test_private_key';

    private string $shop = '178156301932552';

    private string $amount = '70000.00';

    private string $currency = 'PYG';

    private function confirmToken(): string
    {
        return md5($this->priv.$this->shop.'confirm'.$this->amount.$this->currency);
    }

    private function payload(string $responseCode = '00', string $response = 'S', ?string $token = null): array
    {
        return ['operation' => [
            'token' => $token ?? $this->confirmToken(),
            'shop_process_id' => $this->shop,
            'response' => $response,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'authorization_number' => '716372',
            'ticket_number' => '5463433723',
            'response_code' => $responseCode,
            'response_description' => $responseCode === '00' ? 'Transaccion aprobada' : 'Rechazada',
        ]];
    }

    public function test_pago_aprobado_dispara_succeeded_y_acusa_200(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $res = $this->postJson('/webhooks/bancard/payment', $this->payload('00', 'S'));

        $res->assertOk()->assertJson(['status' => 'success']);
        Event::assertDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_pago_rechazado_dispara_failed_y_acusa_200(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        // El token sigue siendo válido (no depende del response_code).
        $res = $this->postJson('/webhooks/bancard/payment', $this->payload('05', 'N'));

        $res->assertOk()->assertJson(['status' => 'success']);
        Event::assertDispatched(PaymentFailed::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_token_invalido_se_rechaza_y_no_dispara_eventos(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $res = $this->postJson('/webhooks/bancard/payment', $this->payload('00', 'S', token: 'token_falsificado'));

        $res->assertOk()->assertJson(['status' => 'rejected']);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_callback_duplicado_no_redispara_el_evento(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $payload = $this->payload('00', 'S');
        $this->postJson('/webhooks/bancard/payment', $payload)->assertOk();
        // Reenvío del MISMO callback (vPOS puede reenviar): no debe re-disparar.
        $this->postJson('/webhooks/bancard/payment', $payload)
            ->assertOk()
            ->assertJson(['duplicate' => true]);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }
}
