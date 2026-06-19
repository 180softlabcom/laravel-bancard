# Laravel Bancard

Integración del gateway de pagos **Bancard VPOS 2.0** (Paraguay) para Laravel: pago ocasional (single buy), catastro y pago con token (tarjetas guardadas), confirmación por webhook, rollback y conciliación.

> Verificado contra la documentación oficial *eCommerce – Compra Simple v1.23.1*.

## Instalación

El paquete se distribuye como repositorio Git (no está en Packagist).

### Opción A — desde GitHub (consumidores)

```jsonc
// composer.json del proyecto consumidor
"repositories": [
    { "type": "vcs", "url": "https://github.com/180softlabcom/laravel-bancard.git" }
],
"require": {
    "softlab180/laravel-bancard": "dev-main"
}
```

### Opción B — repo local (desarrollo del paquete)

```jsonc
"repositories": [
    { "type": "path", "url": "../packages/laravel-bancard", "options": { "symlink": true } }
],
"require": { "softlab180/laravel-bancard": "*" }
```

```bash
composer update softlab180/laravel-bancard
```

### Publicar configuración y migraciones

```bash
php artisan vendor:publish --tag=bancard-config
php artisan vendor:publish --tag=bancard-migrations   # opcional (ya se cargan automáticamente)
php artisan migrate
```

> **Importante:** desde la v1.1.0 hay una tabla nueva, `bancard_transactions` (idempotencia + conciliación). Corré `php artisan migrate` al actualizar. Si no querés persistencia, seteá `BANCARD_PERSIST_TRANSACTIONS=false`.

## Configuración

```env
BANCARD_PUBLIC_KEY=...
BANCARD_PRIVATE_KEY=...
BANCARD_ENVIRONMENT=staging            # staging | production
BANCARD_CURRENCY=PYG
BANCARD_FRONTEND_URL=https://miapp.com
BANCARD_RETURN_URL=/payment/result
BANCARD_CANCEL_URL=/payment/cancel
BANCARD_PERSIST_TRANSACTIONS=true      # registra cada operación para idempotencia/conciliación
BANCARD_CHECKOUT_SCRIPT_VERSION=4.0.0  # versión del SDK JS de checkout
BANCARD_USER_MODEL="App\\Models\\User"
```

## Uso

### 1. Implementar el contrato `Payable`

Cualquier modelo cobrable (Orden, Suscripción, Factura…) implementa `Payable`:

```php
use Softlab180\Bancard\Contracts\Payable;

class Order extends Model implements Payable
{
    public function getPayableId(): int|string { return $this->id; }
    public function getPayableAmount(): float|int { return $this->total; }   // p.ej. 150000 (Gs)
    public function getPayableCurrency(): string { return 'PYG'; }
    public function getPayableDescription(): string { return "Orden #{$this->id}"; }

    // El paquete llama estos hooks automáticamente:
    public function storeBancardPayment(array $data): void
    {
        // $data: shop_process_id, process_id, amount, currency
        $this->update(['bancard_process_id' => $data['shop_process_id']]);
    }
    public function markAsPaid(array $confirmationData): void { $this->update(['status' => 'paid']); }
    public function markAsFailed(array $errorData): void { $this->update(['status' => 'failed']); }
}
```

### 2. Pago ocasional (Single Buy)

```php
use Softlab180\Bancard\Facades\Bancard;

$result = Bancard::createSingleBuy($order, description: 'Pago de la orden');
// $result => shop_process_id, process_id, checkout_js_url, amount, currency, expires_at
```

En el frontend, renderizá el iframe con el SDK de Bancard (el `process_id` NO va como query string):

```html
<div id="bancard-checkout-container"></div>
<script src="{{ $result['checkout_js_url'] }}"></script>
<script>
  const styles = { /* ... */ };
  window.onload = () => Bancard.Checkout.createForm('bancard-checkout-container', '{{ $result['process_id'] }}', styles);
</script>
```

> `checkout_js_url` = `https://{env}/checkout/javascript/dist/bancard-checkout-4.0.0.js`.

### 3. Confirmación del pago (webhook)

Bancard hace un **POST servidor-a-servidor** a la URL de confirmación que cargás en el panel de comercios de vPOS. Apuntala a la ruta del paquete:

```
POST https://miapp.com/webhooks/bancard/payment
```

El paquete valida el token, **responde HTTP 200** (requisito de vPOS: si no recibe 200 en ≤30 s marca la confirmación como inválida) y dispara el evento correspondiente. Vos solo escuchás los eventos (ver más abajo). La idempotencia está cubierta: un callback reenviado no vuelve a disparar el evento.

