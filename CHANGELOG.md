# Changelog

## [2.2.1] - 2026-07-18

> Completa v2.2.0 para consumidores que usan el **service de bajo nivel** (`BancardVPOSService`) en vez del trait `HasBancardCards`. El refresh de alias de v2.2.0 vivía solo en el trait, así que un consumidor que cobra/borra con `chargeWithToken()`/`deleteCard()` directo seguía expuesto al `alias_token` vencido (`CardAliasTokenExpiredError`).

### Added
- **`BancardVPOSService::freshAliasToken($userId, $cardIdentity): ?string`** (público). Pide un `alias_token` fresco a Bancard (`users_cards`) y matchea la tarjeta por identidad estable (`card_id`, o `card_masked_number` + `expiration_date`). Devuelve null si la tarjeta ya no está catastrada. Un consumidor del service directo lo usa antes de cada charge/delete, sin reimplementar el matching. El trait `HasBancardCards` ahora **delega** en este método (una sola fuente de verdad).

### Notes
- Aditivo y no-breaking. 50 tests, 5136 assertions.

## [2.2.0] - 2026-07-18

> El `alias_token` de Bancard es **efímero**: la spec (pág. 35) lo describe como *"alias token temporal"*, con **validez para una sola operación** y **TTL del orden de minutos**. El paquete lo trataba como un token persistente (lo guardaba y cobraba/borraba con él más tarde) → `CardAliasTokenExpiredError` en producción (charge y delete). Esta versión adopta el modelo correcto: el alias es descartable; se pide uno fresco a Bancard justo antes de cada operación.

### Changed
- **`chargeDefaultCard` / `chargeBancardCard` piden un `alias_token` fresco antes de cobrar.** Internamente llaman `users_cards`, matchean la tarjeta por su **identidad estable** (`card_id`, o `card_masked_number` + `expiration_date`) y cobran con el alias vigente. La API para el consumidor **no cambia**. Si la tarjeta ya no está catastrada en Bancard, lanzan una excepción clara.
- **`deleteBancardCard` refresca el alias antes de borrar** (mismo motivo: el delete firma con el `alias_token`). Ahora es **idempotente**: si la tarjeta ya no está en Bancard, limpia el registro local y acusa éxito sin fallar.
- **`syncBancardCards` deduplica por identidad estable (`card_id`), no por `alias_token`.** Antes keyeaba el `firstOrNew` por `alias_token`; como el alias cambia en cada `users_cards`, cada sync **creaba una tarjeta local duplicada**. Ahora re-sincronizar actualiza la misma fila.

### Migración
- **Sin cambios de esquema ni de API.** Un consumidor que hoy cobra/borra con el `alias_token` guardado vía el trait (`chargeDefaultCard`/`chargeBancardCard`/`deleteBancardCard`) pasa a funcionar de forma confiable sin tocar su código. **No** cobres/borres con el alias guardado llamando al service (`Bancard::`) directamente: usá los métodos del trait, que refrescan. Cada charge/delete ahora hace una llamada extra a `users_cards`.

### Notes
- 47 tests, 5133 assertions (Laravel 12 y 13).

## [2.1.2] - 2026-07-18

> Requisito de certificación de Bancard: el webhook debe responder **exactamente** `{"status":"success"}` (HTTP 200), sin campos extra (doc eCommerce Bancard, pág. 44). El paquete agregaba `unresolved`/`duplicate`/`pending` al body y devolvía `{"status":"rejected"}` para token inválido.

### Changed
- **El webhook responde siempre `{"status":"success"}` (HTTP 200) en todos los caminos no-error** (procesado, duplicado, no-resuelto, pending de re-query, token inválido). La distinción de cada caso pasa del body a los **logs** (`Log::info`/`Log::warning` con el `shop_process_id`). Antes el body variaba (`unresolved`/`duplicate`/`pending`/`rejected`), lo que (a) violaba el formato que Bancard exige para certificar y (b) era un **oráculo de enumeración** (un atacante distinguía id desconocido / token forjado / procesado por la respuesta). Ahora los caminos son indistinguibles desde afuera. El **500** genuino (error inesperado) se mantiene: la doc lo contempla (no-200 → el comercio reconcilia con `get_confirmation`).

