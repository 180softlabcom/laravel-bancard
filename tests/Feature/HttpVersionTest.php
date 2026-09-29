<?php

namespace Softlab180\Bancard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Softlab180\Bancard\Contracts\Payable;
use Softlab180\Bancard\Services\BancardVPOSService;
use Softlab180\Bancard\Tests\TestCase;

/**
 * Cloudflare, delante de vPOS, bloquea (403 "Sorry, you have been blocked") los pedidos
 * HTTP/1.1 con la huella TLS de OpenSSL 3.0 (Ubuntu 22/24, Forge); con HTTP/2 pasan
 * (medido en producción, 2026-09-29). Todas las operaciones deben salir por HTTP/2.
 */
class HttpVersionTest extends TestCase
{
    use RefreshDatabase;

    private function fakeBancardOk(): void
    {
        // Una respuesta que todas las operaciones aceptan como éxito.
        Http::fake(['*' => Http::response([
            'status' => 'success',
            'process_id' => 'PID-1',
            'cards' => [],
            'confirmation' => ['response' => 'S', 'response_code' => '00'],
        ])]);
    }

    /** Ejecuta las 7 operaciones que llaman a vPOS. */
    private function callAllOperations(BancardVPOSService $service): void
    {
        $payable = new class implements Payable
        {
            public function getPayableId(): int|string { return 1; }
            public function getPayableAmount(): float|int { return 10000; }
            public function getPayableCurrency(): string { return 'PYG'; }
            public function getPayableDescription(): string { return 'Prueba'; }
            public function storeBancardPayment(array $paymentData): void {}
            public function markAsPaid(array $confirmationData): void {}
            public function markAsFailed(array $errorData): void {}
        };

        $service->createSingleBuy($payable);
        $service->chargeWithToken($payable, 'alias-x');
        $service->initiateCardRegistration(1, 1, '0981000000', 'a@b.com');
        $service->getUserCards(1);
        $service->deleteCard(1, 'alias-x');
        $service->getPaymentConfirmation('110271671002108');
        $service->rollbackPayment('110271671002108');
    }

    /** @return list<string> Versión de HTTP de cada pedido enviado. */
    private function sentVersions(): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => $pair[0]->toPsrRequest()->getProtocolVersion())
            ->values()
            ->all();
    }

    public function test_todas_las_operaciones_salen_por_http2_por_defecto(): void
    {
        config(['bancard.persist_transactions' => false]);
        $this->fakeBancardOk();

        $this->callAllOperations(new BancardVPOSService('pub', 'priv', 'production'));

        $versions = $this->sentVersions();
        $this->assertCount(7, $versions);
        $this->assertSame(array_fill(0, 7, '2.0'), $versions);
    }

    public function test_la_config_permite_volver_a_http11(): void
    {
        config(['bancard.persist_transactions' => false, 'bancard.http_version' => '1.1']);
        $this->fakeBancardOk();

        $this->callAllOperations(new BancardVPOSService('pub', 'priv', 'production'));

        $this->assertSame(array_fill(0, 7, '1.1'), $this->sentVersions());
    }

    public function test_el_valor_2_tambien_significa_http2(): void
    {
        config(['bancard.http_version' => '2']);
        $this->fakeBancardOk();

        (new BancardVPOSService('pub', 'priv', 'production'))->getPaymentConfirmation('110271671002108');

        $this->assertSame(['2.0'], $this->sentVersions());
    }

    public function test_si_curl_no_soporta_http2_usa_http11_en_vez_de_fallar(): void
    {
        // Guzzle NO cae solo a 1.1: con HTTP/2 pedido y libcurl sin soporte, lanza
        // ConnectException antes de enviar. El paquete lo detecta y usa 1.1.
        $this->fakeBancardOk();
        // El aviso sale una vez por proceso: se resetea para no depender del orden de los tests.
        (new \ReflectionProperty(BancardVPOSService::class, 'http2FallbackLogged'))->setValue(null, false);
        $warnings = [];
        Log::listen(function ($log) use (&$warnings) {
            if ($log->level === 'warning') {
                $warnings[] = $log->message;
            }
        });

        $service = new class('pub', 'priv', 'production') extends BancardVPOSService
        {
            protected function curlSupportsHttp2(): bool
            {
                return false;
            }
        };

        $result = $service->getPaymentConfirmation('110271671002108');

        $this->assertTrue($result['success']);
        $this->assertSame(['1.1'], $this->sentVersions());
        $this->assertNotEmpty(array_filter($warnings, fn ($m) => str_contains($m, 'no soporta HTTP/2')));
    }

    public function test_el_curl_de_este_entorno_soporta_http2(): void
    {
        // Documenta el supuesto de los tests de arriba: en el CI (y en Forge) curl trae HTTP/2.
        $service = new class('pub', 'priv', 'production') extends BancardVPOSService
        {
            public function supports(): bool
            {
                return $this->curlSupportsHttp2();
            }
        };

        $this->assertTrue($service->supports());
    }
}
