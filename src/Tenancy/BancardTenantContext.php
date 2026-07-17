<?php

namespace Softlab180\Bancard\Tenancy;

/**
 * Identidad + contexto de un comercio (tenant) resuelto para procesar una
 * confirmación entrante. El WebhookController construye un BancardVPOSService con
 * estas llaves (NUNCA el singleton global) para validar el token con la llave del
 * comercio dueño del callback.
 */
final class BancardTenantContext
{
    /**
     * @param array<string,mixed> $flags Toggles por-tenant (p.ej. ['enable_3ds' => true]).
     * @param mixed $tenantRef Referencia opaca del consumidor (id de comercio, etc.);
     *                         se propaga a los eventos para que el listener sepa el tenant.
     */
    public function __construct(
        public readonly string $publicKey,
        public readonly string $privateKey,
        // Requerido (sin default): el resolver debe ser explícito con el entorno del
        // comercio; un default silencioso apuntaría al baseUrl equivocado.
        public readonly string $environment,
        public readonly array $flags = [],
        public readonly mixed $tenantRef = null,
    ) {}

    public function flag(string $key, mixed $default = null): mixed
    {
        return $this->flags[$key] ?? $default;
    }
}
