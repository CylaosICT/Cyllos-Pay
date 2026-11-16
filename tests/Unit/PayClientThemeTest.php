<?php

namespace App\Tests\Unit;

use App\Pay\PayClient;
use PHPUnit\Framework\TestCase;

class PayClientThemeTest extends TestCase
{
    public function testNoColourMeansNoOverride(): void
    {
        self::assertSame('', (new PayClient('a', 'A', 5, 250))->themeCss());
    }

    public function testPrimaryOnlyKeepsDefaultSurfacesAndPicksReadableLabel(): void
    {
        $css = (new PayClient('a', 'A', 5, 250, '#ffe066'))->themeCss();

        self::assertStringContainsString('--accent: #ffe066', $css);
        self::assertStringContainsString('--on-accent: #0f172a', $css);
        self::assertStringNotContainsString('--bg', $css);
    }

    public function testDarkBackgroundDerivesLightTextAndCard(): void
    {
        $css = (new PayClient('a', 'A', 5, 250, '#1d4ed8', '#0b132b'))->themeCss();

        self::assertStringContainsString('--bg: #0b132b', $css);
        self::assertStringContainsString('--fg: #f8fafc', $css);
        self::assertStringContainsString('--on-accent: #ffffff', $css);
        self::assertStringContainsString('color-scheme: dark', $css);
        self::assertMatchesRegularExpression('/--card: #[0-9a-f]{6}/', $css);
    }

    public function testInvalidColoursAreIgnored(): void
    {
        self::assertSame('', (new PayClient('a', 'A', 5, 250, 'red; } body { display:none', 'url(x)', '#12', '#12345g'))->themeCss());
    }
}
