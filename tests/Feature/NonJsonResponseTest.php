<?php

namespace Softlab180\Bancard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Softlab180\Bancard\Contracts\BancardIdempotencyStore;
use Softlab180\Bancard\Contracts\Payable;
use Softlab180\Bancard\Exceptions\BancardException;
use Softlab180\Bancard\Exceptions\BancardUnknownOutcomeException;
use Softlab180\Bancard\Services\BancardVPOSService;
use Softlab180\Bancard\Tests\TestCase;

/**
 * Respuestas de Bancard que NO son un objeto JSON (página de error HTML, cuerpo vacío) o
 * que no llegan (timeout). Caso real, 2026-09-29: un rollback recibió un cuerpo no JSON y
 * `$response->json()` = null reventó con TypeError en logResponse(array) — un 500 que se
 * escapaba del `catch (Exception)`. Nada de esto debe explotar, y "no sé qué pasó" nunca
 * debe confundirse con "Bancard lo rechazó" (en un rollback, eso lleva a reembolsar dos veces).
 */
class NonJsonResponseTest extends TestCase
{
    use RefreshDatabase;

    private string $shop = '110271671002108';

    private function service(): BancardVPOSService
    {
        return new BancardVPOSService('pub', 'priv', 'production');
    }

    private function htmlBadGateway(): string
    {
        return '<html><head><title>502 Bad Gateway</title></head>'
            .'<body><center><h1>502 Bad Gateway</h1></center><hr><center>nginx</center></body></html>';
    }

    // ---------------------------------------------------------------- rollback

    public function test_rollback_con_html_502_no_explota_y_el_resultado_es_desconocido(): void
    {
        Http::fake(['*/single_buy/rollback' => Http::response($this->htmlBadGateway(), 502, ['Content-Type' => 'text/html'])]);

        $result = $this->service()->rollbackPayment($this->shop);

        $this->assertFalse($result['success']);
        $this->assertSame('unknown', $result['outcome']);
        $this->assertSame(502, $result['http_status']);
        $this->assertStringContainsString('502 Bad Gateway', $result['raw_body']);
        $this->assertStringNotContainsString('<html>', $result['raw_body']);
    }

    public function test_rollback_con_cuerpo_vacio_200_es_desconocido(): void
    {
        Http::fake(['*/single_buy/rollback' => Http::response('', 200)]);

        $result = $this->service()->rollbackPayment($this->shop);

        $this->assertFalse($result['success']);
        $this->assertSame('unknown', $result['outcome']);
        $this->assertSame(200, $result['http_status']);
        $this->assertSame('', $result['raw_body']);
    }

    public function test_rollback_con_json_sin_status_es_desconocido(): void
    {
        // Un JSON que no es de Bancard (p.ej. un proxy): la doc siempre trae "status".
        Http::fake(['*/single_buy/rollback' => Http::response(['message' => 'Bad Gateway'], 502)]);

        $result = $this->service()->rollbackPayment($this->shop);

        $this->assertSame('unknown', $result['outcome']);
        $this->assertSame(502, $result['http_status']);
    }

    public function test_rollback_con_json_que_no_es_de_bancard_es_desconocido_no_rechazo(): void
    {
        // "status" numérico de un proxy, "error" sin messages, o un escalar JSON: ninguno es
        // una respuesta de Bancard. Tratarlos como rechazo invita a reembolsar dos veces.
        $cases = [
            [['status' => 500, 'error' => 'Internal Server Error'], 500],
            [['status' => 'error'], 500],
            ['true', 200],
        ];
        $sequence = Http::sequence();
        foreach ($cases as [$body, $httpStatus]) {
            $sequence->push($body, $httpStatus);
        }
        Http::fake(['*/single_buy/rollback' => $sequence]);

        foreach ($cases as [$body, $httpStatus]) {
            $result = $this->service()->rollbackPayment($this->shop);

            $this->assertSame('unknown', $result['outcome'], 'Para '.json_encode($body));
            $this->assertSame($httpStatus, $result['http_status']);
        }
    }

