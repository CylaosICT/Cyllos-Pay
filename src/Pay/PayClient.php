<?php

namespace App\Pay;

/** What the payment site may know about a client: public data only. */
final readonly class PayClient
{
    public function __construct(
        public string $slug,
        public string $name,
        public int $minAmount,
        public int $maxAmount,
    ) {
    }
}
