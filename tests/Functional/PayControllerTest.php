<?php

namespace App\Tests\Functional;

use App\Pay\CheckoutRefused;
use App\Pay\CheckoutUnavailable;
use App\Pay\ClientCatalog;
use App\Pay\CyllosCheckoutClient;
use App\Pay\OrderStatusReader;
use App\Pay\PayClient;
use App\Pay\PayOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PayControllerTest extends WebTestCase
{
    private KernelBrowser $browser;
    private CyllosCheckoutClient&MockObject $cyllos;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot();

        $catalog = $this->createStub(ClientCatalog::class);
        $catalog->method('findBySlug')->willReturnCallback(static fn (string $slug): ?PayClient => $slug === 'rollon' ? new PayClient('rollon', 'Le RolloN', 5, 250, '#d97706', null, null, null, 'https://cyllos.example/uploads/client-logos/rollon.png') : null);
        self::getContainer()->set(ClientCatalog::class, $catalog);

        $this->cyllos = $this->createMock(CyllosCheckoutClient::class);
        self::getContainer()->set(CyllosCheckoutClient::class, $this->cyllos);
    }

    public function testHomePageDescribesTheServiceWithoutASlug(): void
    {
        $this->browser->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'système de paiement des entreprises et associations');
        self::assertSelectorExists('.how__list li');
    }

    private function ip(): string
    {
        return '203.0.113.' . random_int(1, 250);
    }

    private function submitEmail(string $email, ?string $ip = null): void
    {
        $this->browser->setServerParameter('REMOTE_ADDR', $ip ?? $this->ip());
        $crawler = $this->browser->request('GET', '/rollon');
        $this->browser->submit($crawler->selectButton('Continuer')->form(['form[email]' => $email]));
    }

    private function confirmAccount(): void
    {
        $crawler = $this->browser->followRedirect();
        $this->browser->submit($crawler->selectButton('Oui, continuer')->form());
    }

    private function submitAmount(string $amount): void
    {
        $crawler = $this->browser->followRedirect();
        $this->browser->submit($crawler->selectButton('Continuer')->form(['form[amount]' => $amount]));
    }

    /** Walks the flow up to the summary page. */
    private function reachSummary(string $amount = '20.50'): void
    {
        $this->cyllos->method('checkAccount')->willReturn('individual');
        $this->submitEmail('payer@example.com');
        $this->confirmAccount();
        $this->submitAmount($amount);
        $this->browser->followRedirect();
    }

    public function testUnknownClientIs404(): void
    {
        $this->browser->request('GET', '/nobody');

        self::assertResponseStatusCodeSame(404);
    }

    public function testFirstStepAndSecurityHeaders(): void
    {
        $this->browser->request('GET', '/rollon');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Le RolloN');
        self::assertSelectorExists('input[type=email]');
        self::assertSelectorExists('.steps li.current');
        $headers = $this->browser->getResponse()->headers;
        self::assertStringContainsString("default-src 'none'", (string) $headers->get('Content-Security-Policy'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
    }

    public function testLaterStepsAreNotReachableWithoutTheEarlierOnes(): void
    {
        foreach (['/rollon/confirmer', '/rollon/montant', '/rollon/recapitulatif'] as $path) {
            $this->browser->request('GET', $path);
            self::assertResponseRedirects('/rollon', null, $path);
        }
    }

    public function testFullFlowEndsOnStripeWithTheSessionValuesAndPayerIp(): void
    {
        $this->cyllos->expects(self::once())->method('checkAccount')->with('rollon', 'payer@example.com', '203.0.113.50')->willReturn('professional');
        $this->cyllos->expects(self::once())->method('start')->with('rollon', 'payer@example.com', 2050, '203.0.113.50')->willReturn('https://checkout.stripe.com/c/pay/cs_1');

        $this->submitEmail(' payer@example.com ', '203.0.113.50');
        self::assertResponseRedirects('/rollon/confirmer');

        $crawler = $this->browser->followRedirect();
        self::assertSelectorTextContains('.recap', 'payer@example.com');
        self::assertSelectorTextContains('.recap', 'Professionnel');
        $this->browser->submit($crawler->selectButton('Oui, continuer')->form());
        self::assertResponseRedirects('/rollon/montant');

        $this->submitAmount('20.50');
        self::assertResponseRedirects('/rollon/recapitulatif');

        $crawler = $this->browser->followRedirect();
        self::assertSelectorTextContains('.recap', '20,50 €');
        $this->browser->submit($crawler->selectButton('Payer 20,50 €')->form());

        self::assertResponseRedirects('https://checkout.stripe.com/c/pay/cs_1', 303);
    }

    public function testUnknownAccountStopsAtTheFirstStep(): void
    {
        $this->cyllos->method('checkAccount')->willThrowException(new CheckoutRefused('account_not_found'));
        $this->cyllos->expects(self::never())->method('start');

        $this->submitEmail('ghost@example.com');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert', 'Aucun compte');
        $this->browser->request('GET', '/rollon/montant');
        self::assertResponseRedirects('/rollon');
    }

    public function testInvalidEmailNeverReachesCyllos(): void
    {
        $this->cyllos->expects(self::never())->method('checkAccount');

        $this->submitEmail('not-an-email');

        self::assertResponseStatusCodeSame(422);
    }

    public function testChoosingAnotherAddressRestartsTheFlow(): void
    {
        $this->cyllos->method('checkAccount')->willReturn('individual');
        $this->submitEmail('payer@example.com');
        $crawler = $this->browser->followRedirect();

        $this->browser->submit($crawler->selectButton("Non, changer d'adresse")->form());
        self::assertResponseRedirects('/rollon');

        $this->browser->request('GET', '/rollon/montant');
        self::assertResponseRedirects('/rollon');
    }

    public function testOutOfRangeAmountIsRefusedBeforeTheSummary(): void
    {
        $this->cyllos->method('checkAccount')->willReturn('individual');
        $this->submitEmail('payer@example.com');
        $this->confirmAccount();
        $this->submitAmount('1000');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'compris entre 5 € et 250 €');
        $this->browser->request('GET', '/rollon/recapitulatif');
        self::assertResponseRedirects('/rollon/montant');
    }

    public function testCyllosRefusingTheAmountSendsBackToTheAmountStep(): void
    {
        $this->cyllos->method('start')->willThrowException(new CheckoutRefused('invalid_amount'));
        $this->reachSummary();

        $this->browser->submit($this->browser->getCrawler()->selectButton('Payer 20,50 €')->form());

        self::assertResponseRedirects('/rollon/montant');
    }

    public function testAccountLostBetweenStepsRestartsTheFlow(): void
    {
        $this->cyllos->method('start')->willThrowException(new CheckoutRefused('account_not_found'));
        $this->reachSummary();

        $this->browser->submit($this->browser->getCrawler()->selectButton('Payer 20,50 €')->form());

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert', 'Aucun compte');
        $this->browser->request('GET', '/rollon/montant');
        self::assertResponseRedirects('/rollon');
    }

    public function testTechnicalFailureShowsAGenericMessage(): void
    {
        $this->cyllos->method('checkAccount')->willThrowException(new CheckoutUnavailable('Cyllos unreachable: secret detail'));

        $this->submitEmail('payer@example.com');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert', 'pas disponible');
        self::assertStringNotContainsString('secret detail', (string) $this->browser->getResponse()->getContent());
    }

    public function testAccountChecksAreRateLimitedPerIp(): void
    {
        $this->cyllos->method('checkAccount')->willReturn('individual');
        $ip = '198.51.100.' . random_int(1, 250);

        for ($i = 0; $i < 30; ++$i) {
            $this->submitEmail('payer@example.com', $ip);
            self::assertResponseRedirects('/rollon/confirmer');
        }

        $this->submitEmail('payer@example.com', $ip);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert', 'Trop de tentatives');
    }

    public function testCancelledReturnShowsANotice(): void
    {
        $this->browser->request('GET', '/rollon?annule=S-ABCDEFGHJK');

        self::assertSelectorTextContains('.notice', 'annulé');
    }

    #[DataProvider('orderStates')]
    public function testReturnPageFollowsTheOrderUntilTheCyclosCredit(string $status, ?string $creditStatus, string $expected, bool $autoRefresh): void
    {
        $reader = $this->createStub(OrderStatusReader::class);
        $reader->method('find')->willReturn(new PayOrder('S-ABCDEFGHJK', 2000, $status, $creditStatus));
        self::getContainer()->set(OrderStatusReader::class, $reader);

        $this->browser->request('GET', '/rollon/retour/S-ABCDEFGHJK');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', $expected);
        self::assertSame($autoRefresh, $this->browser->getCrawler()->filter('meta[http-equiv=refresh]')->count() === 1);
    }

    /**
     * @return iterable<string, array{string, string|null, string, bool}>
     */
    public static function orderStates(): iterable
    {
        yield 'awaiting stripe' => ['pending', null, 'confirmation par Stripe', true];
        yield 'paid, not yet recorded' => ['paid', null, 'crédit de votre compte est en cours', true];
        yield 'paid, crediting' => ['paid', 'processing', 'crédit de votre compte est en cours', true];
        yield 'credited' => ['paid', 'credited', 'a été crédité de 20,00 €', false];
        yield 'credit problem' => ['paid', 'problem', "n'a pas pu être effectué automatiquement", false];
        yield 'expired' => ['expired', null, "n'a pas abouti", false];
        yield 'failed' => ['failed', null, "n'a pas abouti", false];
    }

    public function testReturnPageWithUnknownOrderAndBadReference(): void
    {
        $reader = $this->createStub(OrderStatusReader::class);
        $reader->method('find')->willReturn(null);
        self::getContainer()->set(OrderStatusReader::class, $reader);

        $this->browser->request('GET', '/rollon/retour/S-ABCDEFGHJK');
        self::assertSelectorTextContains('.alert', 'introuvable');

        $this->browser->request('GET', '/rollon/retour/not-a-reference');
        self::assertResponseStatusCodeSame(404);
    }

    public function testThemeStylesheetAndLogoAreServed(): void
    {
        $this->browser->request('GET', '/rollon/theme.css');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('--accent: #d97706', (string) $this->browser->getResponse()->getContent());
        self::assertStringContainsString('text/css', (string) $this->browser->getResponse()->headers->get('Content-Type'));

        $this->browser->request('GET', '/rollon');
        self::assertSelectorExists('img.brand__logo[src="https://cyllos.example/uploads/client-logos/rollon.png"]');
        self::assertSelectorExists('link[href="/rollon/theme.css"]');
        self::assertStringContainsString("img-src 'self' data: https://cyllos.test;", (string) $this->browser->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testThemeOfUnknownClientIs404(): void
    {
        $this->browser->request('GET', '/inconnu/theme.css');
        self::assertResponseStatusCodeSame(404);
    }
}