    public function test_rollback_ya_reversado_se_detecta_aunque_no_sea_el_primer_mensaje(): void
    {
        Http::fake(['*/single_buy/rollback' => Http::response([
            'status' => 'error',
            'messages' => [
                ['key' => 'PosCommunicationError', 'level' => 'error', 'dsc' => 'Problema de comunicación'],
                ['key' => 'AlreadyRollbackedError', 'level' => 'error', 'dsc' => 'Ya existe un pedido de rollback previo'],
            ],
        ])]);

        $result = $this->service()->rollbackPayment($this->shop);

        $this->assertSame('already_rolled_back', $result['outcome']);
        $this->assertSame('AlreadyRollbackedError', $result['bancard_key']);
    }

    public function test_rollback_sin_respuesta_timeout_es_desconocido(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $result = $this->service()->rollbackPayment($this->shop);

        $this->assertFalse($result['success']);
        $this->assertSame('unknown', $result['outcome']);
    }

    public function test_rollback_exitoso_mantiene_la_forma_anterior_y_agrega_outcome(): void
    {
        Http::fake(['*/single_buy/rollback' => Http::response([
            'status' => 'success',
            'messages' => [['key' => 'RollbackSuccessful', 'level' => 'info', 'dsc' => 'Rollback correcto.']],
        ])]);

        $result = $this->service()->rollbackPayment($this->shop);

        $this->assertTrue($result['success']);
        $this->assertSame('rolled_back', $result['outcome']);
        $this->assertSame('RollbackSuccessful', $result['bancard_key']);
        $this->assertSame('Rollback completed successfully', $result['message']); // compat
        $this->assertArrayHasKey('raw_response', $result);                         // compat
    }

    public function test_rollback_ya_reversado_no_es_un_rechazo(): void
    {
        // AlreadyRollbackedError = ya había un pedido de reversa: NO reembolsar a mano.
        Http::fake(['*/single_buy/rollback' => Http::response([
            'status' => 'error',
            'messages' => [['key' => 'AlreadyRollbackedError', 'level' => 'error', 'dsc' => 'Ya existe un pedido de rollback previo']],
        ])]);

        $result = $this->service()->rollbackPayment($this->shop);

        $this->assertFalse($result['success']);
        $this->assertSame('already_rolled_back', $result['outcome']);
        $this->assertSame('AlreadyRollbackedError', $result['bancard_key']);
    }

    public function test_rollback_cuponado_es_un_rechazo_explicito_con_la_clave_de_bancard(): void
    {
        Http::fake(['*/single_buy/rollback' => Http::response([
            'status' => 'error',
            'messages' => [['key' => 'TransactionAlreadyConfirmed', 'level' => 'error', 'dsc' => 'Transacción cuponada']],
        ])]);

        $result = $this->service()->rollbackPayment($this->shop);

        $this->assertFalse($result['success']);
        $this->assertSame('rejected', $result['outcome']);
        $this->assertSame('TransactionAlreadyConfirmed', $result['bancard_key']);
        $this->assertSame('Transacción cuponada', $result['error']);
    }

    // ------------------------------------------------------------ confirmación

    public function test_confirmacion_con_html_502_es_desconocida_y_no_explota(): void
    {
        Http::fake(['*/single_buy/confirmations' => Http::response($this->htmlBadGateway(), 502)]);

        $result = $this->service()->getPaymentConfirmation($this->shop);

        $this->assertFalse($result['success']);
        $this->assertSame('unknown', $result['outcome']);
        $this->assertSame(502, $result['http_status']);
    }

    // ------------------------------------------------------------------ charge

    public function test_charge_con_html_502_lanza_desconocido_y_deja_todo_para_conciliar(): void
    {
        config(['bancard.persist_transactions' => false]);
        Http::fake(['*/vpos/api/0.3/charge' => Http::response($this->htmlBadGateway(), 502)]);
        $payable = $this->payable();

        try {
            $this->service()->chargeWithToken($payable, 'alias-fresco');
            $this->fail('Debió lanzar BancardUnknownOutcomeException');
        } catch (BancardUnknownOutcomeException $e) {
            // Sigue siendo una BancardException: el código que ya la atrapa no cambia.
            $this->assertInstanceOf(BancardException::class, $e);
            $this->assertSame('charge', $e->endpoint);
            $this->assertSame(502, $e->httpStatus);
            $this->assertNotNull($e->shopProcessId);

            // El alias quedó guardado: si el cobro sí se hizo, el webhook puede validarlo.
            $this->assertSame('alias-fresco', app(BancardIdempotencyStore::class)->aliasTokenFor($e->shopProcessId));
            // Y el consumidor recibió el shop_process_id para conciliar.
            $this->assertSame($e->shopProcessId, $payable->stored['shop_process_id'] ?? null);
        }
    }

