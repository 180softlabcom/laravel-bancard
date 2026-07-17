# Guía de migración — adoptar el webhook del paquete (Front 2)

> Para un consumidor que hoy corre **su propio webhook** de Bancard (a menudo sin validar
> el token → vector de fraude). Esta guía lo migra a **usar el webhook del paquete** +
> listeners, con seguridad provista por el paquete y soporte multi-tenant. Es la
> contraparte de `docs/multi-tenant.md` (el diseño de Front 1, ya implementado en v2.0.0).

## Definition of Done

El consumidor **borra su `WebhookController` + rutas** y queda con:
- la ruta del paquete (`POST /webhooks/bancard/confirmation`) apuntada desde el portal de Bancard,
- listeners de `PaymentSucceeded` / `PaymentFailed`,
- **cero** código de seguridad propio (validación de token, etc.),
- **sin** verse forzado a adoptar `BancardTransaction` ni el modelo de usuario del paquete.

## Prerrequisitos (en orden)

1. **Actualizar a `^2.0.0`** (`composer update softlab180/laravel-bancard`).
2. **`php artisan migrate`** (columnas `bancard_transactions.alias_token` y `bancard_saved_cards.tenant_ref`).
3. **Estar en el generador CSPRNG del `shop_process_id`** (v1.2.6+). El resolver desambigua por
   `shop_process_id`; su **unicidad cross-tenant** la garantiza ese generador. Con el generador
   viejo (`rand()`, 5 dígitos) hay riesgo bajo-pero-real de colisión entre comercios → el resolver
   podría resolver el comercio equivocado. **Orden:** actualizá y dejá que las órdenes con ids
   viejos **drenen** antes de borrar tu webhook casero (paso 6).

## Paso 1 — Implementá el tenant resolver

El paquete recibe el callback (donde vos no estás en el request) y necesita saber **qué comercio**
es dueño del `shop_process_id` para validar con SUS llaves. Implementá el contrato contra tu storage:

```php
use Softlab180\Bancard\Tenancy\BancardTenantResolver;
use Softlab180\Bancard\Tenancy\BancardTenantContext;

class OrderTenantResolver implements BancardTenantResolver
{
    public function resolveByShopProcessId(string $shopProcessId): ?BancardTenantContext
    {
        // Vos ya persistís shop_process_id -> orden -> comercio (paso 2).
        $order = Order::where('bancard_shop_process_id', $shopProcessId)->first();

        if (! $order) {
            return null; // -> el webhook acusa 200 sin procesar (id desconocido)
        }

        $commerce = $order->commerce;

        return new BancardTenantContext(
            publicKey:  $commerce->bancard_public_key,
            privateKey: $commerce->bancard_private_key,
            environment: $commerce->bancard_sandbox ? 'staging' : 'production',
            flags: ['enable_3ds' => (bool) $commerce->bancard_3ds], // opcional, por-comercio (1c)
            tenantRef: $commerce->id,                                // se propaga a los eventos
        );
    }
}
```

> Los nombres de campo (`bancard_public_key`, `bancard_sandbox`, `bancard_3ds`, `commerce`, …) son
> **ilustrativos**: mapealos a los reales de tu modelo de comercio. Si apuntás a un campo/relación
> inexistente, el context se arma con valores vacíos (llave `''` → **fail-closed**, rechaza) o
> `false` **en silencio** — verificá que resuelvan bien.

Registralo:

```php
// config/bancard.php  (o vía BANCARD_TENANT_RESOLVER)
'tenant_resolver' => \App\Bancard\OrderTenantResolver::class,
```

> **Single-tenant:** si no configurás resolver, el paquete usa el `GlobalTenantResolver` (llaves
> globales) y todo funciona como antes. Esta guía es solo para multi-tenant / convergencia.

## Paso 2 — Persistí `shop_process_id → tenant` al iniciar

Tu resolver necesita ese mapeo. Si ya guardás el `shop_process_id` en la orden (que pertenece a un
comercio), ya está. `createSingleBuy()`/`chargeWithToken()` devuelven `shop_process_id` — guardalo
al iniciar el pago.

## Paso 3 — Configurá

- **Portal de Bancard:** apuntá la "URL de confirmación" a `https://tu-app/webhooks/bancard/confirmation`.
  ⚠️ **Es breaking:** las rutas viejas (`/payment`, `/charge`) ya NO existen en v2.0.0. Si no
  reapuntás el portal, los callbacks pegan **404** → cobro real, orden **impaga**.
- **`BANCARD_WEBHOOK_VERIFICATION`** — `token` (default, valida la firma MD5) o **`requery`**
  (re-consulta el estado autoritativo a Bancard bajo el tenant; zero-trust, **no** requiere guardar
  el `alias_token`, así que desacopla el charge de `persist_transactions`). En `requery`, el timeout
  está acotado (<30s) con fail-safe a *pending*.
- **`BANCARD_PERSIST_TRANSACTIONS`** — ver el callout de seguridad abajo.
- **`BANCARD_SAVED_CARDS_TENANT_COLUMN`** — si tu `bancard_saved_cards` ya tiene una columna de
  comercio (p.ej. `commerce_id`), apuntá la config ahí. **Seteala ANTES de `php artisan migrate`**:
  si migrás sin apuntarla, el paquete crea una columna `tenant_ref` fantasma (inofensiva pero
  duplicada) además de tu `commerce_id`.

