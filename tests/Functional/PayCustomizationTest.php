<?php

namespace App\Tests\Functional;

use App\Pay\ClientCatalog;
use App\Pay\CyllosCheckoutClient;
use App\Pay\PayClient;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** What a client can change about its payment page: look, wording and flow. */
class PayCustomizationTest extends WebTestCase
{
    private KernelBrowser $browser;
    private CyllosCheckoutClient&MockObject $cyllos;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot();

        $catalog = $this->createStub(ClientCatalog::class);
        $catalog->method('findBySlug')->willReturnCallback(static fn (string $slug): ?PayClient => $slug === 'custom'
            ? new PayClient(
                'custom',
                'La Cigogne',
                5,
                250,
                font: 'classic',
                radius: 'round',
                background: 'shapes',
                title: 'Recharger ma Cigogne',
                intro: 'Bienvenue, rechargez en un clic.',
                buttonLabel: 'Régler {montant}',
                successMessage: 'Merci, à bientôt !',
                supportText: 'Une question ? aide@cigogne.test',
                showConfirmStep: false,
                quickAmounts: [10, 20, 999],
            )
            : null);
        self::getContainer()->set(ClientCatalog::class, $catalog);

        $this->cyllos = $this->createMock(CyllosCheckoutClient::class);
        self::getContainer()->set(CyllosCheckoutClient::class, $this->cyllos);
        $this->browser->setServerParameter('REMOTE_ADDR', '203.0.113.' . random_int(1, 250));
    }

    public function testLookAndWordingAreRendered(): void
    {
        $this->browser->request('GET', '/custom');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('body[data-font=classic][data-radius=round][data-bg=shapes]');
        self::assertSelectorTextContains('h1', 'Recharger ma Cigogne');
        self::assertSelectorTextContains('.lead', 'Bienvenue, rechargez en un clic.');
        self::assertSelectorTextContains('.support', 'aide@cigogne.test');
        // The skipped confirmation step is not shown in the stepper.
        self::assertSelectorNotExists('.steps .label:contains("Confirmation")');
        self::assertCount(3, $this->browser->getCrawler()->filter('.steps li'));
    }

    public function testConfirmationStepIsSkippedAndQuickAmountsLeadToTheSummary(): void
    {
        $this->cyllos->method('checkAccount')->willReturn('individual');
        $this->cyllos->expects(self::once())->method('start')->with('custom', 'payer@example.com', 2000)->willReturn('https://checkout.stripe.com/c/pay/cs_2');

        $crawler = $this->browser->request('GET', '/custom');
        $this->browser->submit($crawler->selectButton('Continuer')->form(['form[email]' => 'payer@example.com']));
        self::assertResponseRedirects('/custom/montant');

        $crawler = $this->browser->followRedirect();
        // 999 is outside the client's bounds: only 10 and 20 are offered.
        self::assertCount(2, $crawler->filter('button.chip'));
        $this->browser->submit($crawler->selectButton('20 €')->form());
        self::assertResponseRedirects('/custom/recapitulatif');

        $crawler = $this->browser->followRedirect();
        $this->browser->submit($crawler->selectButton('Régler 20,00 €')->form());
        self::assertResponseRedirects('https://checkout.stripe.com/c/pay/cs_2', 303);
    }

    public function testQuickAmountOutsideTheOfferedOnesIsIgnored(): void
    {
        $this->cyllos->method('checkAccount')->willReturn('individual');

        $crawler = $this->browser->request('GET', '/custom');
        $this->browser->submit($crawler->selectButton('Continuer')->form(['form[email]' => 'payer@example.com']));
        $crawler = $this->browser->followRedirect();

        $form = $crawler->selectButton('Continuer')->form();
        $values = $form->getPhpValues();
        $values['quick'] = '999';
        $this->browser->request('POST', '/custom/montant', $values);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUnknownLookKeysFallBackToDefaults(): void
    {
        $client = new PayClient('a', 'A', 5, 250, font: 'comic-sans', radius: 'x"onload=1', background: '');

        self::assertSame('modern', $client->fontKey());
        self::assertSame('soft', $client->radiusKey());
        self::assertSame('aurora', $client->backgroundKey());
        self::assertSame('A', $client->heading());
        self::assertSame('Payer 5,00 €', $client->buttonText('5,00 €'));
    }
}
