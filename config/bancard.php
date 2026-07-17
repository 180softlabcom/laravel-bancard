<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bancard API Credentials
    |--------------------------------------------------------------------------
    |
    | Your Bancard VPOS API credentials. You can obtain these from your
    | Bancard merchant account.
    |
    */
    'public_key' => env('BANCARD_PUBLIC_KEY'),
    'private_key' => env('BANCARD_PRIVATE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Set to 'production' for live payments or 'staging' for testing.
    |
    */
    'environment' => env('BANCARD_ENVIRONMENT', 'staging'),

    /*
    |--------------------------------------------------------------------------
    | API URLs
    |--------------------------------------------------------------------------
    |
    | The base URLs for Bancard VPOS API endpoints.
    |
    */
    'urls' => [
        'staging' => 'https://vpos.infonet.com.py:8888',
        'production' => 'https://vpos.infonet.com.py',
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout URLs
    |--------------------------------------------------------------------------
    |
    | URLs for the Bancard checkout iframe and scripts.
    |
    */
    'checkout_urls' => [
        'staging' => 'https://vpos.infonet.com.py:8888/checkout',
        'production' => 'https://vpos.infonet.com.py/checkout',
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout Script Version
    |--------------------------------------------------------------------------
    |
    | Version of the Bancard checkout SDK served at
    | {checkout}/javascript/dist/bancard-checkout-{version}.js
    |
    */
    'checkout_script_version' => env('BANCARD_CHECKOUT_SCRIPT_VERSION', '4.0.0'),

    /*
    |--------------------------------------------------------------------------
    | 3D Secure (opcional, por comercio)
    |--------------------------------------------------------------------------
    |
    | Habilita el flujo 3DS en el charge (pago con token): cuando está activo, el
    | request de charge envía extra_response_attributes=['confirmation.process_id']
    | para que Bancard devuelva el process_id del desafío 3DS.
    |
    | IMPORTANTE: ese parámetro REQUIERE que Bancard tenga habilitado el producto
    | 3DS para tu comercio. Enviarlo sin el permiso hace que Bancard RECHACE la
    | operación (con riesgo en producción; reportado por Bancard en homologación).
    | Por eso el default es false: activalo (BANCARD_ENABLE_3DS=true) solo cuando
    | Bancard confirme el enrolamiento 3DS del comercio.
    |
    */
    'enable_3ds' => env('BANCARD_ENABLE_3DS', false),

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | The default currency for transactions. PYG for Paraguayan Guarani.
    |
    */
    'currency' => env('BANCARD_CURRENCY', 'PYG'),

    /*
    |--------------------------------------------------------------------------
    | Payment Expiration
    |--------------------------------------------------------------------------
    |
    | How long (in minutes) a payment session remains valid.
    |
    */
    'payment_expiration_minutes' => env('BANCARD_PAYMENT_EXPIRATION', 30),

    /*
    |--------------------------------------------------------------------------
    | Webhook Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for webhook handling.
    |
    */
    'webhook' => [
        'route_prefix' => env('BANCARD_WEBHOOK_PREFIX', 'webhooks/bancard'),
        'middleware' => ['throttle:60,1'], // Defensa básica. Sumá IP allow-list / verificación de firma para el endpoint de registro de tarjeta (no autenticado aún).
        'log_payloads' => env('BANCARD_LOG_WEBHOOKS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Persist Transactions (idempotencia + conciliación)
    |--------------------------------------------------------------------------
    |
    | Si está activo, el paquete registra cada operación en la tabla
    | bancard_transactions (requiere correr la migración). Permite deduplicar
    | callbacks reenviados por vPOS y conciliar pagos perdidos.
    |
    */
    'persist_transactions' => env('BANCARD_PERSIST_TRANSACTIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Idempotency Store (dedup del webhook, independiente de persist_transactions)
    |--------------------------------------------------------------------------
    |
    | La idempotencia del webhook (deduplicar un callback reenviado por vPOS o un
    | replay) es SIEMPRE activa, no depende de persist_transactions: el paquete
    | reclama atómicamente cada shop_process_id antes de despachar el evento, y guarda
    | el alias_token del charge para poder validarlo sin la tabla de transacciones
    | completa. Así un consumidor con persist_transactions=false igual queda protegido
    | contra el doble-procesamiento (no necesita idempotencia propia en su listener).
    |
    | Valor: class-string (o instancia) que implemente
    | Softlab180\Bancard\Contracts\BancardIdempotencyStore. Si es null, se usa el
    | EloquentIdempotencyStore (tabla bancard_processed_callbacks; requiere migrar).
    | Enchufá el tuyo (Redis, tu propia tabla) apuntando esta config.
    |
    */
    'idempotency_store' => env('BANCARD_IDEMPOTENCY_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Tenant Resolver (multi-tenant)
    |--------------------------------------------------------------------------
    |
    | Resolver de comercio para el webhook ENTRANTE. En multi-tenant cada comercio
    | tiene sus propias llaves; el token de cada callback se firmó con la llave de
    | ese comercio, así que el webhook debe resolver QUÉ comercio es (por
    | shop_process_id) y validar con SUS llaves — no con las globales.
    |
    | Valor: class-string (o instancia) que implemente
    | Softlab180\Bancard\Tenancy\BancardTenantResolver. Si es null, se usa el
    | GlobalTenantResolver (llaves globales) → el comportamiento single-tenant NO
    | cambia. Ver docs/multi-tenant.md.
    |
    */
    'tenant_resolver' => env('BANCARD_TENANT_RESOLVER'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Verification Mode
    |--------------------------------------------------------------------------
    |
    | Cómo el webhook confirma que un callback es genuino:
    | - 'token'   (default): valida la firma MD5 del payload. El alias_token del charge
    |             (que Bancard no manda en el callback) lo recupera del idempotency_store,
    |             así que ya NO requiere persist_transactions=true.
    | - 'requery': ignora el payload y re-consulta el estado autoritativo a Bancard
    |             (getPaymentConfirmation) bajo el tenant. Zero-trust sobre el estado;
    |             no requiere guardar el alias_token. Corre DENTRO del request del
    |             webhook, que debe acusar en <30s: usa webhook_requery_timeout y, si
    |             la consulta falla/timeoutea, acusa 200 + deja la orden pending.
    |
    */
    'webhook_verification' => env('BANCARD_WEBHOOK_VERIFICATION', 'token'),
    'webhook_requery_timeout' => (int) env('BANCARD_WEBHOOK_REQUERY_TIMEOUT', 8),

    /*
    |--------------------------------------------------------------------------
    | Frontend URLs
    |--------------------------------------------------------------------------
    |
    | URLs for redirecting after payment completion.
    |
    */
    'frontend_url' => env('BANCARD_FRONTEND_URL', env('APP_URL')),
    'return_url' => env('BANCARD_RETURN_URL', '/payment/result'),
    'cancel_url' => env('BANCARD_CANCEL_URL', '/payment/cancel'),

    /*
    |--------------------------------------------------------------------------
    | Saved Cards Table
    |--------------------------------------------------------------------------
    |
    | The database table name for storing customer saved cards.
    |
    */
    'saved_cards_table' => 'bancard_saved_cards',

    /*
    |--------------------------------------------------------------------------
    | Saved Cards Tenant Column (multi-tenant)
    |--------------------------------------------------------------------------
    |
    | Columna de bancard_saved_cards que scopea la tarjeta por comercio (multi-tenant).
    | El trait la setea con el tenantRef del BancardTenantContext al sincronizar, y
    | scopea las consultas por ella. Cambiala si tu tabla ya tiene una columna equivalente
    | (p.ej. 'commerce_id') para no duplicar el concepto. En single-tenant queda null.
    |
    */
    'saved_cards_tenant_column' => env('BANCARD_SAVED_CARDS_TENANT_COLUMN', 'tenant_ref'),

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The user model class for card registration.
    |
    */
    'user_model' => env('BANCARD_USER_MODEL', 'App\\Models\\User'),
];