    public function test_charge_sin_respuesta_timeout_lanza_desconocido_y_guarda_el_alias(): void
    {
        config(['bancard.persist_transactions' => false]);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            $this->service()->chargeWithToken($this->payable(), 'alias-fresco');
            $this->fail('Debió lanzar BancardUnknownOutcomeException');
        } catch (BancardUnknownOutcomeException $e) {
            $this->assertSame('alias-fresco', app(BancardIdempotencyStore::class)->aliasTokenFor($e->shopProcessId));
        }
    }

    public function test_charge_con_conexion_cortada_de_guzzle_sin_respuesta_es_desconocido(): void
    {
        // En Laravel ≤11 un corte a mitad de la respuesta (cURL 56) llega como RequestException
        // de Guzzle, no como ConnectionException. Tiene que ser "desconocido" igual.
        config(['bancard.persist_transactions' => false]);
        Http::fake(fn () => throw new \GuzzleHttp\Exception\RequestException(
            'cURL error 56: Recv failure: Connection reset by peer',
            new \GuzzleHttp\Psr7\Request('POST', 'https://vpos.infonet.com.py/vpos/api/0.3/charge'),
        ));

        try {
            $this->service()->chargeWithToken($this->payable(), 'alias-fresco');
            $this->fail('Debió lanzar BancardUnknownOutcomeException');
        } catch (BancardUnknownOutcomeException $e) {
            $this->assertSame('alias-fresco', app(BancardIdempotencyStore::class)->aliasTokenFor($e->shopProcessId));
        }
    }

    public function test_charge_con_json_que_no_es_de_bancard_es_desconocido_no_rechazo(): void
    {
        // Un 500 de un proxy con "status" numérico, o "success" sin "confirmation": tratarlo
        // como "Payment rejected" invita a reintentar y cobrar dos veces.
        $cases = [
            [['status' => 500, 'error' => 'Internal Server Error'], 500],
            [['status' => 'success'], 200],
            [['message' => 'Bad Gateway'], 502],
        ];
        $sequence = Http::sequence();
        foreach ($cases as [$body, $httpStatus]) {
            $sequence->push($body, $httpStatus);
        }
        Http::fake(['*/vpos/api/0.3/charge' => $sequence]);

        foreach ($cases as [$body, $httpStatus]) {
            try {
                $this->service()->chargeWithToken($this->payable(), 'alias-fresco');
                $this->fail('Debió lanzar BancardUnknownOutcomeException para '.json_encode($body));
            } catch (BancardUnknownOutcomeException $e) {
                $this->assertSame($httpStatus, $e->httpStatus);
            }
        }
    }

    public function test_charge_desconocido_deja_la_transaccion_pendiente_para_conciliar(): void
    {
        config(['bancard.persist_transactions' => true]);
        Http::fake(['*/vpos/api/0.3/charge' => Http::response(['message' => 'Bad Gateway'], 502)]);

        try {
            $this->service()->chargeWithToken($this->payable(), 'alias-fresco');
            $this->fail('Debió lanzar BancardUnknownOutcomeException');
        } catch (BancardUnknownOutcomeException $e) {
            $this->assertDatabaseHas('bancard_transactions', [
                'shop_process_id' => $e->shopProcessId,
                'type' => 'charge',
                'status' => 'pending',
            ]);
        }
    }

    public function test_charge_rechazado_explicitamente_por_bancard_sigue_devolviendo_rechazo(): void
    {
        // Un error explícito de Bancard ("status": "error" + messages) NO es desconocido.
        config(['bancard.persist_transactions' => false]);
        Http::fake(['*/vpos/api/0.3/charge' => Http::response([
            'status' => 'error',
            'messages' => [['key' => 'InvalidTokenError', 'level' => 'error', 'dsc' => 'El token se generó en forma incorrecta']],
        ], 401)]);

        $result = $this->service()->chargeWithToken($this->payable(), 'alias-fresco');

        $this->assertFalse($result['success']);
        $this->assertSame('El token se generó en forma incorrecta', $result['error']);
    }

    // --------------------------------------------------- operaciones que lanzan

    public function test_single_buy_con_cuerpo_vacio_lanza_desconocido_y_no_un_type_error(): void
    {
        config(['bancard.persist_transactions' => false]);
        Http::fake(['*/vpos/api/0.3/single_buy' => Http::response('', 200)]);

        $this->expectException(BancardUnknownOutcomeException::class);

        $this->service()->createSingleBuy($this->payable());
    }

    public function test_users_cards_delete_y_catastro_con_html_lanzan_desconocido(): void
    {
        Http::fake(['*' => Http::response($this->htmlBadGateway(), 502)]);

        foreach ([
            fn () => $this->service()->getUserCards(1),
            fn () => $this->service()->deleteCard(1, 'alias-x'),
            fn () => $this->service()->initiateCardRegistration(1, 1, '0981000000', 'a@b.com'),
        ] as $call) {
            try {
                $call();
                $this->fail('Debió lanzar BancardUnknownOutcomeException');
            } catch (BancardUnknownOutcomeException $e) {
                $this->assertSame(502, $e->httpStatus);
            }
        }
    }

    public function test_users_cards_con_cards_que_no_es_lista_no_revienta_el_refresh_de_alias(): void
    {
        Http::fake(['*/users/*/cards' => Http::response(['status' => 'success', 'cards' => 'none'])]);

        $this->assertSame([], $this->service()->getUserCards(1)['cards']);
        $this->assertNull($this->service()->freshAliasToken(1, ['card_id' => '42']));
    }

    public function test_la_excepcion_tolera_messages_y_status_con_tipos_raros(): void
    {
        $e = new BancardException('x', ['status' => 500, 'messages' => 'texto plano']);

        $this->assertSame([], $e->getBancardMessages());
        $this->assertNull($e->getBancardStatus());
        $this->assertFalse($e->isTokenError());
        $this->assertFalse($e->isCredentialsError());
    }

    // ------------------------------------------------------------------ higiene

    public function test_el_fragmento_de_un_cuerpo_latin1_no_se_pierde(): void
    {
        $latin1 = mb_convert_encoding('<p>Transacción rechazada por el procesador</p>', 'ISO-8859-1', 'UTF-8');
        Http::fake(['*/single_buy/rollback' => Http::response($latin1, 500)]);

        $result = $this->service()->rollbackPayment($this->shop);

        $this->assertSame('Transacción rechazada por el procesador', $result['raw_body']);
    }

    public function test_el_fragmento_del_cuerpo_redacta_tokens_y_se_recorta(): void
    {
        $token = md5('priv'.$this->shop.'rollback0.00');
        $body = '<html><body>Error procesando token='.$token.' '.str_repeat('x', 2000).'</body></html>';
        Http::fake(['*/single_buy/rollback' => Http::response($body, 500)]);

        $result = $this->service()->rollbackPayment($this->shop);

        $this->assertStringNotContainsString($token, $result['raw_body']);
        $this->assertStringContainsString('[redactado]', $result['raw_body']);
        $this->assertLessThanOrEqual(501, mb_strlen($result['raw_body']));
    }

    private function payable(): Payable
    {
        return new class implements Payable
        {
            public array $stored = [];

            public function getPayableId(): int|string { return 1; }
            public function getPayableAmount(): float|int { return 114900; }
            public function getPayableCurrency(): string { return 'PYG'; }
            public function getPayableDescription(): string { return 'Orden de prueba'; }
            public function storeBancardPayment(array $paymentData): void { $this->stored = $paymentData; }
            public function markAsPaid(array $confirmationData): void {}
            public function markAsFailed(array $errorData): void {}
        };
    }
}