### 4. Catastro de tarjetas (pago con token)

Bancard **no envía webhook** de catastro: el flujo se completa en el frontend (iframe) y luego se sincroniza con `users_cards`.

```php
// Backend: iniciar el catastro
use Softlab180\Bancard\Traits\HasBancardCards;

class User extends Authenticatable { use HasBancardCards; }

$result = $user->registerBancardCard(cardId: 1, returnUrl: route('cards.result'));
// $result => process_id, checkout_js_url
```

```html
<!-- Frontend: iframe de catastro -->
<div id="bancard-checkout-container"></div>
<script src="{{ $result['checkout_js_url'] }}"></script>
<script>
  window.onload = () => Bancard.Cards.createForm('bancard-checkout-container', '{{ $result['process_id'] }}', styles);
  // El iframe emite { status: "add_new_card_success" } o { status: "add_new_card_fail" }.
</script>
```

```php
// Backend: al recibir add_new_card_success, persistir las tarjetas del usuario
$cards = $user->syncBancardCards();   // llama users_cards y guarda los SavedCard (alias_token, etc.)
```

> `syncBancardCards()` usa el morph class del modelo, así que **soporta múltiples modelos de usuario**.

### 5. Cobrar con tarjeta guardada (charge / 3DS)

```php
$result = $user->chargeDefaultCard($order, numberOfPayments: 1, description: 'Pago mensual');
// o con una tarjeta específica:
$result = $user->chargeBancardCard($card, $order);
```

Si Bancard exige 3DS, `$result['requires_3ds'] === true` y devuelve `process_id` + `checkout_js_url` para renderizar el iframe de confirmación; la confirmación final llega al webhook `POST /webhooks/bancard/charge`.

### 6. Tarjetas, confirmación y rollback

```php
$user->bancardCards;                         // tarjetas locales (SavedCard)
$user->getBancardCards();                     // desde la API de Bancard
$user->deleteBancardCard($card->alias_token); // borra en Bancard y local

Bancard::getPaymentConfirmation($shopProcessId); // consultar estado (si no llegó el webhook)
Bancard::rollbackPayment($shopProcessId);        // reversar
```

## Eventos

- `PaymentSucceeded` — pago aprobado (single buy o charge)
- `PaymentFailed` — pago rechazado
- `CardRegistered` — tarjeta registrada
- `CardDeleted` — tarjeta eliminada

```php
class HandlePaymentSuccess
{
    public function handle(\Softlab180\Bancard\Events\PaymentSucceeded $event): void
    {
        $order = Order::where('bancard_process_id', $event->shopProcessId)->first();
        $order?->markAsPaid($event->response);
    }
}
```

## Idempotencia y conciliación

Con `persist_transactions` activo (default), el paquete registra cada operación en `bancard_transactions` (`shop_process_id` único). Esto permite:

- **Deduplicar** callbacks reenviados por vPOS (el webhook hace un *claim* atómico y no re-dispara el evento).
- **Conciliar** pagos perdidos: si no llegó el webhook, consultá `getPaymentConfirmation()` (vPOS recomienda esperar ~10 min) o `rollbackPayment()`.

## Webhooks

Rutas registradas automáticamente (prefijo configurable con `bancard.webhook.route_prefix`):

- `POST /webhooks/bancard/payment` — confirmación de pago (single buy)
- `POST /webhooks/bancard/charge` — confirmación de pago con token (3DS)
- `POST /webhooks/bancard/card-registration` — **no estándar**: Bancard no envía webhook de catastro; usá `syncBancardCards()`. Se mantiene solo para integraciones que lo configuren explícitamente.

Las rutas traen `throttle:60,1` por defecto (`bancard.webhook.middleware`). Excluí el prefijo de CSRF:

```php
// Laravel <= 10  (app/Http/Middleware/VerifyCsrfToken.php)
protected $except = ['webhooks/bancard/*'];

// Laravel 11+  (bootstrap/app.php)
->withMiddleware(fn ($m) => $m->validateCsrfTokens(except: ['webhooks/bancard/*']))
```

> Las rutas del paquete se cargan **sin** el grupo `web`, así que por defecto no aplican CSRF; el `$except` solo importa si agregás `web` al middleware.

## Testing

```bash
composer install
vendor/bin/phpunit
```

Ambiente de pruebas: `BANCARD_ENVIRONMENT=staging`. Cédula de prueba para el iframe de catastro: `9661000`.

## Licencia

MIT