### Notes
- Cambio de superficie en la RESPUESTA del webhook (no en el request ni en los eventos): un consumidor que dependiera de `duplicate`/`unresolved`/`pending` en el body debe leer esa señal de los logs. Los eventos `PaymentSucceeded`/`PaymentFailed` no cambian.
- 43 tests, 5123 assertions.

## [2.1.1] - 2026-07-17

> Bug crítico en Laravel ≤12: el webhook validaba el token, devolvía 200, pero el evento **nunca se despachaba** → la orden **nunca se marcaba pagada** (falla silenciosa de pagos). Solo afectaba a consumidores en Laravel ≤12 (v12.39.0 y anteriores); los tests del paquete no lo detectaban porque testbench corría Laravel 13.

### Fixed
- **`dispatch()` de eventos con argumentos posicionales, no nombrados.** `WebhookController` despachaba `PaymentSucceeded`/`PaymentFailed` con **argumentos nombrados** (`dispatch(shopProcessId: …)`). El trait `Illuminate\Foundation\Events\Dispatchable` de **Laravel ≤12** declara `dispatch()` **sin parámetros** (lee los args con `func_get_args()`), así que un argumento nombrado lanza `Error: Unknown named parameter $shopProcessId` en tiempo de llamada. Laravel 13 lo hizo **variádico** (`dispatch(...$arguments)`) → por eso el bug era invisible en la suite (testbench 13). El error caía en el `try/catch` del webhook → se logueaba "un listener falló" y se acusaba 200, dejando el pago sin procesar. Ahora se despacha **posicional**, que funciona en todo el rango soportado (`^9`…`^13`).

### CI / Tooling
- **Matriz de CI (GitHub Actions) en Laravel 12 y 13.** El paquete no tenía CI: solo se probaba en el Laravel local del mantenedor (13). Ahora la suite corre en **Laravel 12** (testbench 10) y **13** (testbench 11) en cada push/PR, para agarrar incompatibilidades por versión antes de publicar.
- **Test de regresión portable** (`EventDispatchCompatTest`) que reproduce la firma param-less de `dispatch()` de Laravel ≤12 y fija el contrato (nombrado rompe, posicional funciona), independiente de la versión instalada.
- Quitado el campo `version` hardcodeado de `composer.json` (la versión la define el git tag).

### Notes
- 43 tests, 5123 assertions (en Laravel 12 y 13).

## [2.1.0] - 2026-07-17

> Cierra en **código** el último eslabón de H2 (replay/doble-procesamiento): la idempotencia del webhook deja de depender de `persist_transactions` (y de la disciplina del listener del consumidor) y pasa a ser un guard **siempre-activo** del paquete. Alineado con el objetivo de que el paquete sea la única forma segura de usar Bancard, sin forzar el modelo de datos completo.

### Added
- **Idempotencia siempre-activa e inyectable (`BancardIdempotencyStore`).** El webhook deduplica el `shop_process_id` de forma **atómica en todos los casos**, independiente de `persist_transactions`: un reenvío de vPOS o un replay se acusa `duplicate` sin re-despachar. Antes el dedup vivía en `bancard_transactions` (solo con `persist_transactions=true`), así que un consumidor con `persist=false` quedaba sin guard salvo que su listener fuera idempotente — eso ya no aplica. Default `EloquentIdempotencyStore` (nueva tabla `bancard_processed_callbacks`, `php artisan migrate`); **inyectable** vía `bancard.idempotency_store` (Redis, tabla propia, etc.).
- **El charge valida sin `persist_transactions`.** El `alias_token` con el que se firma el token del webhook de charge (Bancard no lo manda en el callback) ahora lo guarda el store **al cobrar**, así que el modo `token` valida el charge aun con `persist_transactions=false` (antes requería la tabla de transacciones).

### Fixed
- **Columna de tenant configurable + mass-assignment (`SavedCard`).** `$fillable` hardcodeaba `tenant_ref`; un consumidor que apunta `bancard.saved_cards_tenant_column` a otra columna (p.ej. `commerce_id`) y usa `create()`/`fill()` la perdía **en silencio** → tarjeta con tenant NULL (fuga cross-tenant). Ahora la columna configurada se agrega dinámicamente a `$fillable`. Nuevo test del path con columna ≠ `tenant_ref`.

