<?php

namespace Softlab180\Bancard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Softlab180\Bancard\Events\PaymentFailed;
use Softlab180\Bancard\Events\PaymentSucceeded;
use Softlab180\Bancard\Models\BancardTransaction;
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

        $res = $this->postJson('/webhooks/bancard/confirmation', $this->payload('00', 'S'));

        $res->assertOk()->assertJson(['status' => 'success']);
        Event::assertDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_pago_rechazado_dispara_failed_y_acusa_200(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        // El token sigue siendo válido (no depende del response_code).
        $res = $this->postJson('/webhooks/bancard/confirmation', $this->payload('05', 'N'));

        $res->assertOk()->assertJson(['status' => 'success']);
        Event::assertDispatched(PaymentFailed::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_token_invalido_se_rechaza_y_no_dispara_eventos(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $res = $this->postJson('/webhooks/bancard/confirmation', $this->payload('00', 'S', token: 'token_falsificado'));

        $res->assertOk()->assertJson(['status' => 'rejected']);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_callback_duplicado_no_redispara_el_evento(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $payload = $this->payload('00', 'S');
        $this->postJson('/webhooks/bancard/confirmation', $payload)->assertOk();
        // Reenvío del MISMO callback (vPOS puede reenviar): no debe re-disparar.
        $this->postJson('/webhooks/bancard/confirmation', $payload)
            ->assertOk()
            ->assertJson(['duplicate' => true]);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_charge_con_alias_en_el_payload_se_acepta(): void
    {
        // El webhook ÚNICO acepta también la fórmula "charge" (token firmado con el
        // alias_token, que acá viaja en el payload). Bancard postea single_buy y charge
        // a la misma URL de confirmación.
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $alias = 'alias-abc-123';
        $chargeToken = md5($this->priv.$this->shop.'charge'.$this->amount.$this->currency.$alias);
        $payload = $this->payload('00', 'S', token: $chargeToken);
        $payload['operation']['alias_token'] = $alias;

        $res = $this->postJson('/webhooks/bancard/confirmation', $payload);

        $res->assertOk()->assertJson(['status' => 'success']);
        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_charge_valida_con_alias_token_guardado_cuando_no_viene_en_el_payload(): void
    {
        // Caso real de homologación: el token del webhook de charge se firma con el
        // alias_token, que Bancard NO manda en el payload. El paquete lo recupera de
        // la transacción registrada al cobrar y valida con ese.
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $alias = 'alias-guardado-en-la-tx';
        BancardTransaction::create([
            'shop_process_id' => $this->shop,
            'type' => 'charge',
            'status' => 'pending',
            'amount' => $this->amount,
            'currency' => $this->currency,
            'alias_token' => $alias,
        ]);

        $chargeToken = md5($this->priv.$this->shop.'charge'.$this->amount.$this->currency.$alias);
        // NB: SIN alias_token en el payload (Bancard no lo envía).
        $payload = $this->payload('00', 'S', token: $chargeToken);

        $res = $this->postJson('/webhooks/bancard/confirmation', $payload);

        $res->assertOk()->assertJson(['status' => 'success']);
        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_charge_con_alias_token_guardado_incorrecto_se_rechaza(): void
    {
        // El token se firmó con un alias distinto al guardado → no debe validar.
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        BancardTransaction::create([
            'shop_process_id' => $this->shop,
            'type' => 'charge',
            'status' => 'pending',
            'amount' => $this->amount,
            'currency' => $this->currency,
            'alias_token' => 'alias-correcto',
        ]);

        $chargeToken = md5($this->priv.$this->shop.'charge'.$this->amount.$this->currency.'alias-DISTINTO');
        $payload = $this->payload('00', 'S', token: $chargeToken);

        $res = $this->postJson('/webhooks/bancard/confirmation', $payload);

        $res->assertOk()->assertJson(['status' => 'rejected']);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_el_webhook_no_loguea_el_token_en_claro(): void
    {
        // H1: el token del webhook es una credencial reutilizable por transacción (no
        // depende del resultado). NUNCA debe loguearse completo.
        config(['bancard.webhook.log_payloads' => true, 'bancard.persist_transactions' => false]);

        $logged = [];
        Log::listen(function ($log) use (&$logged) {
            $logged[] = json_encode($log->context);
        });

        $token = md5($this->priv.$this->shop.'confirm'.$this->amount.$this->currency);
        $this->postJson('/webhooks/bancard/confirmation', $this->payload('00', 'S', token: $token))->assertOk();

        $this->assertNotEmpty($logged, 'El webhook debería haber logueado la recepción');
        foreach ($logged as $ctx) {
            $this->assertStringNotContainsString($token, $ctx, 'El token completo NO debe aparecer en los logs');
        }
    }

    public function test_un_listener_sincronico_que_lanza_no_convierte_el_webhook_en_500(): void
    {
        // SIN Event::fake: registramos un listener REAL que lanza, para ejercitar el
        // try/catch del dispatch. El pago ya quedó reclamado; un listener que revienta
        // NO debe perder el ack (500 → vPOS da la confirmación por perdida). Debe ser 200.
        Event::listen(PaymentSucceeded::class, function () {
            throw new \RuntimeException('listener boom');
        });

        $res = $this->postJson('/webhooks/bancard/confirmation', $this->payload('00', 'S'));

        $res->assertOk()->assertJson(['status' => 'success']);
    }
}
