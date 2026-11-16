<?php

namespace App\Pay;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Reads Cyllos' pay_client_view. */
final readonly class DbalClientCatalog implements ClientCatalog
{
    public function __construct(
        private Connection $connection,
        #[Autowire('%env(CYLLOS_BASE_URL)%')]
        private string $cyllosBaseUrl,
    ) {
    }

    public function findBySlug(string $slug): ?PayClient
    {
        $row = $this->connection->fetchAssociative(
            'SELECT slug, name, min_amount, max_amount, brand_primary_color, brand_background_color, brand_surface_color, brand_text_color, logo_filename FROM pay_client_view WHERE slug = :slug',
            ['slug' => $slug],
        );

        if (false === $row) {
            return null;
        }

        return new PayClient(
            (string) $row['slug'],
            (string) $row['name'],
            (int) $row['min_amount'],
            (int) $row['max_amount'],
            $row['brand_primary_color'] ?? null,
            $row['brand_background_color'] ?? null,
            $row['brand_surface_color'] ?? null,
            $row['brand_text_color'] ?? null,
            $this->logoUrl($row['logo_filename'] ?? null),
        );
    }

    /** Cyllos serves client logos publicly from /uploads/client-logos/. */
    private function logoUrl(?string $filename): ?string
    {
        if ($filename === null || $filename === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
            return null;
        }

        return rtrim($this->cyllosBaseUrl, '/') . '/uploads/client-logos/' . $filename;
    }
}
