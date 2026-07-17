<?php

namespace Softlab180\Bancard\Exceptions;

use RuntimeException;

/**
 * Se lanza en el modo de verificación 'requery' cuando la re-consulta autoritativa a
 * Bancard (getPaymentConfirmation) no está disponible o timeouteó. El webhook la trata
 * como "estado desconocido": acusa HTTP 200 y deja la orden pending para reconciliar,
 * SIN despachar ningún evento (nunca marca pagado/fallido con información incierta).
 */
class ConfirmationUnavailableException extends RuntimeException
{
}
