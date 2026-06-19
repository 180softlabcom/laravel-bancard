<?php

namespace Softlab180\Bancard\Tests\Unit;

use ReflectionMethod;
use Softlab180\Bancard\Services\BancardVPOSService;
use Softlab180\Bancard\Tests\TestCase;

class BancardVPOSServiceTest extends TestCase
{
    private function service(string $env = 'production'): BancardVPOSService
    {
        return new BancardVPOSService('pub', 'priv', $env);
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
            'amount' => $amount,
            'currency' => $currency,
        ]]);

        $this->assertTrue($result['is_paid']);
        $this->assertArrayNotHasKey('status', $result);
        $this->assertArrayNotHasKey('operation', $result);
        $this->assertSame($shop, $result['shop_process_id']);
    }
}
