<?php

namespace App\Pay;

interface OrderStatusReader
{
    public function find(string $clientSlug, string $reference): ?PayOrder;
}
