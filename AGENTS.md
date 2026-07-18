# AGENTS.md — Guía para agentes/IA que trabajan sobre este paquete

Paquete: **softlab180/laravel-bancard** — cliente de Bancard VPOS 0.3 (Paraguay) para Laravel.
Este archivo es para quien **mantiene** el paquete. Para *usarlo* desde un proyecto, leé el `README.md`.

## Reglas de oro (pagos: no romper)

1. **Nunca inventes fórmulas de token ni URLs.** Verificá SIEMPRE contra la documentación oficial de Bancard (PDF "eCommerce – Compra Simple") antes de tocarlas. Las fórmulas son MD5 con orden exacto:
   - single_buy: `md5(private_key + shop_process_id + amount + currency)`
   - confirm (callback): `md5(private_key + shop_process_id + "confirm" + amount + currency)`
   - get_confirmation: `md5(private_key + shop_process_id + "get_confirmation")`
   - rollback: `md5(private_key + shop_process_id + "rollback" + "0.00")`
   - charge: `md5(private_key + shop_process_id + "charge" + amount + currency + alias_token)`
   - cards/new: `md5(private_key + card_id + user_id + "request_new_card")`
   - users_cards: `md5(private_key + user_id + "request_user_cards")`
   - delete_card: `md5(private_key + "delete_card" + user_id + alias_token)`
2. **El webhook DEBE responder HTTP 200** ante cualquier resultado de negocio (aprobado o rechazado). vPOS no reintenta de forma confiable: un no-200 hace que dé la confirmación por perdida (cuerpo `{"status":"success"}`, regla de 30 s). Reservá 5xx solo para errores genuinos de infraestructura.
3. **`amount` con 2 decimales** (`number_format($n, 2, '.', '')`), comparaciones de token con `hash_equals`.
4. **El catastro NO tiene webhook** servidor-a-servidor: se completa con el iframe (`Bancard.Cards.createForm` → `add_new_card_success`) + `users_cards`. Ver `HasBancardCards::syncBancardCards()`.
5. **SDK de checkout**: `{checkout}/javascript/dist/bancard-checkout-{version}.js` (mismo archivo en staging/producción, solo cambia el host). El `process_id` va por JS (`createForm`), no como query string.
6. **Propagá todo el resultado**: `processWebhook()` debe devolver TODOS los campos legibles de la confirmación (incl. `extended_response_description`/`response_details`), no solo el código. Para errores de operaciones del cliente, mantené `raw_response` (con `messages[].key`) en el array de retorno. El log del payload del webhook se gatea con `bancard.webhook.log_payloads` (no loguear el payload completo en prod por defecto).
7. **3DS de charge — `extra_response_attributes` es OPT-IN (`bancard.enable_3ds`), NO siempre.** El request de `chargeWithToken()` incluye `'extra_response_attributes' => ['confirmation.process_id']` **solo si `config('bancard.enable_3ds')` es true**. Ese parámetro REQUIERE que Bancard tenga habilitado el producto 3DS para el comercio; **enviarlo sin el permiso hace que Bancard RECHACE la operación** (reportado por Bancard directamente en homologación, con riesgo en producción). Para un comercio 3DS, la spec (pág. 37) pide enviarlo siempre → por eso el flag. **No lo vuelvas incondicional** (fue el bug de v1.2.1). Tests: `test_charge_no_envia_extra_response_attributes_sin_3ds` y `test_charge_envia_extra_response_attributes_con_3ds_habilitado`.
8. **`Charge3DS.createForm` es SOLO para charge (pago con token).** El 3DS del **single_buy** es transparente: el frontend usa **siempre `Bancard.Checkout.createForm`** (con o sin 3DS); el desafío se renderiza dentro de ese mismo iframe cuando el comercio está enrolado. NO documentar `Charge3DS` para single_buy (verificado contra el frontend de referencia y el PDF, cuya única sección "Flujo 3D SECURE" es "Pago con token - Charge", pág. 39-41). En WebView/mobile, el `baseUrl` del WebView debe ser `https://` para que el challenge cargue (pág. 75).
9. **`return_url`/`cancel_url` SIEMPRE llevan `shop_process_id`.** El paquete genera el `shop_process_id`, así que es quien debe inyectarlo en la URL de retorno (único identificador de la transacción en el retorno del browser: la cookie de sesión no viaja en el contexto cross-site del iframe/redirect). Usá el helper `appendShopProcessId()` en `createSingleBuy` y `chargeWithToken`; **nunca** lo hagas condicional a que el caller no haya pasado su propia URL. Test: `test_single_buy_agrega_shop_process_id_al_return_url_explicito`.
10. **`shop_process_id` NUNCA empieza con 0.** Bancard lo devuelve como **número JSON** en el webhook, lo que descarta el cero inicial → el token recalculado no coincide (`Invalid token`, pago aprobado rechazado) y falla el lookup. `generateShopProcessId()` garantiza primer dígito 1-9. Test: `test_shop_process_id_es_numerico_de_15_digitos_sin_cero_inicial_y_no_colisiona`. Mantené 15 dígitos (< 2^53, entra en el rango seguro de enteros JSON).
11. **El token del webhook de charge se valida con el `alias_token` usado al cobrar, no con el del payload.** El callback de charge se firma con el `alias_token`, que Bancard **no manda** en el payload. El paquete lo guarda al cobrar en el **idempotency store** (`bancard_processed_callbacks`, siempre — independiente de `persist_transactions` desde v2.1.0; fallback a `bancard_transactions` para charges viejos) y lo recupera por `shop_process_id` en el `WebhookController` (`lookupAliasToken()` → `validateConfirmationToken($op, $alias)`). Tests: `test_charge_valida_con_alias_token_guardado_cuando_no_viene_en_el_payload`, `test_charge_valida_con_alias_del_store_aunque_persist_transactions_este_off`.
12. **`getPaymentConfirmation()` (single_buy/confirmations) sirve para single_buy Y charge.** Es una *operación común a ambos flujos* (spec pág. 44/55) y **no hay endpoint de confirmación específico para charge**. No agregues uno ni reenrutes la conciliación de charges: un `BuyNotFound` al conciliar un charge es, casi siempre, el bug del cero inicial en `shop_process_id` (ver regla 10), no un problema de endpoint.
13. **El webhook valida PER-TENANT, nunca contra el singleton global.** El `WebhookController` resuelve el comercio (`tenant_resolver` → `BancardTenantContext`) y construye el service con `BancardVPOSService::forContext($ctx)`; TODA validación/consulta (token o requery) corre sobre esa instancia. **Nunca** uses `Bancard::` ni `app('bancard')` en el path del webhook (validaría con la llave global y rechazaría a todos los comercios ≠ global). El E2E `MultiTenantWebhookTest` (llaves ≠ global) lo prueba de raíz. Default sin resolver = `GlobalTenantResolver` (llaves globales, single-tenant intacto). **No hay webhook de catastro** (el catastro es local: iframe + `syncBancardCards()`); no reintroduzcas una ruta/handler para eso. Diseño: `docs/multi-tenant.md`.
14. **El `alias_token` es EFÍMERO: validez de UNA sola operación, TTL del orden de minutos (spec pág. 35, *"alias token temporal"*).** NUNCA cobres/borres con el alias guardado en `SavedCard`: se pide uno FRESCO a Bancard (`users_cards`) justo antes de cada charge/delete, matcheando la tarjeta por identidad ESTABLE (`card_id`, o masked+expiration). Lo hacen `chargeDefaultCard`/`chargeBancardCard`/`deleteBancardCard` (`HasBancardCards::freshAliasToken()`); el delete es **idempotente** si la tarjeta ya no está en Bancard. `syncBancardCards()` deduplica por `card_id`, **NO** por `alias_token` (si no, cada sync duplicaría la tarjeta). No llames a `chargeWithToken()`/`deleteCard()` del service con un alias guardado directo. Tests: `EphemeralAliasTest`.

