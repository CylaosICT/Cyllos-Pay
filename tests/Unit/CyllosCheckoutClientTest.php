<?php

namespace App\Tests\Unit;

use App\Pay\CheckoutRefused;
use App\Pay\CheckoutUnavailable;
use App\Pay\CyllosCheckoutClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class CyllosCheckoutClientTest extends TestCase
{
    private const SECRET = 'shared-secret-0123456789';

    private function client(MockResponse $response, ?\Closure $inspect = null): CyllosCheckoutClient
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($response, $inspect): MockResponse {
            if (null !== $inspect) {
                $inspect($method, $url, $options);
            }

            return $response;
        });

        return new CyllosCheckoutClient($http, 'https://cyllos.example/', self::SECRET);
    }

    public function testSendsASignedRequestAndReturnsTheRedirectUrl(): void
    {
        $captured = [];
        $client = $this->client(
            new MockResponse(json_encode(['reference' => 'S-ABCDEFGHJK', 'redirectUrl' => 'https://checkout.stripe.com/c/pay/cs_1']), ['http_code' => 200]),
            static function (string $method, string $url, array $options) use (&$captured): void {
                $captured = compact('method', 'url', 'options');
            },
        );

        $url = $client->start('rollon', 'payer@example.com', 2550, '203.0.113.9');

        self::assertSame('https://checkout.stripe.com/c/pay/cs_1', $url);
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://cyllos.example/internal/checkout', $captured['url']);

        $headers = [];
        foreach ($captured['options']['headers'] as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $headers[strtolower($name)] = $value;
        }
        $body = $captured['options']['body'];
        self::assertSame(
            ['clientSlug' => 'rollon', 'email' => 'payer@example.com', 'amountCents' => 2550, 'clientIp' => '203.0.113.9'],
            json_decode($body, true),
        );
        self::assertSame(hash_hmac('sha256', $headers['x-cyllos-timestamp'].'.'.$body, self::SECRET), $headers['x-cyllos-signature']);
        self::assertEqualsWithDelta(time(), (int) $headers['x-cyllos-timestamp'], 5);
    }

    public function testBusinessRefusalCarriesTheStableReason(): void
    {
        $client = $this->client(new MockResponse(json_encode(['error' => 'account_not_found']), ['http_code' => 422]));

        try {
            $client->start('rollon', 'ghost@example.com', 2000, '203.0.113.9');
            self::fail('Expected CheckoutRefused');
        } catch (CheckoutRefused $e) {
            self::assertSame('account_not_found', $e->reason);
            self::assertStringContainsString('Aucun compte', $e->userMessage());
        }
    }

    public function testRateLimitIsARefusal(): void
    {
        $this->expectException(CheckoutRefused::class);

        $this->client(new MockResponse('{"error":"rate_limited"}', ['http_code' => 429]))->start('rollon', 'a@b.fr', 2000, '203.0.113.9');
    }

    #[DataProvider('technicalFailures')]
    public function testTechnicalFailuresAreUnavailable(MockResponse $response): void
    {
        $this->expectException(CheckoutUnavailable::class);

        $this->client($response)->start('rollon', 'a@b.fr', 2000, '203.0.113.9');
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function technicalFailures(): iterable
    {
        yield 'bad gateway' => [new MockResponse('{"error":"provider_error"}', ['http_code' => 502])];
        yield 'unauthorized' => [new MockResponse('{"error":"unauthorized"}', ['http_code' => 401])];
        yield 'network error' => [new MockResponse('', ['error' => 'connection refused'])];
        yield 'not json' => [new MockResponse('<html>', ['http_code' => 200])];
        yield 'non https redirect' => [new MockResponse('{"redirectUrl":"http://evil.example/"}', ['http_code' => 200])];
        yield 'no redirect url' => [new MockResponse('{}', ['http_code' => 200])];
    }

    public function testRefusesToCallWithoutASecret(): void
    {
        $this->expectException(CheckoutUnavailable::class);

        (new CyllosCheckoutClient(new MockHttpClient(), 'https://cyllos.example', ''))->start('rollon', 'a@b.fr', 2000, '203.0.113.9');
    }
    public function testAccountCheckIsSignedAndReturnsTheType(): void
    {
        $captured = [];
        $client = $this->client(
            new MockResponse(json_encode(['accountType' => 'professional']), ['http_code' => 200]),
            static function (string $method, string $url, array $options) use (&$captured): void {
                $captured = compact('url', 'options');
            },
        );

        self::assertSame('professional', $client->checkAccount('rollon', 'a@b.fr', '203.0.113.9'));
        self::assertSame('https://cyllos.example/internal/account-check', $captured['url']);
        self::assertSame(['clientSlug' => 'rollon', 'email' => 'a@b.fr', 'clientIp' => '203.0.113.9'], json_decode($captured['options']['body'], true));
        self::assertStringContainsString('X-Cyllos-Signature: ' . hash_hmac('sha256', time() . '.' . $captured['options']['body'], self::SECRET), implode("\n", $captured['options']['headers']));
    }

    public function testAccountCheckRefusalAndFailure(): void
    {
        try {
            $this->client(new MockResponse('{"error":"account_not_found"}', ['http_code' => 422]))->checkAccount('rollon', 'a@b.fr', '203.0.113.9');
            self::fail('Expected CheckoutRefused');
        } catch (CheckoutRefused $e) {
            self::assertSame('account_not_found', $e->reason);
        }

        $this->expectException(CheckoutUnavailable::class);
        $this->client(new MockResponse('', ['http_code' => 503]))->checkAccount('rollon', 'a@b.fr', '203.0.113.9');
    }
}
