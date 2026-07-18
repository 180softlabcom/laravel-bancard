<?php

namespace Softlab180\Bancard\Tests\Unit;

use Softlab180\Bancard\Tests\TestCase;

/**
 * Regresión del bug de Laravel <=12 (falla silenciosa de pagos): el trait
 * Illuminate\Foundation\Events\Dispatchable::dispatch() de Laravel <=12 (v12.39.0) es
 * param-less y lee los args con func_get_args(), así que un dispatch con args NOMBRADOS
 * lanza "Unknown named parameter" en tiempo de llamada. Laravel 13 lo hizo variádico, por
 * eso la suite (testbench 13) no lo veía. El WebhookController debe despachar POSICIONAL.
 *
 * Este test reproduce la firma param-less exacta y fija el contrato — es INDEPENDIENTE de
 * la versión de Laravel instalada, así que corre igual en 12 y 13. No podemos usar el
 * trait real de Laravel porque en la versión instalada es variádico y no reproduciría el
 * bug; por eso mimetizamos la firma <=12 acá.
 */
class EventDispatchCompatTest extends TestCase
{
    public function test_dispatch_paramless_de_laravel_12_rechaza_args_nombrados(): void
    {
        // Así lo hacía (mal) el WebhookController: con args nombrados. En la firma <=12 rompe.
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Unknown named parameter $shopProcessId');

        ParamlessDispatchEvent::dispatch(shopProcessId: 'x', response: []);
    }

    public function test_dispatch_paramless_acepta_args_posicionales_en_el_orden_del_constructor(): void
    {
        // El fix: posicional, en el orden del constructor. Funciona en la firma <=12 y en la
        // variádica de 13. Además fija el ORDEN: si alguien reordena el constructor o los
        // args del dispatch, el mapeo se rompe y este assert lo agarra.
        $event = ParamlessDispatchEvent::dispatch('178156301932552', ['confirmation' => []], 'commerce-A');

        $this->assertSame('178156301932552', $event->shopProcessId);
        $this->assertSame(['confirmation' => []], $event->response);
        $this->assertSame('commerce-A', $event->tenantRef);
    }
}

/**
 * Mímica EXACTA de la firma de Dispatchable::dispatch() en Laravel <=12: sin parámetros +
 * func_get_args(). (El return real es event(new static(...)); acá devolvemos la instancia,
 * que es la parte que ejercita cómo PHP recibe los args de dispatch()).
 */
trait ParamlessDispatchable
{
    public static function dispatch()
    {
        return new static(...func_get_args());
    }
}

class ParamlessDispatchEvent
{
    use ParamlessDispatchable;

    public function __construct(
        public readonly string $shopProcessId,
        public readonly array $response,
        public readonly mixed $tenantRef = null,
    ) {}
}
