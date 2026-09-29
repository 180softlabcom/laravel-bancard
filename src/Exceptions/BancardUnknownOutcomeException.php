<?php

namespace Softlab180\Bancard\Exceptions;

use Throwable;

/**
 * No sabemos si Bancard ejecutó la operación.
 *
 * Se lanza cuando Bancard responde algo que no es un objeto JSON (página de error HTML,
 * cuerpo vacío, un proxy intermedio) o cuando la conexión se corta después de enviar un
 * pedido que mueve plata. NO es un rechazo: un rechazo es una respuesta JSON de Bancard
 * con "status": "error". Acá el pedido pudo haberse procesado o no.
 *
 * Qué hacer: no reintentar a ciegas (un charge podría cobrarse dos veces, un rollback
 * podría llevar a reembolsar a mano algo que Bancard ya reversó). Consultar el estado real
 * con getPaymentConfirmation($shopProcessId) o en el portal de comercios de Bancard.
 *
 * Hereda de BancardException: el código que ya atrapa BancardException la sigue atrapando.
 */
class BancardUnknownOutcomeException extends BancardException
{
    public function __construct(
        string $message,
        public readonly string $endpoint,
        public readonly ?int $httpStatus = null,
        public readonly ?string $rawBody = null,
        public readonly ?string $shopProcessId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, [], $previous);
    }
}
