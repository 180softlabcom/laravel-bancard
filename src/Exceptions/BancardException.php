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
        return $this->bancardResponse['messages'] ?? [];
    }

    /**
     * Get Bancard status.
     */
    public function getBancardStatus(): ?string
    {
        return $this->bancardResponse['status'] ?? null;
    }

    /**
     * Check if the error is due to invalid credentials.
     */
    public function isCredentialsError(): bool
    {
        $messages = $this->getBancardMessages();
        foreach ($messages as $msg) {
            $key = $msg['key'] ?? '';
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
        $messages = $this->getBancardMessages();
        foreach ($messages as $msg) {
            $key = $msg['key'] ?? '';
            if (str_contains($key, 'InvalidToken') || str_contains($key, 'TokenMismatch')) {
                return true;
            }
        }
        return false;
    }
}
