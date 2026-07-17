<?php

namespace Softlab180\Bancard\Tests\Fixtures;

use RuntimeException;
use Softlab180\Bancard\Tenancy\BancardTenantContext;
use Softlab180\Bancard\Tenancy\BancardTenantResolver;

/**
 * Resolver de prueba: mapea shop_process_id → contexto desde un array. Con $throws
 * simula un resolver que revienta (p.ej. DB caída) para probar el fail-safe.
 */
class ArrayTenantResolver implements BancardTenantResolver
{
    /** @param array<string, BancardTenantContext> $map */
    public function __construct(
        private array $map,
        private bool $throws = false,
    ) {}

    public function resolveByShopProcessId(string $shopProcessId): ?BancardTenantContext
    {
        if ($this->throws) {
            throw new RuntimeException('resolver boom (DB down)');
        }

        return $this->map[$shopProcessId] ?? null;
    }
}
