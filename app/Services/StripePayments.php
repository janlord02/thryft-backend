<?php

namespace App\Services;

use Stripe\PaymentIntent;
use Stripe\Stripe;

/**
 * One-off card payments to Thryft (featured placements). Its own class so
 * tests can stand in for Stripe.
 */
class StripePayments
{
    /** @return array{id: string, client_secret: string} */
    public function create(int $cents, string $description, array $metadata, ?string $receiptEmail = null): array
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        $intent = PaymentIntent::create(array_filter([
            'amount' => $cents,
            'currency' => 'usd',
            'description' => $description,
            'metadata' => $metadata,
            'receipt_email' => $receiptEmail,
            'automatic_payment_methods' => ['enabled' => true],
        ]));

        return ['id' => $intent->id, 'client_secret' => $intent->client_secret];
    }

    public function status(string $paymentIntentId): string
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        return PaymentIntent::retrieve($paymentIntentId)->status;
    }
}
