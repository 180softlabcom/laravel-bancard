# Diseño — Soporte multi-tenant (Front 1)

> **Estado:** **implementado en v2.0.0.** Este es el documento de diseño original; abajo
> quedan notas donde el diseño divergió de lo entregado. Objetivo: que **cualquier**
> consumidor multi-tenant pueda **borrar su webhook propio** y confiar 100% en el paquete,
> con seguridad igual o mejor que la casera — sin forzar el modelo de datos del paquete.
> Para migrar un consumidor, ver `docs/consumer-migration.md`.
>
> ⚠️ **v2.0.0 es un release breaking en la superficie** (aunque el *comportamiento*
> single-tenant se preserva vía el `GlobalTenantResolver` por defecto). Cambios que
> rompen la API respecto de 1.x: las rutas del webhook se **colapsaron a una sola**
> (`POST /webhooks/bancard/confirmation`; desaparecen `/payment` y `/charge`), se
> **eliminó** el evento `CardRegistered` y todo intento de "webhook de catastro" (Bancard
> no lo tiene — el catastro es 100% local vía `syncBancardCards()`), y se quitaron las
> config `auto_save_cards` / `card_registration_webhook_enabled`. Donde el texto de abajo
> diga "no-breaking", léase **comportamiento single-tenant no-breaking**, no superficie.

## 1. Contexto y problema

El paquete es la forma recomendada de usar Bancard en la organización, pero hoy **no
sirve para multi-tenant en el camino entrante (webhook)**, y por eso los proyectos
multi-comercio rodaron su propio `WebhookController` — algunos **sin validar el token**
(vector de fraude: un POST falso marca una orden pagada). Cada webhook casero es una
superficie de seguridad distinta: uno queda seguro y otro no, aunque usen el mismo
paquete. El objetivo es **converger a un solo camino auditado**.

**La causa raíz (evidencia):**

- `BancardServiceProvider` bindea `BancardVPOSService` como **singleton con las llaves
  GLOBALES**: `new BancardVPOSService(config('bancard.public_key'), config('bancard.private_key'), …)`.
- El `WebhookController` valida el token vía el Facade → ese singleton → **la única llave
  global**.
- Lo **saliente** (`createSingleBuy`/`chargeWithToken`) el consumidor lo resuelve por
  comercio (instancia por-comercio con sus llaves). Lo **entrante** (webhook) vuelve al
  singleton global.

Esa **asimetría** es el bloqueo estructural: en multi-tenant, cada callback fue firmado
por Bancard con la llave **de ese comercio**; validarlo contra la llave global lo
rechaza como *"Invalid token"* → órdenes legítimas nunca se marcan pagadas. Por eso un
proyecto multi-comercio **no puede** adoptar el webhook del paquete tal cual.

## 2. Objetivos / No-objetivos

**Objetivos**
- Un consumidor multi-tenant borra su `WebhookController` + rutas y queda solo con las
  rutas del paquete + listeners (`PaymentSucceeded`/`PaymentFailed`).
- Seguridad ≥ la casera, provista por el paquete (un solo lugar auditado).
- **Sin** forzar `BancardTransaction`, `persist_transactions=true`, ni el modelo de
  usuario del paquete.
- **No-breaking en comportamiento** para single-tenant (la superficie sí cambia en 2.0.0).

**No-objetivos**
- Resolver el token de un *webhook de catastro*: **no existe** — Bancard no envía callback
  de catastro. El flujo correcto es pull autenticado con `syncBancardCards()`. En v2.0.0 se
  **eliminó** todo el andamiaje que asumía ese webhook (evento `CardRegistered` incluido).
- Cambiar el comportamiento single-tenant.
- Imponer el modelo de datos del paquete a los consumidores.

## 3. Principios

1. **Contrato, no solución.** El paquete expone un contrato; el consumidor lo implementa
   contra su propio storage.
2. **General, no para-un-consumidor.** Debe servir a todos los proyectos multi-comercio.
3. **Sin acoplar a la persistencia del paquete.** Nada exige `BancardTransaction` ni
   `persist_transactions=true`.
4. **No-breaking single-tenant.** Sin resolver configurado, todo funciona como hoy.

## 4. El contrato

### R1 — Resolver de tenant (linchpin)

Hook del consumidor, **NO** atado a `BancardTransaction`. Es imprescindible **solo para
lo entrante sin contexto del consumidor** (el webhook de pago/3DS), porque ahí el
consumidor no está en el request — el paquete recibe el callback de Bancard y debe
resolver **qué comercio** es antes de validar.

