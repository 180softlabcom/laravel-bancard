<?php

namespace Softlab180\Bancard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Softlab180\Bancard\Events\PaymentFailed;
use Softlab180\Bancard\Events\PaymentSucceeded;
use Softlab180\Bancard\Tenancy\BancardTenantContext;
use Softlab180\Bancard\Tests\Fixtures\ArrayTenantResolver;
use Softlab180\Bancard\Tests\TestCase;

/**
 * Front 1a — el webhook resuelve el comercio por shop_process_id y valida con SUS
 * llaves (no las globales del singleton). Cierra el bloqueo estructural del
 * multi-tenant y el vector de fraude de los webhooks caseros.
 */
class MultiTenantWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $shop = '178156301932552';

    private string $amount = '70000.00';

    private string $currency = 'PYG';

    /** Llaves del comercio A, DISTINTAS de las globales de config. */
    private string $commerceAKey = 'COMMERCE_A_PRIVATE_KEY';

    private function useCommerceAResolver(mixed $tenantRef = 'commerce-A'): void
    {
        // Global DISTINTA de la del comercio: si el webhook cayera al singleton global,
        // validaría con esta y fallaría → el test prueba que usa la del resolver.
        config(['bancard.private_key' => 'GLOBAL_PRIVATE_KEY', 'bancard.public_key' => 'GLOBAL_PUBLIC_KEY']);

        $context = new BancardTenantContext(
            'COMMERCE_A_PUBLIC_KEY',
            $this->commerceAKey,
            'production',
            [],
            $tenantRef,
        );

        config(['bancard.tenant_resolver' => new ArrayTenantResolver([$this->shop => $context])]);
    }

    private function payload(string $token, string $responseCode = '00', string $response = 'S', array $extra = []): array
    {
        return ['operation' => array_merge([
            'token' => $token,
            'shop_process_id' => $this->shop,
            'response' => $response,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'authorization_number' => '716372',
            'ticket_number' => '5463433723',
            'response_code' => $responseCode,
            'response_description' => $responseCode === '00' ? 'Transaccion aprobada' : 'Rechazada',
        ], $extra)];
    }

    public function test_valida_con_la_llave_del_comercio_resuelto_no_la_global(): void
    {
        // El linchpin: el token se firmó con la llave del comercio A (≠ global). Si el
        // webhook usara el singleton global, no validaría → prueba de raíz que no hay
        // residuo del singleton en el path del webhook.
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
        $this->useCommerceAResolver();

        $token = md5($this->commerceAKey.$this->shop.'confirm'.$this->amount.$this->currency);
        $res = $this->postJson('/webhooks/bancard/confirmation', $this->payload($token));

        $res->assertOk()->assertJson(['status' => 'success']);
        Event::assertDispatched(PaymentSucceeded::class, fn ($e) => $e->tenantRef === 'commerce-A');
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_rechaza_si_el_token_se_firmo_con_la_llave_global_y_no_la_del_comercio(): void
    {
        // Token firmado con la llave GLOBAL mientras el comercio resuelto tiene otra →
        // no valida → rejected, sin evento. (Un webhook que usara la global lo aceptaría.)
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
        $this->useCommerceAResolver();

        $tokenConGlobal = md5('GLOBAL_PRIVATE_KEY'.$this->shop.'confirm'.$this->amount.$this->currency);
        $res = $this->postJson('/webhooks/bancard/confirmation', $this->payload($tokenConGlobal));

        $res->assertOk()->assertExactJson(['status' => 'success']);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_tenant_no_resuelto_devuelve_200_sin_procesar(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
        // Resolver vacío → null para cualquier shop_process_id.
        config(['bancard.tenant_resolver' => new ArrayTenantResolver([])]);

        $res = $this->postJson('/webhooks/bancard/confirmation', $this->payload('cualquier_token'));

        // Body limpio (Bancard exige {"status":"success"}); "no resuelto" se prueba por
        // la ausencia de eventos (y queda en el log), no en el body.
        $res->assertOk()->assertExactJson(['status' => 'success']);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_resolver_que_lanza_devuelve_200_sin_evento_ni_500(): void
    {
        // Fail-safe: el resolver revienta (DB caída) → 200 sin procesar, nunca 500
        // (un no-200 le haría perder el callback a vPOS).
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
        config(['bancard.tenant_resolver' => new ArrayTenantResolver([], throws: true)]);

        $res = $this->postJson('/webhooks/bancard/confirmation', $this->payload('cualquier_token'));

        $res->assertOk()->assertExactJson(['status' => 'success']);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_confirmacion_cruda_conserva_card_masked_number_del_single_buy(): void
    {
        // R4: el evento carga la confirmación CRUDA, así el listener recupera campos que
        // el parseo estructurado descartaría (marca/últimos-4 del single_buy).
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
        $this->useCommerceAResolver();

        $token = md5($this->commerceAKey.$this->shop.'confirm'.$this->amount.$this->currency);
        $payload = $this->payload($token, extra: [
            'card_masked_number' => '5418********0014',
            'card_brand' => 'MasterCard',
        ]);

        $this->postJson('/webhooks/bancard/confirmation', $payload)->assertOk();

        Event::assertDispatched(
            PaymentSucceeded::class,
            fn ($e) => ($e->response['confirmation']['card_masked_number'] ?? null) === '5418********0014'
                && ($e->response['confirmation']['card_brand'] ?? null) === 'MasterCard'
        );
    }

    public function test_modo_requery_confirma_contra_bancard_e_ignora_el_payload(): void
    {
        // Zero-trust sobre el estado: en modo requery el token del payload es basura y
        // aun así valida, porque se re-consulta el estado autoritativo a Bancard.
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
        $this->useCommerceAResolver();
        config(['bancard.webhook_verification' => 'requery']);

        Http::fake([
            '*/vpos/api/0.3/single_buy/confirmations' => Http::response([
                'status' => 'success',
                'confirmation' => [
                    'response' => 'S',
                    'response_code' => '00',
                    'authorization_number' => '999999',
                    'amount' => $this->amount,
                    'currency' => $this->currency,
                ],
            ]),
        ]);

        $res = $this->postJson('/webhooks/bancard/confirmation', $this->payload('token_basura_ignorado'));

        $res->assertOk()->assertJson(['status' => 'success']);
        Event::assertDispatched(PaymentSucceeded::class, fn ($e) => $e->tenantRef === 'commerce-A');

        // Prueba de raíz que el requery usó la llave del COMERCIO (no la global): el token
        // de get_confirmation se firma con la private key del comercio A.
        Http::assertSent(fn ($req) => ($req->data()['operation']['token'] ?? null)
            === md5($this->commerceAKey.$this->shop.'get_confirmation'));
    }

    public function test_pago_rechazado_multi_tenant_dispara_failed_con_tenant_ref(): void
    {
        // PaymentFailed también debe propagar el tenantRef (token válido, pago rechazado).
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
        $this->useCommerceAResolver('commerce-A');

        $token = md5($this->commerceAKey.$this->shop.'confirm'.$this->amount.$this->currency);
        $res = $this->postJson('/webhooks/bancard/confirmation', $this->payload($token, '05', 'N'));

        $res->assertOk()->assertJson(['status' => 'success']);
        Event::assertDispatched(PaymentFailed::class, fn ($e) => $e->tenantRef === 'commerce-A' && $e->errorCode === '05');
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_modo_requery_con_pago_no_aprobado_dispara_failed(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
        $this->useCommerceAResolver();
        config(['bancard.webhook_verification' => 'requery']);

        Http::fake([
            '*/vpos/api/0.3/single_buy/confirmations' => Http::response([
                'status' => 'success',
                'confirmation' => ['response' => 'N', 'response_code' => '05', 'response_description' => 'Rechazada'],
            ]),
        ]);

        $res = $this->postJson('/webhooks/bancard/confirmation', $this->payload('token_ignorado'));

        $res->assertOk()->assertJson(['status' => 'success']);
        Event::assertDispatched(PaymentFailed::class, fn ($e) => $e->tenantRef === 'commerce-A');
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_charge_valida_con_la_llave_del_comercio(): void
    {
        // Fórmula "charge" (token + alias) por el webhook único, multi-tenant.
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
        $this->useCommerceAResolver();

        $alias = 'alias-A';
        $token = md5($this->commerceAKey.$this->shop.'charge'.$this->amount.$this->currency.$alias);
        $payload = $this->payload($token, extra: ['alias_token' => $alias]);

        $res = $this->postJson('/webhooks/bancard/confirmation', $payload);

        $res->assertOk()->assertJson(['status' => 'success']);
        Event::assertDispatched(PaymentSucceeded::class, fn ($e) => $e->tenantRef === 'commerce-A');
    }

    public function test_modo_requery_si_bancard_no_responde_devuelve_200_pending_sin_evento(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
        $this->useCommerceAResolver();
        config(['bancard.webhook_verification' => 'requery']);

        Http::fake([
            '*/vpos/api/0.3/single_buy/confirmations' => Http::response(['status' => 'error'], 500),
        ]);

        $res = $this->postJson('/webhooks/bancard/confirmation', $this->payload('token_ignorado'));

        // Body limpio; "pending" (reconciliar con get_confirmation) queda en el log.
        $res->assertOk()->assertExactJson(['status' => 'success']);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

}
