<?php

namespace App\Pay;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The only way this site acts on Cyllos (site A). It holds no Stripe key and
 * no Cyclos access: every call is authenticated by an HMAC-SHA256 of
 * "{timestamp}.{raw body}" with the secret shared with Cyllos
 * (PAY_INTERNAL_SECRET), and Cyllos re-validates everything.
 */
class CyllosCheckoutClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $secret,
    ) {
    }

    /**
     * "Confirm your account" step: does this e-mail match a Cyclos account that
     * can be credited, and of which kind?
     *
     * @return 'professional'|'individual'
     *
     * @throws CheckoutRefused     business refusal (unknown account...)
     * @throws CheckoutUnavailable technical failure
     */
    public function checkAccount(string $clientSlug, string $email, string $payerIp): string
    {
        $data = $this->call('/internal/account-check', ['clientSlug' => $clientSlug, 'email' => $email, 'clientIp' => $payerIp]);

        return ($data['accountType'] ?? null) === 'professional' ? 'professional' : 'individual';
    }

    /**
     * Starts the Stripe Checkout for a top-up.
     *
     * @return string the URL to redirect the payer to
     *
     * @throws CheckoutRefused     business refusal (bad amount, unknown account...)
     * @throws CheckoutUnavailable technical failure
     */
    public function start(string $clientSlug, string $email, int $amountCents, string $payerIp): string
    {
        $data = $this->call('/internal/checkout', ['clientSlug' => $clientSlug, 'email' => $email, 'amountCents' => $amountCents, 'clientIp' => $payerIp]);

        $url = $data['redirectUrl'] ?? null;
        if (!\is_string($url) || !str_starts_with($url, 'https://')) {
            throw new CheckoutUnavailable('Cyllos answered without a usable redirect URL.');
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> the decoded body of a successful (HTTP 200) answer
     */
    private function call(string $path, array $payload): array
    {
        if ($this->secret === '') {
            throw new CheckoutUnavailable('PAY_INTERNAL_SECRET is not configured.');
        }

        $body = json_encode($payload, \JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . $path, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Cyllos-Timestamp' => $timestamp,
                    'X-Cyllos-Signature' => hash_hmac('sha256', $timestamp . '.' . $body, $this->secret),
                ],
                'body' => $body,
                'timeout' => 20,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new CheckoutUnavailable('Cyllos unreachable: ' . $e->getMessage(), 0, $e);
        }

        if ($status === 200) {
            return $data;
        }

        $error = \is_string($data['error'] ?? null) ? $data['error'] : '';
        if ($status === 422) {
            throw new CheckoutRefused($error);
        }
        if ($status === 429) {
            throw new CheckoutRefused('rate_limited');
        }

        throw new CheckoutUnavailable(\sprintf('Unexpected Cyllos answer (HTTP %d, %s).', $status, $error));
    }
}