```php
interface BancardTenantResolver
{
    /**
     * Devuelve el contexto (llaves + entorno + flags) del comercio dueño de este
     * shop_process_id, o null si no lo reconoce (→ el webhook responde 200 sin procesar).
     * El consumidor lo implementa contra su storage (p.ej. order → commerce).
     */
    public function resolveByShopProcessId(string $shopProcessId): ?BancardTenantContext;
}

final class BancardTenantContext
{
    public function __construct(
        public readonly string $publicKey,
        public readonly string $privateKey,
        public readonly string $environment,
        public readonly array $flags = [],     // enable_3ds, … (por-tenant, ver R7)
        public readonly mixed $tenantRef = null, // se propaga a los eventos
    ) {}
}
```

Se bindea por config (`bancard.tenant_resolver`). **Responsabilidad del consumidor:**
persistir el mapeo `shop_process_id → tenant` al iniciar la operación (el paquete ya
devuelve el `shop_process_id` en `createSingleBuy`/`chargeWithToken`).

> **Nota de diseño (reducción del contrato):** la spec original proponía un segundo
> método `resolveByUser()` para catastro. **No hace falta.** El catastro (`syncBancardCards`)
> es **iniciado por el consumidor**, en un request donde ya conoce al usuario y su
> comercio → pasa el tenant **explícito** (o usa una instancia por-comercio). El resolver
> callback es necesario **únicamente** para lo entrante (webhook). El contrato se reduce a
> **un** método.

### R2 — Mecanismo del webhook (el corazón de Front 1)

El `WebhookController` **deja de usar el Facade singleton**. Pasa a:

```
payload → shop_process_id
        → resolver->resolveByShopProcessId($spid)
            · null  → HTTP 200 sin procesar (transacción desconocida)
            · ctx   → construir un BancardVPOSService PER-TENANT con ctx (NO el singleton)
                    → validar token (o re-query, ver R5) con ESA instancia
                    → despachar PaymentSucceeded/Failed con ctx->tenantRef
```

`BancardVPOSService::forContext(BancardTenantContext $ctx)` construye la instancia con las
llaves del comercio. La validación (`validateConfirmationToken`) no cambia — cambia **qué
instancia** la ejecuta.

**Robustez (obligatorio en 1a):**

- **Cero residuo del singleton.** TODAS las llamadas del path del webhook
  (`processWebhook`/`validateConfirmationToken`/`getPaymentConfirmation`/`lookupAliasToken`)
  corren sobre la instancia `forContext($ctx)` — **nunca** `Bancard::` ni `app('bancard')`. El
  **E2E con llaves ≠ global lo prueba de raíz**: si algún path cayera al singleton global,
  validaría con la llave equivocada y el test fallaría. (Opcional: chequeo estático de que el
  controller del webhook no referencia el Facade/singleton.)
- **Fail-safe ante fallo del resolver.** Si `resolveByShopProcessId` **lanza** (p.ej. DB caída),
  envolver → **HTTP 200 + no procesar + log/alerta**, nunca 500. vPOS no reintenta confiable y
  un no-200 le hace dar la confirmación por perdida; la reconciliación (callback del browser /
  polling) la recupera autoritativamente. (`null` del resolver ya es 200 sin procesar.)

### R2b — Default no-breaking

Si `bancard.tenant_resolver` es `null`, el paquete usa un `GlobalTenantResolver` interno
que devuelve, para cualquier `shop_process_id`, el contexto armado con las **llaves
globales** de config. → **single-tenant queda idéntico a hoy**; multi-tenant es un overlay
**opt-in**.

### R3 — Idempotencia como costura inyectable (no acople)

`persist_transactions` **nunca** es condición para converger:

- Consumidor con idempotencia propia (p.ej. `merchant_notified_at` + `markAsPaid`-si-pending):
  deja `persist_transactions=false` → el paquete no deduplica → su **listener es idempotente**.
- Consumidor sin idempotencia propia: deja el dedup del paquete (`claimTransaction`, requiere
  persist) activo.

> **Nota de migración (Front 2):** con `persist_transactions=false`, el guard de idempotencia de
> la fila de negocio del consumidor (hoy suele vivir en el callback del browser) debe **moverse
> al listener** — pasa a ser el único dedup de esa fila. El criterio de aceptación testea
> "replay se deduplica".

### R4 — Eventos con payload suficiente

`PaymentSucceeded`/`PaymentFailed` ya cargan `shop_process_id`, `is_paid`,
`authorization_number`, `ticket_number`, `response_code`, `response_description`,
`extended_response_description`, `amount`, `currency`. **Se agrega `tenantRef`** (hoy falta) —
campo nuevo opcional al final → no-breaking.

