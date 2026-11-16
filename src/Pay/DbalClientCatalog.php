<?php

namespace App\Pay;

use Doctrine\DBAL\Connection;

/** Reads Cyllos' pay_client_view. */
final readonly class DbalClientCatalog implements ClientCatalog
{
    public function __construct(private Connection $connection)
    {
    }

    public function findBySlug(string $slug): ?PayClient
    {
        $row = $this->connection->fetchAssociative(
            'SELECT slug, name, min_amount, max_amount FROM pay_client_view WHERE slug = :slug',
            ['slug' => $slug],
        );

        return false === $row ? null : new PayClient((string) $row['slug'], (string) $row['name'], (int) $row['min_amount'], (int) $row['max_amount']);
    }
}
