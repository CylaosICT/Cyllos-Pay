<?php

namespace App\Pay;

/** What the payment site may know about a client: public data only. */
final readonly class PayClient
{
    private const HEX = '/^#[0-9a-f]{6}$/i';

    /**
     * @param string|null $logoUrl absolute URL of the client's logo (served by Cyllos), if any
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
    ) {
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
