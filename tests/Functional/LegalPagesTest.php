<?php

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LegalPagesTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pages(): iterable
    {
        yield 'mentions' => ['/mentions-legales', 'Mentions légales'];
        yield 'privacy' => ['/confidentialite', 'Politique de confidentialité'];
        yield 'terms' => ['/conditions-utilisation', "Conditions d'utilisation"];
    }

    #[DataProvider('pages')]
    public function testLegalPageIsPublicAndNotTakenForAClientSlug(string $path, string $title): void
    {
        $browser = static::createClient();
        $browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $title);
        self::assertSelectorExists('.legal-links');
    }

    public function testNoticeNamesPublisherAndHost(): void
    {
        $browser = static::createClient();
        $browser->request('GET', '/mentions-legales');

        self::assertSelectorTextContains('main', 'CYLAOS ICT');
        self::assertSelectorTextContains('main', '814 299 590');
        self::assertSelectorTextContains('main', 'Infomaniak');
        self::assertSelectorTextContains('main', 'support@cylaos.com');
    }

    public function testHomePageLinksToTheLegalPages(): void
    {
        $browser = static::createClient();
        $crawler = $browser->request('GET', '/');

        self::assertCount(1, $crawler->filter('a[href="/mentions-legales"]'));
        self::assertCount(1, $crawler->filter('a[href="/confidentialite"]'));
    }
}