### Migración
- **Nueva migración** `create_bancard_processed_callbacks_table`. Corré `php artisan migrate`. Es aditiva y no-breaking: single-tenant y multi-tenant siguen igual, solo suman el guard de idempotencia.

### Notes
- 41 tests, 5118 assertions.

## [2.0.1] - 2026-07-17

> Endurecimiento tras una revisión adversarial (red-team) de un agente consumidor. Un fix de seguridad en el código; el resto endurece la documentación para consumidores.

### Security
- **El token del webhook ya no se loguea en claro.** `operation.token` es una credencial **reutilizable por transacción** (no depende del resultado): si aparece en los logs, quien lo lea puede forjar un callback "pagado" válido. Ahora se **enmascara** en `logReceived` y en el `catch` de error (`maskedPayload()`), igual que el saliente ya enmascaraba `logRequest`. Test: `test_el_webhook_no_loguea_el_token_en_claro`.

### Docs
- **Replay / payload forjado (guía de migración).** El token autentica el **origen** pero **no firma** `response`/`response_code`; la protección contra "marcar pagado indebidamente" es la **idempotencia** (o el modo `requery`). `docs/consumer-migration.md` documenta las 3 configuraciones y cuál es segura: `persist_transactions=true` (dedup atómico, default), `webhook_verification=requery` (ignora el payload, inmune a forjado), y `persist=false`+`token` (la idempotencia queda **entera en el listener**, que debe deduplicar por `shop_process_id` en **cualquier** resultado).
- **Footguns Front 2:** setear `BANCARD_SAVED_CARDS_TENANT_COLUMN` **antes** de `migrate` (si no, columna `tenant_ref` fantasma); aviso prominente de que reapuntar la URL del portal es **breaking** (404 = cobro real, orden impaga); los campos del resolver de ejemplo son **ilustrativos**; el listener de `PaymentFailed` de ejemplo **persiste** la fila fallida con el motivo (`extended_response_description`).
- **Doc drift en `docs/multi-tenant.md`:** marcado como implementado en v2.0.0 y aclarado que es un release **breaking en superficie** (una sola ruta `/confirmation`, sin `CardRegistered`, sin webhook de catastro, sin `auto_save_cards`); rutas de tipos corregidas a `src/Tenancy`; "no-breaking" aclarado como **comportamiento** single-tenant.

## [2.0.0] - 2026-07-17

> **Corrige el modelo de webhooks de Bancard, que estaba mal.** Bancard tiene **UN solo webhook** (la "URL de confirmación" del portal) por el que llegan single_buy **y** charge/3DS; **no** hay webhooks separados por tipo ni webhook de catastro. El paquete modelaba de más (3 rutas). Es breaking por eso, aunque en la práctica los consumidores actuales no usan estas rutas (rodaron su propio webhook). Ver `docs/multi-tenant.md`.

### Added
- **Soporte multi-tenant en el webhook (Front 1a).** El webhook resuelve, por `shop_process_id`, QUÉ comercio es dueño de cada callback y valida el token con **SUS** llaves (no las globales del singleton). Config `bancard.tenant_resolver` (class-string o instancia de `Softlab180\Bancard\Tenancy\BancardTenantResolver`); sin resolver configurado usa el `GlobalTenantResolver` (llaves globales) → **single-tenant sin cambios de comportamiento**. Los eventos `PaymentSucceeded`/`PaymentFailed` ganan `tenantRef`. Cierra el bloqueo estructural que obligaba a los proyectos multi-comercio a rodar su propio webhook.
- **Modo de verificación `requery` (opt-in).** `bancard.webhook_verification='requery'`: en vez de validar la firma del payload, re-consulta el estado autoritativo a Bancard (`getPaymentConfirmation`) bajo el tenant resuelto. Zero-trust sobre el estado; **no requiere** guardar el `alias_token` (desacopla el charge de `persist_transactions`). Timeout acotado (`webhook_requery_timeout`, default 8s, <30s) con fail-safe a *pending*.
- **Catastro/tarjetas per-tenant (Front 1b).** `HasBancardCards` acepta un `?BancardTenantContext $tenant` explícito en cada método (catastro es consumer-initiated): con tenant opera con las llaves del comercio y **scopea** las tarjetas; sin tenant, single-tenant como antes. `SavedCard` gana la columna de scoping **configurable** `bancard.saved_cards_tenant_column` (default `tenant_ref`, para reutilizar un `commerce_id` existente) + scope `forTenant`; `setAsDefault()` también scopea por comercio (elegir la default en un comercio no toca la de otro). Nueva migración `add_tenant_ref_to_bancard_saved_cards` (`php artisan migrate`).
- **Flags salientes por-tenant (Front 1c).** `BancardVPOSService` lee `enable_3ds` (y futuros toggles) del `BancardTenantContext` (`flag()`), con fallback a `config('bancard.*')` global → habilita **flota mixta** (unos comercios con 3DS, otros no) sin cambiar el single-tenant.