> **La confirmación CRUDA, verbatim (importante).** El evento debe cargar el `operation`
> **completo** de Bancard, no solo el subconjunto que hoy arma `processWebhook`. Motivo: en
> **single_buy** (tarjeta nueva en el iframe) Bancard manda `card_masked_number`/`card_brand` en
> la confirmación, y hoy el `processWebhook` estructurado **los descarta** → se perdería el
> display (marca/últimos-4). El listener los lee de la confirmación cruda. En **charge** el
> consumidor ya los tiene (inició el cobro). `security_information` (card_source, card_country)
> sí está; brand/últimos-4 salen del `operation` crudo → **verificar contra los campos reales
> del single_buy**.

### R5 — Charge seguro sin forzar persistencia (modo re-query, opt-in)

`config('bancard.webhook_verification')`:

- `'token'` (default): valida la firma MD5 del payload. Para charge necesita el `alias_token`
  (→ `persist_transactions=true` para recuperarlo).
- `'requery'`: tras resolver el tenant, autentica **re-consultando** `getPaymentConfirmation($spid)`
  bajo ese tenant y actúa sobre **ese** estado (no sobre el payload). **Zero-trust sobre el
  estado**, no solo sobre la firma (modelo Stripe). **Elimina** la necesidad de guardar
  `alias_token` → desacopla el charge webhook de `persist_transactions`.

`'requery'` **no reemplaza** al resolver (la re-consulta igual firma con la llave del comercio
resuelto); resuelve el **acople** de R3 para charge.

> **Acuse de 30s (obligatorio en modo requery).** La re-query corre **dentro** del request del
> webhook, y Bancard exige acusar en **<30s** o da la confirmación por perdida. Por eso: timeout
> **corto** y configurable, muy por debajo de 30s (p.ej. 8s), y **fail-safe** — si la re-query
> tarda o falla → **200 + orden pending** (se reconcilia por el callback del browser / polling).
> **Nunca** bloquear el ack por la re-query. Es la razón por la que el default es `'token'`
> (síncrono, sin llamada HTTP saliente).

### R6 — Catastro por pull autenticado, per-tenant (Front 1b)

Convergencia vía `syncBancardCards()` (que usa `getUserCards` autenticado) bajo el tenant,
disparado por el consumidor al recibir `add_new_card_success` del iframe. Requiere:

- `SavedCard` gana scoping per-comercio en una **columna configurable**
  (`bancard.saved_cards_tenant_column`, default `tenant_ref`) → un consumidor que ya extendió
  `bancard_saved_cards` con su propia columna (p.ej. `commerce_id`) la **reutiliza** en vez de
  duplicar el concepto.
- El trait `HasBancardCards` acepta un **context explícito** (consumer-initiated → sin
  resolver-callback).
- El modelo de usuario/morph sigue siendo del consumidor (`syncBancardCards` ya usa
  `getMorphClass()`).

### R7 — Flags por-tenant (Front 1c, saliente)

`enable_3ds` (y futuros toggles) resolubles por tenant: el `BancardVPOSService` lee los flags
del **context/instancia**, no de `config('bancard.*')` global → habilita **flota mixta**
(unos comercios enrolados en 3DS, otros no). Es un refinamiento del camino **saliente**
(`chargeWithToken`), independiente del webhook (1a). Solo necesario para fleets mixtos.

### R8 — Titularidad de rutas

El consumidor usa las rutas del paquete (prefijo configurable en `bancard.webhook.route_prefix`)
y borra las suyas. El portal de Bancard apunta a la ruta del paquete. Todos los comercios pegan
a la **misma** ruta; el resolver desambigua por `shop_process_id`, que desde **v1.2.6 es CSPRNG
→ globalmente único entre tenants** (otro motivo por el que ese fix importaba).

## 5. Fases

| Fase | Alcance | Por qué |
|------|---------|---------|
| **1a — Webhook de pago multi-tenant** | R1, R2, R2b, R4 (tenantRef), R5 | **Linchpin de seguridad + convergencia.** Cierra el hueco de fraude y desbloquea que rga/tushop borren su webhook de pago. **No** requiere R7. |
| **1b — Catastro per-tenant** | R6 (SavedCard.tenant_ref + trait con context) | Pull autenticado, sin inbound sin-auth → menos urgente, más grande. |
| **1c — Flags saliente por-tenant** | R7 | Solo para fleets con 3DS mixto. Baja prioridad (rga es todo no-3DS). |

**1a es prerrequisito de la migración del consumidor (Front 2).** Faseando, un consumidor
puede **borrar su webhook de pago y cerrar el fraude apenas salga 1a**, sin esperar 1b/1c.

