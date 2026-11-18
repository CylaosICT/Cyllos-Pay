<?php

namespace App\Pay;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Where the payer is in the guided flow (e-mail -> confirmation -> amount ->
 * summary), kept in the session per client. Each step is only reachable once
 * the previous one is done; nothing here is trusted by Cyllos, which
 * re-validates e-mail, account and amount when the payment starts.
 */
final readonly class PayFlow
{
    public const STEP_EMAIL = 1;
    public const STEP_CONFIRM = 2;
    public const STEP_AMOUNT = 3;
    public const STEP_SUMMARY = 4;

    public function __construct(private RequestStack $requestStack)
    {
    }

    /** E-mail typed and its Cyclos account found. */
    public function startWithAccount(string $slug, string $email, string $accountType): void
    {
        $this->write($slug, ['email' => $email, 'accountType' => $accountType, 'confirmed' => false, 'amountCents' => null]);
    }

    public function confirm(string $slug): void
    {
        $state = $this->read($slug);
        if ($state !== null) {
            $state['confirmed'] = true;
            $this->write($slug, $state);
        }
    }

    public function setAmount(string $slug, int $amountCents): void
    {
        $state = $this->read($slug);
        if ($state !== null && $state['confirmed']) {
            $state['amountCents'] = $amountCents;
            $this->write($slug, $state);
        }
    }

    public function reset(string $slug): void
    {
        $this->requestStack->getSession()->remove($this->key($slug));
    }

    public function email(string $slug): ?string
    {
        return $this->read($slug)['email'] ?? null;
    }

    /** @return 'professional'|'individual'|null */
    public function accountType(string $slug): ?string
    {
        return $this->read($slug)['accountType'] ?? null;
    }

    public function amountCents(string $slug): ?int
    {
        return $this->read($slug)['amountCents'] ?? null;
    }

    /** How many of the follow-up steps of an order the payer has already seen complete. */
    public function seenSteps(string $reference): int
    {
        $seen = $this->requestStack->getSession()->get('pay_seen');

        return \is_array($seen) && \is_int($seen[$reference] ?? null) ? $seen[$reference] : 0;
    }

    public function markSeen(string $reference, int $steps): void
    {
        $session = $this->requestStack->getSession();
        $seen = $session->get('pay_seen');
        $seen = \is_array($seen) ? $seen : [];
        $seen[$reference] = max($steps, \is_int($seen[$reference] ?? null) ? $seen[$reference] : 0);
        // Only the most recent orders are remembered.
        $session->set('pay_seen', \array_slice($seen, -10, null, true));
    }

    /** The furthest step the payer may open. */
    public function reachableStep(string $slug): int
    {
        $state = $this->read($slug);

        return match (true) {
            $state === null => self::STEP_EMAIL,
            !$state['confirmed'] => self::STEP_CONFIRM,
            $state['amountCents'] === null => self::STEP_AMOUNT,
            default => self::STEP_SUMMARY,
        };
    }

    /**
     * @return array{email: string, accountType: string, confirmed: bool, amountCents: int|null}|null
     */
    private function read(string $slug): ?array
    {
        $state = $this->requestStack->getSession()->get($this->key($slug));

        return \is_array($state) && \is_string($state['email'] ?? null) && \is_string($state['accountType'] ?? null)
            ? ['email' => $state['email'], 'accountType' => $state['accountType'], 'confirmed' => (bool) ($state['confirmed'] ?? false), 'amountCents' => \is_int($state['amountCents'] ?? null) ? $state['amountCents'] : null]
            : null;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function write(string $slug, array $state): void
    {
        $this->requestStack->getSession()->set($this->key($slug), $state);
    }

    private function key(string $slug): string
    {
        return 'pay_flow_' . $slug;
    }
}
