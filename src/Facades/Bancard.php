<?php

namespace Softlab180\Bancard\Facades;

use Illuminate\Support\Facades\Facade;
use Softlab180\Bancard\Contracts\Payable;
use Softlab180\Bancard\Services\BancardVPOSService;

/**
 * @method static array createSingleBuy(Payable $payable, ?string $description = null, ?string $returnUrl = null, ?string $cancelUrl = null)
 * @method static array chargeWithToken(Payable $payable, string $aliasToken, int $numberOfPayments = 1, ?string $description = null, ?string $returnUrl = null)
 * @method static array initiateCardRegistration(int|string $userId, int $cardId, string $userPhone, string $userEmail, ?string $returnUrl = null)
 * @method static array getUserCards(int|string $userId)
 * @method static array deleteCard(int|string $userId, string $aliasToken)
 * @method static array getPaymentConfirmation(string $shopProcessId)
 * @method static array rollbackPayment(string $shopProcessId)
 * @method static array processWebhook(array $payload)
 * @method static bool validateConfirmationToken(array $operation)
 * @method static bool validateChargeWebhookToken(array $operation, string $aliasToken)
 * @method static bool isPaymentExpired(\DateTimeInterface $expiresAt)
 * @method static string getBaseUrl()
 * @method static string getCheckoutUrl()
 *
 * @see \Softlab180\Bancard\Services\BancardVPOSService
 */
class Bancard extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BancardVPOSService::class;
    }
}
