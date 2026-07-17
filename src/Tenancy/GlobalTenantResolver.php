<?php

namespace Softlab180\Bancard\Tenancy;

/**
 * Resolver por defecto (single-tenant): devuelve, para cualquier shop_process_id, el
 * contexto armado con las llaves GLOBALES de config('bancard.*'). Hace que el soporte
 * multi-tenant sea un overlay OPT-IN: sin `bancard.tenant_resolver` configurado, todo
 * funciona exactamente como antes.
 */
final class GlobalTenantResolver implements BancardTenantResolver
{
    public function resolveByShopProcessId(string $shopProcessId): ?BancardTenantContext
    {
        return new BancardTenantContext(
            (string) config('bancard.public_key'),
            (string) config('bancard.private_key'),
            (string) config('bancard.environment', 'staging'),
            ['enable_3ds' => (bool) config('bancard.enable_3ds', false)],
            null,
        );
    }
}
