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
            'SELECT slug, name, min_amount, max_amount, brand_primary_color, brand_background_color, brand_surface_color, brand_text_color, logo_filename, pay_font, pay_radius, pay_background, pay_title, pay_intro, pay_button_label, pay_success_message, pay_support_text, pay_show_confirm_step, pay_quick_amounts FROM pay_client_view WHERE slug = :slug',
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
            $row['pay_font'] ?? null,
            $row['pay_radius'] ?? null,
            $row['pay_background'] ?? null,
            $row['pay_title'] ?? null,
            $row['pay_intro'] ?? null,
            $row['pay_button_label'] ?? null,
            $row['pay_success_message'] ?? null,
            $row['pay_support_text'] ?? null,
            !isset($row['pay_show_confirm_step']) || (bool) $row['pay_show_confirm_step'],
            $this->quickAmounts($row['pay_quick_amounts'] ?? null),
        );
    }

    /** @return list<int> */
    private function quickAmounts(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        return array_values(array_map('intval', array_filter(explode(',', $raw), static fn (string $p): bool => ctype_digit(trim($p)))));
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
