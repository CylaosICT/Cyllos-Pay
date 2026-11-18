<?php

namespace App\Pay;

/** What the payer is told about an order, from Cyllos' pay_order_view. */
final readonly class PayOrder
{
    public const STAGE_AWAITING_PAYMENT = 'awaiting_payment';
    public const STAGE_CREDITING = 'crediting';
    public const STAGE_CREDITED = 'credited';
    public const STAGE_NEEDS_ATTENTION = 'needs_attention';
    public const STAGE_CLOSED = 'closed';

    /**
     * @param string      $status       pending|paid|expired|failed (Stripe side)
     * @param string|null $creditStatus null|processing|credited|problem (Cyclos side)
     * @param int|null    $creditAmountCents what the Cyclos account receives; null = same as the payment
     */
    public function __construct(
        public string $reference,
        public int $amountCents,
        public string $status,
        public ?string $creditStatus = null,
        public ?int $creditAmountCents = null,
    ) {
    }

    /** What the Cyclos account is credited with (the payment itself unless the client credits another amount). */
    public function creditedCents(): int
    {
        return $this->creditAmountCents ?? $this->amountCents;
    }

    /**
     * How far the order got, as the number of finished steps of the three the
     * payer follows: 1 = Stripe payment confirmed, 2 = Cyclos account credited,
     * 3 = operation over. A failed or unresolved step counts as "reached":
     * it is shown (as a problem) and nothing comes after it.
     */
    public function reachedSteps(): int
    {
        return match ($this->stage()) {
            self::STAGE_AWAITING_PAYMENT => 0,
            self::STAGE_CLOSED => 1,
            self::STAGE_CREDITING => 1,
            self::STAGE_NEEDS_ATTENTION => 2,
            default => 3,
        };
    }

    /** Where the order stands, from the payer's point of view. */
    public function stage(): string
    {
        return match (true) {
            $this->status === 'pending' => self::STAGE_AWAITING_PAYMENT,
            $this->status !== 'paid' => self::STAGE_CLOSED,
            $this->creditStatus === 'credited' => self::STAGE_CREDITED,
            $this->creditStatus === 'problem' => self::STAGE_NEEDS_ATTENTION,
            default => self::STAGE_CREDITING,
        };
    }

    /** True while the page should keep refreshing itself. */
    public function isInProgress(): bool
    {
        return \in_array($this->stage(), [self::STAGE_AWAITING_PAYMENT, self::STAGE_CREDITING], true);
    }
}
