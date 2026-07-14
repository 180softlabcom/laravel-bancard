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

> **Importante:** desde la v1.1.0 hay una tabla nueva, `bancard_transactions` (idempotencia + conciliación), y la **v1.2.6** le agrega la columna `alias_token` (necesaria para validar el webhook de charge). Corré `php artisan migrate` al actualizar. Si no querés persistencia, seteá `BANCARD_PERSIST_TRANSACTIONS=false` — pero tené en cuenta que **el webhook de charge (pago con token) requiere `persist_transactions=true`** para poder guardar/recuperar el `alias_token` con el que valida el token.

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
BANCARD_ENABLE_3DS=false               # 3DS en charge (opt-in): requiere que Bancard habilite el producto 3DS al comercio
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

> **Retorno del browser:** el paquete agrega el `shop_process_id` como query param al `return_url` (y al `cancel_url`) — **también si pasás una URL explícita** (`Bancard::createSingleBuy($order, returnUrl: route('pagos.resultado'))`). Es el identificador con el que tu endpoint de retorno identifica la transacción y consulta `getPaymentConfirmation()`; no dependas de la sesión, que en el retorno cross-site no viaja.

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

#### 3DS en el pago ocasional

El 3DS del single buy es **totalmente transparente**: se sigue usando **`Bancard.Checkout.createForm`** — **no** hay cambio de método. Si el comercio está enrolado en 3DS del lado de Bancard y la tarjeta lo exige, el desafío se renderiza **dentro del mismo iframe de `Checkout`**; el comercio no toca nada. La llamada `single_buy`, el `process_id` y el `checkout_js_url` son idénticos con o sin 3DS, y el resultado llega igual al webhook `POST /webhooks/bancard/payment` (con `security_information` → `risk_index`, etc.).

> ⚠️ **No uses `Bancard.Charge3DS.createForm` para single_buy.** Ese método es exclusivo del **pago con token (charge)** — ver la sección 5. Para el pago ocasional, `Checkout.createForm` es siempre el método correcto.

> 📱 **WebView / mobile:** para que el desafío 3DS (que carga la página del emisor) renderice dentro de un WebView (React Native/Chromium), el WebView **debe** usar la URL base segura del servicio (spec pág. 75), p. ej. `source={{ html: getHtml(process_id), baseUrl: 'https://vpos.infonet.com.py' }}`. Con un esquema no seguro (`exp://`) el challenge no carga.

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

A diferencia del pago ocasional, el charge es una llamada servidor-a-servidor **sin iframe**. Si Bancard exige 3DS, `$result['requires_3ds'] === true` y devuelve `process_id` + `checkout_js_url`; **aquí sí** el frontend levanta un iframe de confirmación con **`Bancard.Charge3DS.createForm`** (este es el único flujo que usa `Charge3DS`):

```html
<div id="bancard-checkout-container"></div>
<script src="{{ $result['checkout_js_url'] }}"></script>
<script>
  window.onload = () => Bancard.Charge3DS.createForm('bancard-checkout-container', '{{ $result['process_id'] }}', styles);
</script>
```

La confirmación final llega al webhook `POST /webhooks/bancard/charge`.

> **3DS opcional por comercio (`BANCARD_ENABLE_3DS`).** El request de charge envía `extra_response_attributes: ["confirmation.process_id"]` **solo si `BANCARD_ENABLE_3DS=true`**. Ese parámetro **requiere que Bancard tenga habilitado el producto 3DS** para tu comercio; enviarlo sin ese permiso hace que Bancard **rechace la operación** (con riesgo en producción). Activá el flag únicamente cuando Bancard confirme el enrolamiento 3DS. Con el flag en `false` (default), el charge funciona como pago directo sin 3DS.

> 📱 **WebView / mobile:** igual que en el pago ocasional, el WebView debe usar `baseUrl: 'https://vpos.infonet.com.py'` para que el desafío 3DS renderice (spec pág. 75).

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

### Datos del evento y manejo de errores

`PaymentSucceeded` / `PaymentFailed` reciben:

- `shopProcessId` (string)
- `response` → `['confirmation' => [...]]` con los campos planos de la confirmación: `is_paid`, `response_code`, `response_description`, **`extended_response_description`** (motivo legible/detallado, p.ej. *"VALOR INCORRECTO DEL CVV2"*), `response_details`, `authorization_number`, `ticket_number`, `amount`, `currency`, `security_information`.
- `PaymentFailed` además trae `errorCode` (= `response_code`) y `errorMessage` (= `extended_response_description` ?? `response_description`, el motivo legible).

```php
public function handle(PaymentFailed $event): void
{
    Log::warning('Pago rechazado', [
        'shop_process_id' => $event->shopProcessId,
        'code'   => $event->errorCode,    // p.ej. "05"
        'reason' => $event->errorMessage, // motivo legible
    ]);
}
```

**Errores de operaciones del cliente** (`rollbackPayment`, `getPaymentConfirmation`, etc.): además del string `error`, el array trae **`raw_response`** con el payload crudo de Bancard, incluyendo `messages[].key` (código estructurado). Usalo para distinguir casos en vez de parsear texto — p.ej. una reversa fuera de ventana:

```php
$res = Bancard::rollbackPayment($shopProcessId);
$key = $res['raw_response']['messages'][0]['key'] ?? null; // p.ej. "AlreadyRollbackedError"
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

### Logging y seguridad

- **Logging del payload**: por defecto el webhook loguea el payload a nivel `info`. En producción seteá **`BANCARD_LOG_WEBHOOKS=false`** para loguear solo el `shop_process_id`. Desde v1.2.0 ese flag **sí** gatea el log del controller (antes el payload se logueaba igual). El payload de Bancard no trae PAN/CVV, pero es buena higiene.
- **Ruta de card-registration**: `/webhooks/bancard/card-registration` **no valida token** y el catastro no usa webhook (usá `syncBancardCards()`). Si solo usás single_buy / charge, **no la expongas** (no la cargues en el panel de Bancard) o protegela con IP allow-list.

## Testing

```bash
composer install
vendor/bin/phpunit
```

Ambiente de pruebas: `BANCARD_ENVIRONMENT=staging`. Cédula de prueba para el iframe de catastro: `9661000`.

## Licencia

MIT
