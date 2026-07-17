<?php

namespace Softlab180\Bancard\Tenancy;

/**
 * Contrato que un consumidor multi-tenant implementa para que el paquete resuelva,
 * en el webhook ENTRANTE (donde el consumidor no está en el request), qué comercio
 * es dueño de un shop_process_id — y con qué llaves validar el token.
 *
 * Se bindea vía config('bancard.tenant_resolver') (class-string o instancia). Si no
 * hay resolver configurado, el paquete usa GlobalTenantResolver (llaves globales),
 * por lo que el comportamiento single-tenant NO cambia.
 */
interface BancardTenantResolver
{
    /**
     * Devuelve el contexto del comercio dueño de $shopProcessId, o null si no lo
     * reconoce (→ el webhook responde 200 sin procesar). El consumidor lo implementa
     * contra su propio storage (p.ej. order → commerce), habiendo persistido el mapeo
     * shop_process_id → tenant al iniciar la operación.
     */
    public function resolveByShopProcessId(string $shopProcessId): ?BancardTenantContext;
}
