<?php

namespace Softlab180\Bancard\Contracts;

/**
 * Interface for models that can be paid with Bancard.
 *
 * Implement this interface on any model that represents a payable entity
 * (e.g., Order, Subscription, Invoice, etc.)
 */
interface Payable
{
    /**
     * Get the unique identifier for the payable.
     */
    public function getPayableId(): int|string;

    /**
     * Get the amount to be paid in the smallest currency unit or as a float.
     * For PYG (Guaranies), use the full amount (e.g., 150000 for Gs. 150.000).
     */
    public function getPayableAmount(): float|int;

    /**
     * Get the currency code for the payment.
     * Default should be 'PYG' for Paraguayan Guarani.
     */
    public function getPayableCurrency(): string;

    /**
     * Get a description for the payment.
     * This will be shown in the Bancard checkout.
     */
    public function getPayableDescription(): string;

    /**
     * Store the Bancard payment details on the payable.
     */
    public function storeBancardPayment(array $paymentData): void;

    /**
     * Mark the payable as paid.
     */
    public function markAsPaid(array $confirmationData): void;

    /**
     * Mark the payable as failed.
     */
    public function markAsFailed(array $errorData): void;
}