## Arquitectura

```
src/Services/BancardVPOSService.php   # cliente HTTP + tokens + parsing de webhook (processWebhook)
src/Http/Controllers/WebhookController.php  # 1 handler (confirmación única, per-tenant); idempotencia; responde 200
src/Models/BancardTransaction.php     # idempotencia/conciliación (tabla bancard_transactions)
src/Models/SavedCard.php              # tarjetas catastradas (polimórfico)
src/Traits/HasBancardCards.php        # API para el modelo de usuario (registrar/sync/charge/delete)
src/Contracts/Payable.php             # contrato del modelo cobrable
src/Events/*                          # PaymentSucceeded/Failed
config/bancard.php · routes/webhooks.php · database/migrations/*
```

## Desarrollo y tests

```bash
composer install
vendor/bin/phpunit          # (en Windows/git-bash: php vendor/bin/phpunit)
php -l <archivo>            # lint rápido
```

Los tests usan `orchestra/testbench` + sqlite en memoria (`RefreshDatabase`). Cubren: webhook aprobado/rechazado/token-inválido/duplicado (idempotencia), URLs del SDK, unicidad de `shop_process_id`, forma de `processWebhook`, y sync de catastro. **Todo cambio en webhook/tokens/URLs debe venir con su test.**

## Convenciones

- El paquete es un **cliente VPOS**: persiste lo mínimo (`bancard_transactions` para idempotencia) y delega la lógica de negocio al consumidor vía **eventos** y los hooks del `Payable`.
- Versionado: `version` en `composer.json` + tag `vX.Y.Z`. Los consumidores que usan `dev-main` reciben el cambio al pushear a `main`.
- Mantené el `CHANGELOG.md` y el `README.md` al día con cualquier cambio de comportamiento.
