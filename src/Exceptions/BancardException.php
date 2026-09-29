<?php

namespace Softlab180\Bancard\Exceptions;

use Exception;
use Throwable;

class BancardException extends Exception
{
    protected array $bancardResponse;

    public function __construct(
        string $message = '',
        array $bancardResponse = [],
        ?Throwable $previous = null
    ) {
        $this->bancardResponse = $bancardResponse;
        parent::__construct($message, 0, $previous);
    }

    /**
     * Get the raw Bancard response.
     */
    public function getBancardResponse(): array
    {
        return $this->bancardResponse;
    }

    /**
     * Get Bancard error messages.
     */
    public function getBancardMessages(): array
    {
        // Una respuesta rara ("messages": "texto") no debe convertirse en TypeError.
        return is_array($this->bancardResponse['messages'] ?? null) ? $this->bancardResponse['messages'] : [];
    }

    /**
     * Get Bancard status.
     */
    public function getBancardStatus(): ?string
    {
        return is_string($this->bancardResponse['status'] ?? null) ? $this->bancardResponse['status'] : null;
    }

    /**
     * Check if the error is due to invalid credentials.
     */
    public function isCredentialsError(): bool
    {
        foreach ($this->messageKeys() as $key) {
            if (str_contains($key, 'InvalidCredentials') || str_contains($key, 'InvalidPublicKey')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the error is due to invalid token.
     */
    public function isTokenError(): bool
    {
        foreach ($this->messageKeys() as $key) {
            if (str_contains($key, 'InvalidToken') || str_contains($key, 'TokenMismatch')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Las `messages[].key` que son texto.
     *
     * @return list<string>
     */
    protected function messageKeys(): array
    {
        $keys = [];
        foreach ($this->getBancardMessages() as $message) {
            if (is_array($message) && is_string($message['key'] ?? null)) {
                $keys[] = $message['key'];
            }
        }
        return $keys;
    }
}
