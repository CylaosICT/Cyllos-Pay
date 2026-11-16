<?php

namespace App\Controller;

use App\Pay\CheckoutRefused;
use App\Pay\CheckoutUnavailable;
use App\Pay\ClientCatalog;
use App\Pay\CyllosCheckoutClient;
use App\Pay\OrderStatusReader;
use App\Pay\PayClient;
use App\Pay\PayFlow;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The public payment interface, as a guided flow:
 *   1. e-mail of the account to top up   (/{slug})
 *   2. confirmation that the account exists  (/{slug}/confirmer)
 *   3. amount                              (/{slug}/montant)
 *   4. summary, then Stripe's hosted page    (/{slug}/recapitulatif)
 *   5. follow-up until the Cyclos account is credited (/{slug}/retour/{ref})
 *
 * Nothing here touches Stripe or Cyclos: account checks and the payment start
 * are signed calls to Cyllos, which re-validates everything and later credits
 * the Cyclos account itself.
 */
class PayController extends AbstractController
{
    private const SLUG = '[a-z0-9][a-z0-9-]{0,99}';

    private const STEP_ROUTES = [
        PayFlow::STEP_EMAIL => 'pay_form',
        PayFlow::STEP_CONFIRM => 'pay_confirm',
        PayFlow::STEP_AMOUNT => 'pay_amount',
        PayFlow::STEP_SUMMARY => 'pay_summary',
    ];

    public function __construct(
        private readonly ClientCatalog $clients,
        private readonly OrderStatusReader $orders,
        private readonly CyllosCheckoutClient $cyllos,
        private readonly PayFlow $flow,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactoryInterface $payAttemptLimiter,
    ) {
    }

    /** The client's colours, as a stylesheet (no inline style: the CSP forbids it). */
    #[Route(path: '/{slug}/theme.css', name: 'pay_theme', requirements: ['slug' => self::SLUG], methods: ['GET'])]
    public function theme(string $slug): Response
    {
        $response = new Response($this->client($slug)->themeCss(), Response::HTTP_OK, ['Content-Type' => 'text/css; charset=UTF-8']);
        $response->setPrivate();
        $response->setMaxAge(60);

        return $response;
    }

