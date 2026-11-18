<?php

namespace App\Pay;

/**
 * One way to top up: what the payer pays on Stripe and what the Cyclos account
 * is credited with, both in cents. They only differ when the client configured
 * a different credit (e.g. pay 100 €, receive 150 units).
 */
final readonly class AmountOffer
{
    public function __construct(
        public int $paidCents,
        public int $creditCents,
    ) {
    }

    public function hasDifferentCredit(): bool
    {
        return $this->paidCents !== $this->creditCents;
    }
}
