# Changelog

## [1.2.0] - 2026-06-22

### Added / Changed
- `processWebhook()` ahora propaga **`extended_response_description`** (motivo legible del rechazo, p.ej. "VALOR INCORRECTO DEL CVV2") y `response_details`, no solo el código. `PaymentFailed.errorMessage` usa el motivo detallado (`extended_response_description ?? response_description`).
- El log del payload del webhook ahora **respeta `BANCARD_LOG_WEBHOOKS`** (`bancard.webhook.log_payloads`): con `false` se loguea solo el `shop_process_id`. Antes el controller logueaba el payload completo siempre (independiente del flag).
- README ampliado para integradores/agentes: campos del evento, manejo de errores vía `raw_response.messages[].key`, logging y seguridad del webhook.

## [1.1.1] - 2026-06-22

### Fixed
- `bancard_transactions.payable_id` ahora es **CHAR(36)** (`nullableUuidMorphs`) en vez de BIGINT → soporta `Payable` con **ID entero y con UUID** (`recordTransaction()` siempre castea a string; un entero entra como texto, un UUID también). Antes, un Payable con UUID rompía el insert. Necesario p.ej. para consumidores con modelos UUID.

### Nota / limitación conocida
- `bancard_saved_cards.user_id` sigue siendo `unsignedBigInteger`. Sirve para modelos de usuario con ID entero (todos los consumidores actuales). Si en el futuro un consumidor usa **usuarios con UUID**, esa tabla requeriría el mismo tratamiento (string/uuid) — se difiere por ser una tabla ya publicada.

## [1.1.0] - 2026-06-15

### Fixed
- **Webhook de pago (crítico):** `WebhookController::handlePayment` leía `$result['status']`/`['operation']`, claves que `processWebhook()` nunca devuelve, por lo que **todo pago aprobado disparaba `PaymentFailed`**. Ahora se basa en `$result['is_paid']` y lee los campos planos (`shop_process_id`, `authorization_number`, `ticket_number`).
- **Acuse HTTP a Bancard (crítico):** los tres webhooks devolvían 400/401/500 en resultados de negocio. Como vPOS no reintenta de forma confiable, eso hacía que Bancard diera la confirmación por perdida. Ahora se responde **HTTP 200** (`{"status":"success"}`) ante cualquier resultado de negocio (aprobado/rechazado); 200 con `rejected` ante token inválido; 500 solo ante error genuino del servidor.
- **URL del SDK de checkout (crítico):** `buildCheckoutScriptUrl()` apuntaba a `/checkout/js/bancard-checkout-{v2|sandbox}.js` (HTTP 404). Ahora usa la ruta real `/checkout/javascript/dist/bancard-checkout-{version}.js` (HTTP 200), sin el query `?process_id=`. Versión configurable vía `bancard.checkout_script_version` (default `4.0.0`).
- **URL del iframe:** `buildCheckoutUrl()` devolvía `/checkout/new?process_id=` (404). Ahora `/checkout/{process_id}`.
- **Registro de tarjeta:** `handleCardRegistration` derivaba `user_id`/`card_id` de un `shop_process_id` con formato que `initiateCardRegistration` nunca genera, por lo que **la tarjeta nunca se guardaba** (ids = 0). Ahora se leen como campos discretos del `operation`.
- **`shop_process_id`:** `time().rand(10000,99999)` colisionaba bajo ráfaga. Ahora id numérico de 15 dígitos con `random_int` (CSPRNG).
- **Validación de token:** comparación con `hash_equals` (tiempo constante) en lugar de `===`.
- **`return_url`/`cancel_url`:** se agrega `shop_process_id` con el separador correcto (`?`/`&`); `currency` con fallback `?:` (cubre string vacío).

### Changed
- `composer.json`: se elimina la clave `version` hardcodeada (la versión se deriva del tag de git). Soporte declarado para Laravel hasta 13 y tooling de tests ampliado.
- Las rutas de webhook traen `throttle:60,1` por defecto.

### Added
- Suite de tests (PHPUnit/Testbench) que cubre el webhook de pago (aprobado/rechazado/token inválido) y el servicio (URLs, unicidad de `shop_process_id`, forma del `processWebhook`).

### Verificado contra el PDF oficial (eCommerce compra simple v1.23.1)
- Las **8 fórmulas de token** (single_buy, confirm, get_confirmation, rollback, cards/new, users_cards, charge, delete_card) coinciden con el spec.
- **Confirmación de pago**: el comercio debe responder **HTTP 200 dentro de 30 s** o vPOS marca la confirmación como inválida (sección "Buy Single Confirm"). El cuerpo esperado es `{"status":"success"}`. El fix de este release cumple ambos.
- **Checkout/Catastro JS**: `/checkout/javascript/dist/bancard-checkout-4.0.0.js`, con `process_id` pasado a `createForm` (no como query). Confirmado.
- `delete_card` usa `alias_token` (el spec lo llama `card_token` en la fórmula, pero es el mismo valor): correcto.

### Added (cierre de pendientes)
- **Catastro documentado:** `HasBancardCards::syncBancardCards()` implementa el flujo oficial del PDF (iframe `Bancard.Cards.createForm` → mensaje `add_new_card_success` → `users_cards` → persistir el `alias_token`). No depende de un webhook. Usa `getMorphClass()`/`getKey()`, por lo que **soporta múltiples modelos de usuario / morph maps** (resuelve el `user_type` polimórfico).
- **Persistencia + idempotencia:** nuevo modelo/tabla `BancardTransaction`. `createSingleBuy`/`chargeWithToken` registran cada operación (y ahora sí invocan `storeBancardPayment()` del `Payable`). El webhook hace un **claim atómico** por `shop_process_id`: un callback reenviado por vPOS se acusa con 200 **sin volver a despachar** el evento (evita doble fulfillment). Configurable con `bancard.persist_transactions`.
- El handler `handleCardRegistration` se mantiene por compatibilidad (integraciones no estándar), pero el flujo recomendado es `syncBancardCards()`.

### Migraciones
- Nueva tabla `bancard_transactions`. Al actualizar, publicá/corré las migraciones del paquete (`php artisan migrate`). Si no querés persistencia, seteá `BANCARD_PERSIST_TRANSACTIONS=false`.

### Tests
- 9 tests / 76 assertions (PHPUnit/Testbench): webhook aprobado/rechazado/token-inválido/**duplicado (idempotencia)**, URLs del SDK, unicidad de `shop_process_id`, forma de `processWebhook`, y **sync de catastro** (persiste con el morph correcto).