    /** Step 1: which account? */
    #[Route(path: '/{slug}', name: 'pay_form', requirements: ['slug' => self::SLUG], methods: ['GET', 'POST'])]
    public function email(Request $request, string $slug): Response
    {
        $client = $this->client($slug);

        if ($request->isMethod('GET') && !$request->query->has('annule')) {
            $this->flow->reset($slug);
        }

        $form = $this->createFormBuilder(null, ['csrf_token_id' => 'pay_email'])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail de votre compte',
                'attr' => ['autocomplete' => 'email', 'maxlength' => 255],
                'constraints' => [new Assert\NotBlank(message: 'Saisissez votre adresse e-mail.'), new Assert\Email(message: "Cette adresse e-mail n'est pas valide."), new Assert\Length(max: 255)],
            ])
            ->getForm();
        $form->handleRequest($request);
        $error = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $email = trim((string) $form->get('email')->getData());
            $error = $this->throttled($request);

            if ($error === null) {
                try {
                    $type = $this->cyllos->checkAccount($slug, $email, $this->payerIp($request));
                    $this->flow->startWithAccount($slug, $email, $type);

                    return $this->redirectToRoute('pay_confirm', ['slug' => $slug]);
                } catch (CheckoutRefused $e) {
                    $this->logger->info('Cyllos refused the request: ' . $e->reason);
                    $error = $e->userMessage();
                } catch (CheckoutUnavailable $e) {
                    $error = $this->unavailable($e);
                }
            }
        }

        return $this->page('pay/email.html.twig', $client, PayFlow::STEP_EMAIL, $form, $error, [
            'cancelled' => $request->query->has('annule'),
        ]);
    }

    /** Step 2: is this the right account? */
    #[Route(path: '/{slug}/confirmer', name: 'pay_confirm', requirements: ['slug' => self::SLUG], methods: ['GET', 'POST'])]
    public function confirm(Request $request, string $slug): Response
    {
        $client = $this->client($slug);
        if ($this->flow->reachableStep($slug) < PayFlow::STEP_CONFIRM) {
            return $this->redirectToStep($slug, PayFlow::STEP_EMAIL);
        }

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('pay_confirm', (string) $request->request->get('_token'))) {
            if ($request->request->get('action') === 'change') {
                $this->flow->reset($slug);

                return $this->redirectToRoute('pay_form', ['slug' => $slug]);
            }
            $this->flow->confirm($slug);

            return $this->redirectToRoute('pay_amount', ['slug' => $slug]);
        }

        return $this->page('pay/confirm.html.twig', $client, PayFlow::STEP_CONFIRM, null, null, [
            'email' => $this->flow->email($slug),
            'accountType' => $this->flow->accountType($slug),
        ]);
    }

    /** Step 3: how much? */
    #[Route(path: '/{slug}/montant', name: 'pay_amount', requirements: ['slug' => self::SLUG], methods: ['GET', 'POST'])]
    public function amount(Request $request, string $slug): Response
    {
        $client = $this->client($slug);
        if ($this->flow->reachableStep($slug) < PayFlow::STEP_AMOUNT) {
            return $this->redirectToStep($slug, $this->flow->reachableStep($slug));
        }

        $form = $this->createFormBuilder(['amount' => $this->flow->amountCents($slug) !== null ? $this->flow->amountCents($slug) / 100 : null], ['csrf_token_id' => 'pay_amount'])
            ->add('amount', NumberType::class, [
                'label' => 'Montant (€)',
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => $client->minAmount, 'max' => $client->maxAmount, 'step' => '0.01', 'inputmode' => 'decimal'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Saisissez un montant.'),
                    new Assert\Range(min: $client->minAmount, max: $client->maxAmount, notInRangeMessage: 'Le montant doit être compris entre {{ min }} € et {{ max }} €.'),
                ],
            ])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->flow->setAmount($slug, (int) round(((float) $form->get('amount')->getData()) * 100));

            return $this->redirectToRoute('pay_summary', ['slug' => $slug]);
        }

        return $this->page('pay/amount.html.twig', $client, PayFlow::STEP_AMOUNT, $form, null, ['email' => $this->flow->email($slug)]);
    }

    /** Step 4: summary, then Stripe. */
    #[Route(path: '/{slug}/recapitulatif', name: 'pay_summary', requirements: ['slug' => self::SLUG], methods: ['GET', 'POST'])]
    public function summary(Request $request, string $slug): Response
    {
        $client = $this->client($slug);
        if ($this->flow->reachableStep($slug) < PayFlow::STEP_SUMMARY) {
            return $this->redirectToStep($slug, $this->flow->reachableStep($slug));
        }

        $email = (string) $this->flow->email($slug);
        $amountCents = (int) $this->flow->amountCents($slug);
        $error = null;

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('pay_summary', (string) $request->request->get('_token'))) {
            $error = $this->throttled($request);

            if ($error === null) {
                try {
                    return new RedirectResponse($this->cyllos->start($slug, $email, $amountCents, $this->payerIp($request)), Response::HTTP_SEE_OTHER);
                } catch (CheckoutRefused $e) {
                    $this->logger->info('Cyllos refused the payment start: ' . $e->reason);
                    // The account or the amount no longer holds: send the payer back to fix it.
                    if ($e->reason === 'invalid_amount') {
                        return $this->redirectToRoute('pay_amount', ['slug' => $slug]);
                    }
                    if (\in_array($e->reason, ['account_not_found', 'account_not_eligible', 'invalid_email'], true)) {
                        $this->flow->reset($slug);
                    }
                    $error = $e->userMessage();
                } catch (CheckoutUnavailable $e) {
                    $error = $this->unavailable($e);
                }
            }
        }

        return $this->page('pay/summary.html.twig', $client, PayFlow::STEP_SUMMARY, null, $error, [
            'email' => $email,
            'accountType' => $this->flow->accountType($slug),
            'amountCents' => $amountCents,
        ]);
    }

    /** Step 5: back from Stripe, follow the order until the Cyclos credit. */
    #[Route(path: '/{slug}/retour/{reference}', name: 'pay_return', requirements: ['slug' => self::SLUG, 'reference' => 'S-[A-Z0-9]{10}'], methods: ['GET'])]
    public function return(string $slug, string $reference): Response
    {
        $client = $this->client($slug);
        $order = $this->orders->find($slug, $reference);

        $response = $this->render('pay/return.html.twig', ['client' => $client, 'order' => $order]);
        if ($order !== null && $order->status === 'paid') {
            // The payment is done: the in-progress flow state has no further use.
            $this->flow->reset($slug);
        }

        return $response;
    }

    private function client(string $slug): PayClient
    {
        return $this->clients->findBySlug($slug) ?? throw $this->createNotFoundException();
    }

    private function redirectToStep(string $slug, int $step): RedirectResponse
    {
        return $this->redirectToRoute(self::STEP_ROUTES[$step], ['slug' => $slug]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function page(string $template, PayClient $client, int $step, ?FormInterface $form, ?string $error, array $context = []): Response
    {
        $failed = $error !== null || ($form !== null && $form->isSubmitted() && !$form->isValid());

        return $this->render($template, $context + ['client' => $client, 'step' => $step, 'form' => $form, 'error' => $error], new Response(status: $failed ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function throttled(Request $request): ?string
    {
        return $this->payAttemptLimiter->create($this->payerIp($request))->consume()->isAccepted()
            ? null
            : (new CheckoutRefused('rate_limited'))->userMessage();
    }

    private function unavailable(CheckoutUnavailable $e): string
    {
        $this->logger->error('Cyllos call failed: ' . $e->getMessage());

        return (new CheckoutRefused('unavailable'))->userMessage();
    }

    private function payerIp(Request $request): string
    {
        return $request->getClientIp() ?? 'unknown';
    }
}
