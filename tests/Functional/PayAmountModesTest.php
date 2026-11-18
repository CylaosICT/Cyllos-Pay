<?php

namespace App\Tests\Functional;

use App\Pay\AmountOffer;
use App\Pay\ClientCatalog;
use App\Pay\CyllosCheckoutClient;
use App\Pay\OrderStatusReader;
use App\Pay\PayClient;
use App\Pay\PayOrder;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The three ways a client can let payers choose the amount (range, list, single
 * amount), a credit that differs from the payment, and the step-by-step follow-up
 * of the order on the return page.
 */
class PayAmountModesTest extends WebTestCase
{
    private KernelBrowser $browser;
    private CyllosCheckoutClient&MockObject $cyllos;
    private ?PayClient $plainOverride = null;
    private ?PayOrder $order = null;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot();

        $catalog = $this->createStub(ClientCatalog::class);
        $catalog->method('findBySlug')->willReturnCallback(fn (string $slug): ?PayClient => match ($slug) {
            'list' => new PayClient('list', 'Liste', 5, 250, showConfirmStep: false, amountMode: PayClient::MODE_LIST, offers: [
                new AmountOffer(5000, 5000), new AmountOffer(10000, 15000), new AmountOffer(20000, 32000),
            ], creditUnit: 'point|points'),
            'many' => new PayClient('many', 'Beaucoup', 5, 250, showConfirmStep: false, amountMode: PayClient::MODE_LIST, offers: array_map(
                static fn (int $n): AmountOffer => new AmountOffer($n * 1000, $n * 1000), range(1, 8),
            )),
            'fixed' => new PayClient('fixed', 'Fixe', 5, 250, showConfirmStep: false, amountMode: PayClient::MODE_FIXED, offers: [new AmountOffer(10000, 15000)]),
            'rate' => new PayClient('rate', 'Taux', 5, 250, showConfirmStep: false, creditRate: 1.5),
            'plain' => $this->plainOverride ?? new PayClient('plain', 'Simple', 5, 250, showConfirmStep: false),
            default => null,
        });
        self::getContainer()->set(ClientCatalog::class, $catalog);

        $this->cyllos = $this->createMock(CyllosCheckoutClient::class);
        $this->cyllos->method('checkAccount')->willReturn('individual');
        self::getContainer()->set(CyllosCheckoutClient::class, $this->cyllos);

        $reader = $this->createStub(OrderStatusReader::class);
        $reader->method('find')->willReturnCallback(fn (): ?PayOrder => $this->order);
        self::getContainer()->set(OrderStatusReader::class, $reader);