### Changed / Removed (breaking)
- **UN solo webhook: `POST /webhooks/bancard/confirmation`.** Las rutas `/payment` y `/charge` (con `handlePayment`/`handleChargeWithToken`) se **colapsaron** en una sola ruta y handler (`handleConfirmation`), porque Bancard usa una única URL de confirmación para ambos flujos. **⚠️ Migración:** en el portal de Bancard, apuntá la URL de confirmación a `.../webhooks/bancard/confirmation`.
- **Eliminado el webhook de catastro (código muerto).** Bancard **no** envía webhook de catastro (es local: iframe + `syncBancardCards()`). Se removieron la ruta `/card-registration`, el handler `handleCardRegistration`, el evento `CardRegistered` y los config `auto_save_cards` / `card_registration_webhook_enabled`. **⚠️ Migración:** el catastro se persiste con `syncBancardCards()` tras el `add_new_card_success` del iframe.

### Security
- **Fail-closed ante private key vacía:** la validación de token rechaza si no hay secreto configurado (una llave vacía haría el token forjable con datos públicos).
- Un **listener síncrono que lanza** ya no convierte el webhook en HTTP 500 (se loguea y se acusa 200, para no perder el ack).

### Notes
- Verificado con **dos pasadas de revisión adversarial multi-agente**. 30 tests.

## [1.2.6] - 2026-07-14

### Fixed
- **`shop_process_id` con cero inicial → confirmación rechazada (crítico, financiero, intermitente ~1/10).** `generateShopProcessId()` usaba `substr(time(), -6)` como prefijo, que empieza con `0` ~10% de las veces. Bancard devuelve el `shop_process_id` como **número JSON** en el webhook (`"053708855743773"` → `53708855743773`, pierde el cero). Con el cero perdido, el token recalculado no coincide (`Invalid token` → un pago **aprobado** se rechaza, con riesgo de rollback) y falla el lookup de la transacción. Ahora el primer dígito es **1-9** (round-trip por número sin pérdida; 15 dígitos < 2^53). Afecta single_buy y charge.
- **Webhook de charge: token no validaba porque falta el `alias_token` en el payload (crítico).** El token de confirmación de un charge se firma con el `alias_token`, que Bancard **no envía** en el callback, así que `handleChargeWithToken` validaba con `''` → siempre rechazaba (pago aprobado sin evento, orden impaga). Ahora el paquete **persiste el `alias_token`** al cobrar (nueva columna `bancard_transactions.alias_token`) y lo **recupera por `shop_process_id`** en el webhook para validar. Aplica a ambas rutas (`/payment` y `/charge`).

### Migración
- **Nueva migración** `add_alias_token_to_bancard_transactions_table`. Corré `php artisan migrate` al actualizar. El webhook de charge **requiere `persist_transactions=true`** (default) para poder guardar/recuperar el `alias_token`; sin persistencia no puede validar el token de charge.

## [1.2.5] - 2026-07-13

### Fixed
- **`return_url`/`cancel_url` sin `shop_process_id` cuando el caller pasa una URL explícita (severidad alta).** `createSingleBuy()` y `chargeWithToken()` solo agregaban el `shop_process_id` a la URL de retorno en la rama por defecto; si el caller pasaba su propio `returnUrl`/`cancelUrl`, se enviaba tal cual. Como el paquete genera el `shop_process_id` internamente, el caller no puede incluirlo, así que su URL de retorno **nunca lo llevaba** → en el retorno del browser (contexto cross-site del iframe/redirect, sin cookie de sesión) el comercio no podía identificar la transacción y mostraba el pago como **fallido pese al cobro aprobado**. Ahora el `shop_process_id` se agrega **siempre** (a la URL provista o a la default), vía el helper `appendShopProcessId()`.
- De paso, en `chargeWithToken()`: se corrige el `?` **hardcodeado** (rompía si `bancard.return_url` ya traía query → `...?a=b?shop_process_id=`) y se agrega el `rtrim` del `frontend_url` (evita doble slash). Ambos métodos usan ahora el mismo helper: separador `?`/`&` correcto, `rtrim`, e **idempotente** (no duplica si la URL ya trae `shop_process_id=`).

