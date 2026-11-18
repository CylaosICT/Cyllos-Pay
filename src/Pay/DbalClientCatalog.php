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
            'SELECT slug, name, min_amount, max_amount, brand_primary_color, brand_background_color, brand_surface_color, brand_text_color, logo_filename, pay_font, pay_radius, pay_background, pay_title, pay_intro, pay_button_label, pay_success_message, pay_support_text, pay_show_confirm_step, pay_quick_amounts, pay_amount_mode, pay_amount_choices, pay_fixed_amount, pay_fixed_credit, pay_credit_rate, pay_credit_unit FROM pay_client_view WHERE slug = :slug',
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
            \in_array($row['pay_amount_mode'] ?? null, [PayClient::MODE_LIST, PayClient::MODE_FIXED], true) ? (string) $row['pay_amount_mode'] : PayClient::MODE_RANGE,
            $this->offers($row),
            isset($row['pay_credit_rate']) ? (float) $row['pay_credit_rate'] : null,
            $row['pay_credit_unit'] ?? null,
        );
    }

    /**
     * The fixed offers of a "list" client ("50,100:150") or the single one of a
     * "fixed" client. Whole numbers of euros; an entry that doesn't parse is skipped.
     *
     * @param array<string, mixed> $row
     *
     * @return list<AmountOffer>
     */
    private function offers(array $row): array
    {
        $mode = $row['pay_amount_mode'] ?? null;

        if ($mode === PayClient::MODE_FIXED) {
            $paid = (int) ($row['pay_fixed_amount'] ?? 0);
            $credit = (int) ($row['pay_fixed_credit'] ?? 0);

            return $paid > 0 ? [new AmountOffer($paid * 100, ($credit > 0 ? $credit : $paid) * 100)] : [];
        }

        if ($mode !== PayClient::MODE_LIST || !\is_string($row['pay_amount_choices'] ?? null)) {
            return [];
        }

        $offers = [];
        foreach (explode(',', $row['pay_amount_choices']) as $entry) {
            if (preg_match('/^([1-9][0-9]{0,5})(?::([1-9][0-9]{0,6}))?$/', trim($entry), $m) === 1) {
                $offers[] = new AmountOffer((int) $m[1] * 100, (int) ($m[2] ?? $m[1]) * 100);
            }
        }

        return $offers;
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
