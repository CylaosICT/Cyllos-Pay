<?php

namespace App\Pay;

use Doctrine\DBAL\Connection;

/** Reads Cyllos' pay_order_view: reference, amount and statuses only (no e-mail). */
final readonly class DbalOrderStatusReader implements OrderStatusReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(string $clientSlug, string $reference): ?PayOrder
    {
        $row = $this->connection->fetchAssociative(
            'SELECT reference, amount_cents, status, credit_status FROM pay_order_view WHERE client_slug = :slug AND reference = :reference',
            ['slug' => $clientSlug, 'reference' => $reference],
        );

        if ($row === false) {
            return null;
        }

        return new PayOrder(
            (string) $row['reference'],
            (int) $row['amount_cents'],
            (string) $row['status'],
            $row['credit_status'] === null ? null : (string) $row['credit_status'],
        );
    }
}