## [1.2.4] - 2026-07-13

### Fixed
- **Webhook: confirmación rechazada como "Invalid token" (crítico, financiero).** Bancard usa **una sola "URL de confirmación"** en el portal y por ahí llegan **ambos** tipos de callback (single_buy → fórmula `confirm`; charge/3DS → fórmula `charge` + `alias_token`). El paquete validaba una sola fórmula por ruta: si el portal apuntaba a `/webhooks/bancard/charge`, la confirmación de un **single_buy aprobado** (sin `alias_token`) se rechazaba (`{"status":"rejected"}`), el evento `PaymentSucceeded` **nunca se disparaba** y la orden quedaba impaga pese al cobro real (con riesgo de rollback por vPOS). Ahora **ambos** endpoints (`/payment` y `/charge`) aceptan **cualquiera** de las dos fórmulas, vía el nuevo método público `Bancard::validateConfirmationToken()`. Es robusto sin importar qué URL configure el portal.

## [1.2.3] - 2026-07-13

### Changed (corrección de v1.2.1)
- **`extra_response_attributes` del charge ahora es OPT-IN (`bancard.enable_3ds`, default `false`).** La v1.2.1 lo enviaba **siempre**, pero **Bancard reportó en homologación** que ese parámetro **requiere el producto 3DS habilitado para el comercio**: enviarlo sin el permiso hace que Bancard **rechace la operación** (con riesgo en producción). Ahora solo se envía si `BANCARD_ENABLE_3DS=true`.
  - ⚠️ **Migración:** los comercios que **ya usan 3DS** deben setear `BANCARD_ENABLE_3DS=true` (y confirmar el enrolamiento con Bancard). Los comercios **sin** 3DS no envían el parámetro y evitan el rechazo — no requieren acción.

## [1.2.2] - 2026-07-07

### Docs (corrección)
- **Corrige el método 3DS del pago ocasional en el README/AGENTS.** La v1.2.1 decía —erróneamente— que el single_buy con 3DS usa `Bancard.Charge3DS.createForm`. **No es así:** el single_buy usa **siempre `Bancard.Checkout.createForm`** y el 3DS es transparente (el desafío se renderiza dentro del mismo iframe cuando el comercio está enrolado). `Charge3DS.createForm` es **exclusivo del pago con token (charge)**. Verificado contra el frontend de integración de referencia y contra el PDF (única sección "Flujo 3D SECURE" = "Pago con token - Charge", pág. 39-41).
- Agrega la nota de **WebView/mobile**: el `baseUrl` del WebView debe ser `https://vpos.infonet.com.py` para que el desafío 3DS renderice (spec pág. 75).

> El fix de código de la v1.2.1 (`extra_response_attributes` en charge) es correcto y se mantiene. Esta versión solo corrige documentación.

## [1.2.1] - 2026-07-07

### Fixed
- **3DS de charge (crítico):** `chargeWithToken()` volvió a enviar `extra_response_attributes: ["confirmation.process_id"]` en el request. La spec (pág. 37) lo marca como **obligatorio** ("Siempre enviar este dato"): sin él, Bancard no devuelve `confirmation.process_id`, por lo que un cobro con token que exige 3DS caía en la rama *Payment rejected* en vez de disparar `requires_3ds`. Este atributo se había perdido en la refactorización de idempotencia de la v1.1.0 (regresión respecto a la rama publicada en GitHub).

### Docs
- README: se documenta cómo activar **3DS en el pago ocasional** (single_buy). El backend no cambia; en el frontend se usa `Bancard.Charge3DS.createForm(...)` en lugar de `Bancard.Checkout.createForm(...)` con el **mismo** `process_id` y `checkout_js_url`. Es opcional por comercio.
- README: la sección de charge/3DS ahora nombra explícitamente `Bancard.Charge3DS.createForm` para el iframe de confirmación y aclara el rol de `extra_response_attributes`.

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
