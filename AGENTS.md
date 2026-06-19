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

## Arquitectura

```
src/Services/BancardVPOSService.php   # cliente HTTP + tokens + parsing de webhook (processWebhook)
src/Http/Controllers/WebhookController.php  # 3 handlers; idempotencia (claimTransaction); responde 200
src/Models/BancardTransaction.php     # idempotencia/conciliación (tabla bancard_transactions)
src/Models/SavedCard.php              # tarjetas catastradas (polimórfico)
src/Traits/HasBancardCards.php        # API para el modelo de usuario (registrar/sync/charge/delete)
src/Contracts/Payable.php             # contrato del modelo cobrable
src/Events/*                          # PaymentSucceeded/Failed, CardRegistered/Deleted
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
