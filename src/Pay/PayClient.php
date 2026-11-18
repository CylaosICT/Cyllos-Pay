<?php

namespace App\Pay;

/** What the payment site may know about a client: public data only. */
final readonly class PayClient
{
    private const HEX = '/^#[0-9a-f]{6}$/i';

    public const FONTS = ['modern', 'classic', 'rounded', 'mono'];
    public const RADII = ['sharp', 'soft', 'round'];
    public const BACKGROUNDS = ['aurora', 'shapes', 'plain'];

    /** How the payer picks the amount: any amount in a range, one of a list, or a single fixed amount. */
    public const MODE_RANGE = 'range';
    public const MODE_LIST = 'list';
    public const MODE_FIXED = 'fixed';

    /**
     * @param string|null $logoUrl absolute URL of the client's logo (served by Cyllos), if any
     * @param list<int>   $quickAmounts suggested amounts in whole euros ("range" mode)
     * @param list<AmountOffer> $offers the amounts to choose from ("list" mode) or the single one ("fixed" mode)
     * @param float|null  $creditRate   "range" mode: units credited per euro paid (null = 1)
     * @param string|null $creditUnit   name of the credited unit, e.g. "unités"
     */
    public function __construct(
        public string $slug,
        public string $name,
        public int $minAmount,
        public int $maxAmount,
        public ?string $primaryColor = null,
        public ?string $backgroundColor = null,
        public ?string $surfaceColor = null,
        public ?string $textColor = null,
        public ?string $logoUrl = null,
        public ?string $font = null,
        public ?string $radius = null,
        public ?string $background = null,
        public ?string $title = null,
        public ?string $intro = null,
        public ?string $buttonLabel = null,
        public ?string $successMessage = null,
        public ?string $supportText = null,
        public bool $showConfirmStep = true,
        public array $quickAmounts = [],
        public string $amountMode = self::MODE_RANGE,
        public array $offers = [],
        public ?float $creditRate = null,
        public ?string $creditUnit = null,
    ) {
    }

    public function isRange(): bool
    {
        // A list or fixed client without any usable offer falls back to a range rather than offering nothing.
        return !$this->isFixed() && !$this->isList();
    }

    public function isFixed(): bool
    {
        return $this->amountMode === self::MODE_FIXED && \count($this->offers) === 1;
    }

    public function isList(): bool
    {
        return $this->amountMode === self::MODE_LIST && $this->offers !== [];
    }

    /** The single offer of a "fixed" client. */
    public function fixedOffer(): ?AmountOffer
    {
        return $this->isFixed() ? $this->offers[0] : null;
    }

    /**
     * What this client allows for an amount the payer wants to pay: the offer
     * (paid + credited amounts), or null. Mirrors Cyllos' own rule, which is
     * the one that actually decides when the payment starts.
     */
    public function offerFor(int $paidCents): ?AmountOffer
    {
        if ($this->isFixed() || $this->isList()) {
            foreach ($this->offers as $offer) {
                if ($offer->paidCents === $paidCents) {
                    return $offer;
                }
            }

            return null;
        }

        if ($paidCents < $this->minAmount * 100 || $paidCents > $this->maxAmount * 100) {
            return null;
        }

        return new AmountOffer($paidCents, $this->creditRate === null ? $paidCents : (int) round($paidCents * $this->creditRate));
    }

    /** "1,5 unité(s) pour 1 €" in a sentence, for a range with a credit rate; null when the credit equals the payment. */
    public function rateText(): ?string
    {
        if ($this->isRange() && $this->creditRate !== null && abs($this->creditRate - 1.0) > 0.00001) {
            return \sprintf('1 € payé = %s %s', rtrim(rtrim(number_format($this->creditRate, 4, ',', ' '), '0'), ','), $this->unit(true, (int) round($this->creditRate * 100)));
        }

        return null;
    }

    /** Euros without useless decimals, e.g. "100 €" or "12,50 €" (tiles, chips, drop-down). */
    public static function moneyShort(int $cents): string
    {
        return self::number($cents) . ' €';
    }

    /** Same as creditText() without useless decimals, e.g. "150 unités". */
    public function creditShort(int $paidCents, int $creditCents): string
    {
        return self::number($creditCents) . ' ' . $this->unit($paidCents !== $creditCents, $creditCents);
    }

    private static function number(int $cents): string
    {
        return number_format($cents / 100, $cents % 100 === 0 ? 0 : 2, ',', ' ');
    }

    /** Euros, e.g. "20,00 €". */
    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }

    /** What the account is credited with, in its unit: "150,00 unités", or "20,00 €" when it is simply the payment. */
    public function creditText(int $paidCents, int $creditCents): string
    {
        $unit = $this->unit($paidCents !== $creditCents, $creditCents);

        return number_format($creditCents / 100, 2, ',', ' ') . ' ' . $unit;
    }

    /**
     * Name of the credited unit: the client's own word ("cigogne" or "cigogne|cigognes":
     * singular below 2 units, plural otherwise), else "unités" when it differs from euros, else "€".
     */
    private function unit(bool $differs, int $creditCents): string
    {
        $unit = self::text($this->creditUnit);
        if ($unit === null) {
            return $differs ? 'unités' : '€';
        }

        $words = explode('|', $unit);

        return $creditCents < 200 ? $words[0] : ($words[1] ?? $words[0]);
    }

    /** Page heading: the client's own title, else its name. */
    public function heading(): string
    {
        return self::text($this->title) ?? $this->name;
    }

    public function introText(): ?string
    {
        return self::text($this->intro);
    }

    public function successText(): ?string
    {
        return self::text($this->successMessage);
    }

    public function supportLine(): ?string
    {
        return self::text($this->supportText);
    }

    /** The pay button's label; {montant} becomes $amount (e.g. "20,00 €"). */
    public function buttonText(string $amount): string
    {
        $label = self::text($this->buttonLabel);

        return $label === null ? 'Payer ' . $amount : str_replace('{montant}', $amount, $label);
    }

    /**
     * Quick amounts that fit the client's bounds.
     *
     * @return list<int>
     */
    public function suggestedAmounts(): array
    {
        return array_values(array_filter($this->quickAmounts, fn (int $a): bool => $a >= $this->minAmount && $a <= $this->maxAmount));
    }

    /** Whitelisted look keys, as data-* values on <body> (styled by pay.css, no inline style). */
    public function fontKey(): string
    {
        return \in_array($this->font, self::FONTS, true) ? (string) $this->font : 'modern';
    }

    public function radiusKey(): string
    {
        return \in_array($this->radius, self::RADII, true) ? (string) $this->radius : 'soft';
    }

    public function backgroundKey(): string
    {
        return \in_array($this->background, self::BACKGROUNDS, true) ? (string) $this->background : 'aurora';
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * The client's identity as a CSS block overriding the default theme, or ''
     * when the client configured no colour. Missing colours are derived from
     * the given ones so the result always stays readable: text from the
     * background, card from the background, button label from the accent.
     */
    public function themeCss(): string
    {
        $primary = self::valid($this->primaryColor);
        $background = self::valid($this->backgroundColor);
        $surface = self::valid($this->surfaceColor);
        $text = self::valid($this->textColor);

        if ($primary === null && $background === null && $surface === null && $text === null) {
            return '';
        }

        $vars = [];
        if ($background !== null || $surface !== null || $text !== null) {
            $base = $background ?? $surface;
            $dark = $base !== null ? self::luminance($base) < 0.4 : self::luminance($text ?? '#000000') > 0.5;
            $text ??= $dark ? '#f8fafc' : '#0f172a';
            $background ??= $surface !== null
                ? self::mix($surface, '#000000', $dark ? 0.35 : 0.04)
                : ($dark ? '#0f172a' : '#f4f5f7');
            $surface ??= $dark ? self::mix($background, '#ffffff', 0.07) : '#ffffff';
            $vars['--bg'] = $background;
            $vars['--card'] = $surface;
            $vars['--fg'] = $text;
            $vars['color-scheme'] = $dark ? 'dark' : 'light';
            $vars['--ok'] = $dark ? '#6fdc8c' : '#1a7f37';
            $vars['--err'] = $dark ? '#ff8a80' : '#b42318';
        }
        if ($primary !== null) {
            $vars['--accent'] = $primary;
            $vars['--on-accent'] = self::luminance($primary) > 0.55 ? '#0f172a' : '#ffffff';
        }

        $css = ':root {';
        foreach ($vars as $name => $value) {
            $css .= \sprintf(' %s: %s;', $name, $value);
        }

        return $css . " }\n";
    }

    private static function valid(?string $color): ?string
    {
        return $color !== null && preg_match(self::HEX, $color) === 1 ? strtolower($color) : null;
    }

    /** Relative luminance, 0 (black) to 1 (white). */
    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(static function (int $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** $ratio of $towards mixed into $from. */
    private static function mix(string $from, string $towards, float $ratio): string
    {
        $a = self::rgb($from);
        $b = self::rgb($towards);

        return \sprintf('#%02x%02x%02x', ...array_map(static fn (int $x, int $y): int => (int) round($x + ($y - $x) * $ratio), $a, $b));
    }

    /** @return array{int, int, int} */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }
}