**Prerrequisito del consumidor: estar en ≥v1.2.6 antes de converger.** El resolver desambigua
por `shop_process_id`; su **unicidad cross-tenant** la garantiza el generador **CSPRNG de
v1.2.6** (R8). Con el generador viejo (`rand()`, 5 dígitos) hay riesgo bajo-pero-real de
**colisión cross-tenant** (dos comercios con el mismo `shop_process_id` → el resolver podría
resolver el comercio equivocado y rechazar un callback legítimo). Orden correcto: **upgrade a
v1.2.6 → mergear 1a → recién ahí borrar el webhook casero.** La ventana de ids viejos drena sola
a medida que esas órdenes se completan. (El fix del generador, que al principio parecía "no
sirve", resulta **prerrequisito** de la convergencia multi-tenant.)

## 6. Cambios de código (1a)

> Nota: al implementar, estos tres tipos quedaron bajo `src/Tenancy/` (no `src/Contracts`
> ni `src/Support`), namespace `Softlab180\Bancard\Tenancy\…`.

- `src/Tenancy/BancardTenantResolver.php` — interface (nuevo).
- `src/Tenancy/BancardTenantContext.php` — value object (nuevo).
- `src/Tenancy/GlobalTenantResolver.php` — default de llaves globales (nuevo, comportamiento no-breaking).
- `BancardVPOSService::forContext()` — factory por-tenant.
- `Http/Controllers/WebhookController.php` — resuelve el service por-request desde el resolver;
  **no** toca el Facade singleton; despacha con `tenantRef`.
- `Events/PaymentSucceeded`, `PaymentFailed` — `tenantRef` nullable al final.
- `config/bancard.php` — `tenant_resolver` (default `null`), `webhook_verification` (default
  `'token'`), `webhook_requery_timeout` (segundos, default 8, para el modo `requery`).
- Tests — E2E multi-tenant con comercio A (llaves ≠ global): single_buy y charge validan y
  disparan el evento; callback forjado se rechaza; `shop_process_id` desconocido → 200 sin
  procesar; y regresión single-tenant (sin resolver) idéntica.

## 7. Criterio de aceptación (Front 1a hecho)

El consumidor borra su `WebhookController` de pago y pasa un E2E con **comercio A (llaves ≠
global)**:

- `single_buy` y `charge` **validan** y marcan pagado **vía listener** (no vía código del
  webhook casero);
- un **replay** se deduplica (por la idempotencia del consumidor o del paquete);
- un **callback forjado** se rechaza;
- un `shop_process_id` **desconocido** → HTTP 200 sin procesar;
- **sin una línea de seguridad casera** en el consumidor, con `persist_transactions=false`.

## 8. No-breaking (garantía)

- Sin `bancard.tenant_resolver` → `GlobalTenantResolver` → llaves globales → single-tenant
  idéntico.
- `webhook_verification` default `'token'` → comportamiento actual.
- `tenantRef` en eventos es opcional al final → no rompe listeners existentes.
- La suite de tests actual debe quedar **verde sin cambios** (además de los tests nuevos
  multi-tenant).

## 9. Mapeo a la spec del consumidor

| Req spec | Cómo se cumple | Fase |
|----------|----------------|------|
| R1 Resolver (no atado a BancardTransaction) | `BancardTenantResolver::resolveByShopProcessId` | 1a |
| R2 (implícito) Mecanismo webhook per-tenant | `forContext()` + `WebhookController` sin singleton | 1a |
| R3 Idempotencia inyectable | `persist_transactions=false` + listener idempotente | 1a |
| R4 Eventos suficientes + `tenantRef` | eventos actuales + `tenantRef` nuevo; brand/last-4 vía listener | 1a |
| R5 Charge sin persistencia (re-query) | `webhook_verification='requery'` | 1a |
| R6 Catastro pull per-tenant | `SavedCard.tenant_ref` + trait con context | 1b |
| R7 Flags por-tenant | flags desde el context/instancia | 1c |
| R8 Titularidad de rutas | rutas del paquete + resolver por `shop_process_id` único (CSPRNG) | 1a |

## 10. Ítems abiertos

- **(7a) Mitigación interina** mientras converge: allow-list de IPs de Bancard en el edge +
  rate-limit (control **no-divergente**, sin seguridad casera en el consumidor). Depende de que
  Bancard tenga **IPs estables** — verificar.
- **(7b) Marca/últimos-4:** resuelto — los enriquece el listener desde el registro de tarjeta
  del consumidor; el paquete no los garantiza en la confirmación.
- **(7c) Portal — una URL por comercio o global:** **indiferente al diseño.** Todos pegan a la
  misma ruta y el resolver desambigua por `shop_process_id` (único desde v1.2.6).