        $this->browser->setServerParameter('REMOTE_ADDR', '203.0.113.' . random_int(1, 250));
    }

    /** Walks to the amount step of a client (its confirmation step is skipped). */
    private function reachAmountStep(string $slug): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->browser->request('GET', '/' . $slug);
        $this->browser->submit($crawler->selectButton('Continuer')->form(['form[email]' => 'payer@example.com']));
        self::assertResponseRedirects('/' . $slug . '/montant');

        return $this->browser->followRedirect();
    }

    public function testListOffersTilesWithTheCreditOfEachAmount(): void
    {
        $crawler = $this->reachAmountStep('list');

        self::assertCount(3, $crawler->filter('.tile input[type=radio]'));
        self::assertSelectorTextContains('.tiles', '100 €');
        // Only the amounts whose credit differs announce it, in the client's own unit.
        self::assertSelectorTextContains('.tiles', '150 points crédités');
        self::assertSelectorTextContains('.tiles', '320 points crédités');
        self::assertCount(2, $crawler->filter('.tile__credit'));
        self::assertSelectorNotExists('input[type=number]');
    }

    public function testTheClientsOwnUnitNameIsUsedInSingularAndPlural(): void
    {
        $client = new PayClient('x', 'X', 5, 250, creditUnit: 'cigogne|cigognes');

        self::assertSame('1 cigogne', $client->creditShort(100, 100));
        self::assertSame('200 cigognes', $client->creditShort(10000, 20000));
        self::assertSame('200,00 cigognes', $client->creditText(10000, 20000));
    }

    public function testChoosingAListedAmountShowsPaymentAndCreditOnTheSummary(): void
    {
        $this->cyllos->expects(self::once())->method('start')->with('list', 'payer@example.com', 10000)->willReturn('https://checkout.stripe.com/c/pay/cs_3');

        $crawler = $this->reachAmountStep('list');
        $form = $crawler->selectButton('Continuer')->form();
        $offer = $form['form[offer]'];
        self::assertInstanceOf(ChoiceFormField::class, $offer);
        $offer->select('10000');
        $this->browser->submit($form);
        self::assertResponseRedirects('/list/recapitulatif');

        $crawler = $this->browser->followRedirect();
        self::assertSelectorTextContains('.recap', 'Montant payé');
        self::assertSelectorTextContains('.recap', '100,00 €');
        self::assertSelectorTextContains('.recap', '150,00 points');
        $this->browser->submit($crawler->selectButton('Payer 100,00 €')->form());
        self::assertResponseRedirects('https://checkout.stripe.com/c/pay/cs_3', 303);
    }

    public function testAnAmountThatIsNotInTheListIsRefused(): void
    {
        $crawler = $this->reachAmountStep('list');
        $values = $crawler->selectButton('Continuer')->form()->getPhpValues();
        $values['form']['offer'] = '7500';
        $this->browser->request('POST', '/list/montant', $values);

        self::assertResponseStatusCodeSame(422);
        // And nothing was kept: the summary stays out of reach.
        $this->browser->request('GET', '/list/recapitulatif');
        self::assertResponseRedirects('/list/montant');
    }

    public function testManyAmountsBecomeADropDown(): void
    {
        $crawler = $this->reachAmountStep('many');

        self::assertCount(0, $crawler->filter('.tile'));
        self::assertCount(8, $crawler->filter('select.select option[value]:not([value=""])'));
    }

    public function testSingleAmountIsShownNotTypedAndAdvancesToTheSummary(): void
    {
        $crawler = $this->reachAmountStep('fixed');

        self::assertSelectorNotExists('input[type=number]');
        self::assertSelectorTextContains('.amount-card__value', '100 €');
        self::assertSelectorTextContains('.amount-card__credit', '150 unités');

        $this->browser->submit($crawler->selectButton('Continuer')->form());
        self::assertResponseRedirects('/fixed/recapitulatif');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.recap', '150,00 unités');
    }

    public function testRangeWithACreditRateExplainsItAndCreditsTheRate(): void
    {
        $crawler = $this->reachAmountStep('rate');
        self::assertSelectorTextContains('form small', '1 € payé = 1,5 unités');

        $this->browser->submit($crawler->selectButton('Continuer')->form(['form[amount]' => '20']));
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.recap', '30,00 unités');
    }

    public function testAnOrdinaryRangeIsUnchanged(): void
    {
        $crawler = $this->reachAmountStep('plain');

        self::assertSelectorExists('input[type=number]');
        self::assertSelectorNotExists('.tile');
        $this->browser->submit($crawler->selectButton('Continuer')->form(['form[amount]' => '20']));
        $this->browser->followRedirect();
        self::assertSelectorNotExists('.recap__credit');
    }

    public function testSummaryGoesBackToTheAmountWhenTheClientsRulesNoLongerAllowIt(): void
    {
        $crawler = $this->reachAmountStep('plain');
        $this->browser->submit($crawler->selectButton('Continuer')->form(['form[amount]' => '20']));

        // The client switched to a single amount of 100 € in the meantime.
        $this->plainOverride = new PayClient('plain', 'Simple', 5, 250, showConfirmStep: false, amountMode: PayClient::MODE_FIXED, offers: [new AmountOffer(10000, 10000)]);

        $this->browser->request('GET', '/plain/recapitulatif');
        self::assertResponseRedirects('/plain/montant');
    }

    private function returnPage(PayOrder $order): \Symfony\Component\DomCrawler\Crawler
    {
        $this->order = $order;

        return $this->browser->request('GET', '/plain/retour/S-ABCDEFGHJK');
    }

    public function testReturnPageValidatesEachStepInTurnAndShowsTheCreditedAmount(): void
    {
        $crawler = $this->returnPage(new PayOrder('S-ABCDEFGHJK', 10000, 'paid', 'credited', 15000));

        self::assertCount(3, $crawler->filter('.tl.tl--ok.is-anim'));
        // One beat each: s0, s1, s2, then the success message after the third.
        self::assertCount(1, $crawler->filter('.tl.s0'));
        self::assertCount(1, $crawler->filter('.tl.s1'));
        self::assertCount(1, $crawler->filter('.tl.s2'));
        self::assertSelectorExists('.reveal.r3 .celebrate');
        self::assertSelectorTextContains('.success', 'crédité de 150,00 unités');
        self::assertSelectorTextContains('.timeline', 'Paiement de 100,00 € reçu');
        self::assertSelectorTextContains('.timeline', 'Votre compte a reçu 150,00 unités');
    }

    public function testAnAlreadyWatchedStepIsNotPlayedAgainOnTheAutomaticRefresh(): void
    {
        // First visit: Stripe confirmed, the Cyclos credit still running.
        $crawler = $this->returnPage(new PayOrder('S-ABCDEFGHJK', 2000, 'paid', 'processing'));
        self::assertCount(1, $crawler->filter('.tl--ok.is-anim.s0'));
        self::assertCount(1, $crawler->filter('.tl--current.is-anim.s1'));
        self::assertSelectorExists('.tl--todo');
        self::assertSelectorExists('meta[http-equiv=refresh]');

        // Refresh while still crediting: nothing replays, the spinner just keeps going.
        $crawler = $this->returnPage(new PayOrder('S-ABCDEFGHJK', 2000, 'paid', 'processing'));
        self::assertCount(1, $crawler->filter('.tl--ok:not(.is-anim)'));
        self::assertCount(1, $crawler->filter('.tl--current.is-anim.s0'));

        // Credit done: step 1 stays as it was, only the two new ones play (and still take their beat).
        $crawler = $this->returnPage(new PayOrder('S-ABCDEFGHJK', 2000, 'paid', 'credited'));
        self::assertCount(1, $crawler->filter('.tl--ok:not(.is-anim)'));
        self::assertCount(1, $crawler->filter('.tl--ok.is-anim.s0'));
        self::assertCount(1, $crawler->filter('.tl--ok.is-anim.s1'));
        self::assertSelectorExists('.reveal.r2 .celebrate');
    }

    public function testFailedPaymentAndCreditProblemAreShownOnTheirStep(): void
    {
        $crawler = $this->returnPage(new PayOrder('S-ABCDEFGHJK', 2000, 'expired'));
        self::assertCount(1, $crawler->filter('.tl--fail'));
        self::assertCount(2, $crawler->filter('.tl--todo'));
        self::assertSelectorTextContains('.alert', "n'a pas abouti");

        $crawler = $this->returnPage(new PayOrder('S-ABCDEFGHJK', 3000, 'paid', 'problem'));
        self::assertCount(1, $crawler->filter('.tl--ok'));
        self::assertCount(1, $crawler->filter('.tl--fail'));
        self::assertCount(1, $crawler->filter('.tl--todo'));
    }
}
