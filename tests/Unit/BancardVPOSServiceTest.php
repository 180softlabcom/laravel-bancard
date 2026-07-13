<?php

namespace Softlab180\Bancard\Tests\Unit;

use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Softlab180\Bancard\Contracts\Payable;
use Softlab180\Bancard\Services\BancardVPOSService;
use Softlab180\Bancard\Tests\TestCase;

class BancardVPOSServiceTest extends TestCase
{
    private function service(string $env = 'production'): BancardVPOSService
    {
        return new BancardVPOSService('pub', 'priv', $env);
    }

    private function payable(float $amount = 70000): Payable
    {
        return new class($amount) implements Payable {
            public function __construct(private float $amount) {}
            public function getPayableId(): int|string { return 1; }
            public function getPayableAmount(): float|int { return $this->amount; }
            public function getPayableCurrency(): string { return 'PYG'; }
            public function getPayableDescription(): string { return 'Test charge'; }
            public function storeBancardPayment(array $paymentData): void {}
            public function markAsPaid(array $confirmationData): void {}
            public function markAsFailed(array $errorData): void {}
        };
    }

    private function invokeProtected(BancardVPOSService $s, string $method, ...$args): mixed
    {
        $m = new ReflectionMethod($s, $method);
        $m->setAccessible(true);

        return $m->invoke($s, ...$args);
    }

    public function test_checkout_script_url_es_el_sdk_real_4_0_0(): void
    {
        $this->assertSame(
            'https://vpos.infonet.com.py/checkout/javascript/dist/bancard-checkout-4.0.0.js',
            $this->invokeProtected($this->service('production'), 'buildCheckoutScriptUrl')
        );
        $this->assertSame(
            'https://vpos.infonet.com.py:8888/checkout/javascript/dist/bancard-checkout-4.0.0.js',
            $this->invokeProtected($this->service('staging'), 'buildCheckoutScriptUrl')
        );
    }

    public function test_iframe_url_usa_el_path_con_process_id(): void
    {
        $this->assertSame(
            'https://vpos.infonet.com.py/checkout/ABC123',
            $this->invokeProtected($this->service('production'), 'buildCheckoutUrl', 'ABC123')
        );
    }

    public function test_shop_process_id_es_numerico_de_15_digitos_y_no_colisiona(): void
    {
        $s = $this->service();
        $ids = [];
        for ($i = 0; $i < 2000; $i++) {
            $ids[] = $this->invokeProtected($s, 'generateShopProcessId');
        }

        // El generador viejo (time().rand(10000,99999)) colisionaba decenas de veces
        // en este volumen; el nuevo (15 dígitos CSPRNG) debe ser único.
        $this->assertCount(2000, array_unique($ids), 'shop_process_id colisionó');
        foreach (array_slice($ids, 0, 50) as $id) {
            $this->assertMatchesRegularExpression('/^\d{15}$/', $id);
        }
    }

    public function test_process_webhook_devuelve_is_paid_plano_sin_clave_status(): void
    {
        $shop = '178156301932552';
        $amount = '70000.00';
        $currency = 'PYG';
        $token = md5('priv'.$shop.'confirm'.$amount.$currency);

        $result = $this->service()->processWebhook(['operation' => [
            'token' => $token,
            'shop_process_id' => $shop,
            'response' => 'S',
            'response_code' => '00',
            'response_description' => 'Transaccion aprobada',
            'extended_response_description' => 'VALOR INCORRECTO DEL CVV2',
            'amount' => $amount,
            'currency' => $currency,
        ]]);

        $this->assertTrue($result['is_paid']);
        $this->assertArrayNotHasKey('status', $result);
        $this->assertArrayNotHasKey('operation', $result);
        $this->assertSame($shop, $result['shop_process_id']);
        // El motivo detallado debe propagarse (no solo el código).
        $this->assertSame('VALOR INCORRECTO DEL CVV2', $result['extended_response_description']);
        $this->assertArrayHasKey('response_details', $result);
    }

    public function test_charge_no_envia_extra_response_attributes_sin_3ds(): void
    {
        // Bancard reportó en homologación que extra_response_attributes REQUIERE el
        // producto 3DS habilitado; enviarlo sin permiso hace que rechace la
        // operación. Por eso es opt-in: con enable_3ds=false (default) NO se envía.
        config(['bancard.persist_transactions' => false, 'bancard.enable_3ds' => false]);
        Http::fake([
            '*/vpos/api/0.3/charge' => Http::response([
                'confirmation' => ['response' => 'S', 'response_code' => '00'],
            ]),
        ]);

        $this->service()->chargeWithToken($this->payable(), 'alias-token-xyz');

        Http::assertSent(fn ($request) => ! isset($request->data()['operation']['extra_response_attributes']));
    }

    public function test_charge_envia_extra_response_attributes_con_3ds_habilitado(): void
    {
        // Con enable_3ds=true (comercio enrolado en 3DS), la spec (pág. 37) pide
        // enviar siempre extra_response_attributes para recibir confirmation.process_id.
        config(['bancard.persist_transactions' => false, 'bancard.enable_3ds' => true]);
        Http::fake([
            '*/vpos/api/0.3/charge' => Http::response([
                'confirmation' => ['response' => 'S', 'response_code' => '00'],
            ]),
        ]);

        $this->service()->chargeWithToken($this->payable(), 'alias-token-xyz');

        Http::assertSent(fn ($request) => ($request->data()['operation']['extra_response_attributes'] ?? null) === ['confirmation.process_id']);
    }

    public function test_validate_confirmation_token_acepta_confirm_y_charge(): void
    {
        // Bancard usa una sola URL de confirmación: el mismo endpoint puede recibir
        // single_buy (fórmula "confirm") o charge/3DS (fórmula "charge" + alias).
        $s = $this->service(); // private key = 'priv'
        $shop = '944529967052726';
        $amount = '950000.00';
        $currency = 'PYG';

        // single_buy → fórmula "confirm" (sin alias_token)
        $confirm = md5('priv'.$shop.'confirm'.$amount.$currency);
        $this->assertTrue($s->validateConfirmationToken([
            'token' => $confirm, 'shop_process_id' => $shop, 'amount' => $amount, 'currency' => $currency,
        ]));

        // charge/3DS → fórmula "charge" + alias_token
        $alias = 'alias-xyz';
        $charge = md5('priv'.$shop.'charge'.$amount.$currency.$alias);
        $this->assertTrue($s->validateConfirmationToken([
            'token' => $charge, 'shop_process_id' => $shop, 'amount' => $amount, 'currency' => $currency, 'alias_token' => $alias,
        ]));

        // Token falso → false con ambas fórmulas
        $this->assertFalse($s->validateConfirmationToken([
            'token' => 'falsificado', 'shop_process_id' => $shop, 'amount' => $amount, 'currency' => $currency,
        ]));
    }

    public function test_charge_con_3ds_pendiente_devuelve_requires_3ds(): void
    {
        // Respuesta 3DS (spec pág. 40): todo null salvo confirmation.process_id.
        config(['bancard.persist_transactions' => false, 'bancard.enable_3ds' => true]);
        Http::fake([
            '*/vpos/api/0.3/charge' => Http::response([
                'confirmation' => ['process_id' => 'i5fn*lx6niQel0QzWK1g'],
            ]),
        ]);

        $result = $this->service()->chargeWithToken($this->payable(), 'alias-token-xyz');

        $this->assertTrue($result['requires_3ds']);
        $this->assertSame('i5fn*lx6niQel0QzWK1g', $result['process_id']);
        $this->assertStringContainsString('bancard-checkout-4.0.0.js', $result['checkout_js_url']);
    }
}
