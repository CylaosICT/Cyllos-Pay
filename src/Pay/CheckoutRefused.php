<?php

namespace App\Pay;

/** Cyllos refused the top-up for a business reason (stable code in $reason). */
final class CheckoutRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Checkout refused: '.$reason);
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            'invalid_email' => "Cette adresse e-mail n'est pas valide.",
            'invalid_amount' => "Ce montant n'est pas autorisé.",
            'account_not_found' => "Aucun compte n'est associé à cette adresse e-mail. Vérifiez l'adresse utilisée pour votre compte.",
            'account_not_eligible' => 'Ce compte ne peut pas être alimenté par ce moyen de paiement.',
            'rate_limited' => 'Trop de tentatives. Réessayez dans quelques minutes.',
            default => "Le paiement n'est pas disponible pour le moment.",
        };
    }
}