> ⚠️ **Seguridad — replay / idempotencia.** El token de Bancard autentica que el mensaje **vino de
> Bancard**, pero **no firma** `response`/`response_code`. Por eso la protección contra "marcar
> pagado indebidamente" es la **idempotencia** (o el modo `requery`). Elegí una de estas config
> **seguras**:
> - **`persist_transactions=true` (default):** el paquete deduplica el `shop_process_id` de forma
>   atómica → un reenvío se acusa `duplicate` sin re-despachar. **Recomendado si no tenés dedup propio.**
> - **`webhook_verification=requery`:** el webhook **ignora el payload** y re-consulta el estado real
>   a Bancard → inmune a un payload forjado (aunque un atacante tuviera un token válido). **La opción
>   más fuerte.** No requiere `persist_transactions`.
> - **`persist_transactions=false` + `token`:** el paquete **no deduplica** → la idempotencia queda
>   **enteramente en tu listener**, que DEBE deduplicar por `shop_process_id` en **cualquier**
>   resultado (no solo "paid"): un `single_buy` declinado reenviado como `S` NO debe marcar pagado.
>   Solo usá esta combinación si tu listener cumple eso.
>
> En cualquier caso: el webhook **debe** ir sobre **HTTPS** (el token es una credencial por transacción).

## Paso 4 — Mové la lógica de negocio a listeners

Toda la máquina de estados (marcar orden pagada, ledger, notificaciones) se **relocaliza** a
listeners; no se pierde, se muda:

```php
Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $e) {
    // $e->shopProcessId, $e->tenantRef (id del comercio), $e->authorizationNumber, $e->ticketNumber
    // $e->response['confirmation'] -> confirmación CRUDA (incluye card_masked_number/card_brand
    //   del single_buy, security_information/risk_index, amount, currency, extended_response_description...)

    // IDEMPOTENCIA: con persist_transactions=false, el guard va ACÁ (único dedup de tu fila).
    //   ej.: si el Payment ya está 'paid' o marcado (merchant_notified_at), return.
    // Luego: markAsPaid + tu fulfillment. Ideal: listener ENCOLADO e idempotente.
});

Event::listen(PaymentFailed::class, function (PaymentFailed $e) {
    // $e->errorCode, $e->errorMessage (motivo legible = extended_response_description), $e->tenantRef
    // Persistí/actualizá la fila Payment como FALLIDA *con el motivo*, para que tu storefront lo
    // muestre (extended_response_description / response_description del rechazo). No la omitas: si el
    // usuario reintenta, necesitás el estado y el motivo del intento anterior.
});
```

- **`tenantRef`** te dice a qué comercio pertenece — no necesitás re-resolverlo.
- **brand/últimos-4 del single_buy** (tarjeta nueva en el iframe): salen de
  `$e->response['confirmation']['card_masked_number']` / `['card_brand']`.

## Paso 5 — Catastro y charge per-tenant

El catastro es **local** (no hay webhook): tras el `add_new_card_success` del iframe, sincronizás con
las llaves del comercio pasando el context explícito:

```php
$user->syncBancardCards($tenantContext);              // persiste las tarjetas scopeadas al comercio
$user->chargeDefaultCard($payable, tenant: $tenantContext);
$user->deleteBancardCard($aliasToken, $tenantContext);
```

Podés armar el `$tenantContext` con los mismos datos del comercio que en el resolver (o reutilizar
una fábrica). Las tarjetas quedan scopeadas por la columna de tenant (paso 3).

## Paso 6 — Borrá tu webhook casero

Una vez que el portal apunta a `/webhooks/bancard/confirmation` y los listeners están en su lugar:
eliminá tu `WebhookController` + sus rutas. **Hacelo después** de que drenen las órdenes con
`shop_process_id` viejo (prerrequisito 3).

## Mitigación interina (mientras migrás)

Si tu webhook casero actual **no valida token** (vector de fraude vivo), mitigá **en el edge/infra**,
sin agregar seguridad casera a la app: **IP allow-list** de las IPs de Bancard + rate-limit en el
balanceador/CDN. Requiere que Bancard tenga IPs estables (verificalo). Es no-divergente y compra
tiempo hasta el paso 6.

## Criterio de aceptación (E2E)

Con el webhook casero **borrado**, un E2E contra **comercio A (llaves ≠ global)** debe:

- `single_buy` y `charge` **validan** y marcan pagado **vía listener**;
- un **replay** se deduplica (por tu idempotencia en el listener);
- un **callback forjado** se rechaza;
- un `shop_process_id` **desconocido** → HTTP 200 sin procesar;
- **sin una línea de seguridad casera**, con `persist_transactions=false`.

## Secuencia recomendada

1. `composer update` a `^2.0.0` + `php artisan migrate`.
2. Deploy: resolver (paso 1) + listeners (paso 4) + config (paso 3), **sin** borrar el webhook casero
   todavía. (Interinamente ambos pueden coexistir si tu casero es idempotente.)
3. Probar en **staging** con comercio A (llaves ≠ global): single_buy + charge + catastro.
4. Reconfigurar la URL de confirmación del portal a `/webhooks/bancard/confirmation`.
5. Verificar en producción que llegan y se procesan por el webhook del paquete.
6. **Borrar** el `WebhookController` casero + rutas (tras drenar ids viejos).
