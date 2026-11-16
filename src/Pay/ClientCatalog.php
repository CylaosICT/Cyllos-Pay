<?php

namespace App\Pay;

interface ClientCatalog
{
    /** An active Stripe client, or null (unknown, inactive or not on Stripe). */
    public function findBySlug(string $slug): ?PayClient;
}
