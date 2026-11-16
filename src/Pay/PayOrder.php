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
     */
    public function __construct(
        public string $reference,
        public int $amountCents,
        public string $status,
        public ?string $creditStatus = null,
    ) {
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
