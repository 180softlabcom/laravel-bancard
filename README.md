# Laravel Bancard

Paquete Laravel para integración con Bancard VPOS 2.0 (Paraguay).

## Instalación

```bash
composer require softlab180/laravel-bancard
```

### Instalación desde GitHub (repositorio privado/desarrollo)

Añadir el repositorio en `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/180softlabcom/laravel-bancard.git"
        }
    ]
}
```

Luego instalar:

```bash
composer require softlab180/laravel-bancard:dev-main
```

### Publicar Configuración

```bash
php artisan vendor:publish --tag=bancard-config
```

### Publicar Migraciones (opcional)

```bash
php artisan vendor:publish --tag=bancard-migrations
```

### Ejecutar Migraciones

```bash
php artisan migrate
```

## Configuración

Añadir las siguientes variables de entorno en `.env`:

```env
BANCARD_PUBLIC_KEY=your_public_key
BANCARD_PRIVATE_KEY=your_private_key
BANCARD_ENVIRONMENT=staging  # o production
```

## Uso

### Single Buy (Compra Ocasional)

```php
use Softlab180\Bancard\Facades\Bancard;

// Tu modelo debe implementar Softlab180\Bancard\Contracts\Payable
$order = Order::find(1);

$result = Bancard::createSingleBuy(
    payable: $order,
    description: 'Compra en Mi Tienda',
    returnUrl: route('payment.success'),
    cancelUrl: route('payment.cancel')
);

// Redirigir al checkout de Bancard
$processId = $result['process_id'];
$checkoutUrl = Bancard::getCheckoutUrl() . "/new?process_id={$processId}";
```

### Implementar Payable Interface

```php
use Softlab180\Bancard\Contracts\Payable;

class Order extends Model implements Payable
{
    public function getPayableId(): int|string
    {
        return $this->id;
    }

    public function getPayableAmount(): float|int
    {
        return $this->total; // En guaraníes para PYG
    }

    public function getPayableCurrency(): string
    {
        return 'PYG';
    }

    public function getPayableDescription(): string
    {
        return "Orden #{$this->id}";
    }

    public function storeBancardPayment(array $paymentData): void
    {
        $this->update([
            'bancard_process_id' => $paymentData['process_id'],
            'payment_status' => 'pending',
        ]);
    }

    public function markAsPaid(array $confirmationData): void
    {
        $this->update([
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    public function markAsFailed(array $errorData): void
    {
        $this->update(['payment_status' => 'failed']);
    }
}
```

### Registro de Tarjetas (Zimple)

```php
use Softlab180\Bancard\Traits\HasBancardCards;

class User extends Authenticatable
{
    use HasBancardCards;
}

// Registrar nueva tarjeta
$result = $user->registerBancardCard(
    cardId: 1, // ID único para esta tarjeta
    returnUrl: route('cards.registered')
);

// Redirigir al formulario de Bancard
$checkoutUrl = Bancard::getCheckoutUrl() . "/register?process_id={$result['process_id']}";
```

### Cobrar con Tarjeta Guardada

```php
// Cobrar con la tarjeta por defecto
$result = $user->chargeDefaultCard(
    payable: $order,
    numberOfPayments: 1, // Cuotas
    description: 'Pago mensual'
);

// Cobrar con tarjeta específica
$card = $user->bancardCards()->first();
$result = $user->chargeBancardCard($card, $order);
```

### Obtener Tarjetas del Usuario

```php
// Desde la base de datos local
$cards = $user->bancardCards;

// Desde la API de Bancard
$cardsFromApi = $user->getBancardCards();
```

### Eliminar Tarjeta

```php
$card = $user->bancardCards()->first();
$user->deleteBancardCard($card->alias_token);
```

### Confirmar Estado de Pago

```php
$result = Bancard::getPaymentConfirmation($shopProcessId);

if ($result['confirmation']['response_code'] === '00') {
    // Pago exitoso
}
```

### Rollback de Pago

```php
$result = Bancard::rollbackPayment($shopProcessId);
```

## Eventos

El paquete dispara los siguientes eventos que puedes escuchar:

- `PaymentSucceeded` - Cuando un pago es exitoso
- `PaymentFailed` - Cuando un pago falla
- `CardRegistered` - Cuando se registra una tarjeta
- `CardDeleted` - Cuando se elimina una tarjeta

### Ejemplo de Listener

```php
// EventServiceProvider.php
protected $listen = [
    \Softlab180\Bancard\Events\PaymentSucceeded::class => [
        \App\Listeners\HandlePaymentSuccess::class,
    ],
];
```

```php
// HandlePaymentSuccess.php
class HandlePaymentSuccess
{
    public function handle(PaymentSucceeded $event): void
    {
        $shopProcessId = $event->shopProcessId;
        $order = Order::where('bancard_process_id', $shopProcessId)->first();

        if ($order) {
            $order->markAsPaid($event->response);
        }
    }
}
```

## Webhooks

Los webhooks se registran automáticamente en:

- `POST /webhooks/bancard/payment` - Confirmación de pago
- `POST /webhooks/bancard/card-registration` - Registro de tarjeta
- `POST /webhooks/bancard/charge` - Cobro con token (3DS)

Asegúrate de excluir estas rutas de la verificación CSRF en `app/Http/Middleware/VerifyCsrfToken.php`:

```php
protected $except = [
    'webhooks/bancard/*',
];
```

## Testing

Para testing, usa el ambiente `staging`:

```env
BANCARD_ENVIRONMENT=staging
```

Tarjetas de prueba en staging:
- Visa: 4111111111111111
- Mastercard: 5111111111111111

## Licencia

MIT
