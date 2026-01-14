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
        'middleware' => [], // Add 'api' or custom middleware if needed
        'log_payloads' => env('BANCARD_LOG_WEBHOOKS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto Save Cards
    |--------------------------------------------------------------------------
    |
    | Whether to automatically save cards to the database when registered.
    |
    */
    'auto_save_cards' => env('BANCARD_AUTO_SAVE_CARDS', true),

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
    | User Model
    |--------------------------------------------------------------------------
    |
    | The user model class for card registration.
    |
    */
    'user_model' => env('BANCARD_USER_MODEL', 'App\\Models\\User'),
];
