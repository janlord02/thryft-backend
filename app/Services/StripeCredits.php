<?php

namespace App\Services;

use Stripe\Customer;
use Stripe\Stripe;

/**
 * Puts money on a Stripe customer's balance, which Stripe takes off their
 * next invoice. Its own class so tests can stand in for Stripe.
 */
class StripeCredits
{
    /** Returns the balance transaction id. */
    public function credit(string $customerId, int $cents, string $description, string $idempotencyKey): string
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        $txn = Customer::createBalanceTransaction(
            $customerId,
            ['amount' => -$cents, 'currency' => 'usd', 'description' => $description],
            ['idempotency_key' => $idempotencyKey],
        );

        return $txn->id;
    }
}
