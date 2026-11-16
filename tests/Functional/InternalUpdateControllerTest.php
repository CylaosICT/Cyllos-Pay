<?php

namespace App\Tests\Functional;

use App\Update\SelfUpdater;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class InternalUpdateControllerTest extends WebTestCase
{
    private const SECRET = 'test-internal-secret-0123456789abcdef0123456789abcdef';

    private KernelBrowser $browser;
    private SelfUpdater&MockObject $updater;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot();
        $this->updater = $this->createMock(SelfUpdater::class);
        self::getContainer()->set(SelfUpdater::class, $this->updater);
    }

    private function call(string $path, ?string $signature = null, ?int $timestamp = null): void
    {
        $timestamp ??= time();
        $body = '{}';
        $this->browser->request('POST', $path, server: [
            'HTTP_X_CYLLOS_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_CYLLOS_SIGNATURE' => $signature ?? hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);
    }

    public function testStatusIsReturnedForASignedRequest(): void
    {
        $this->updater->expects(self::once())->method('status')->willReturn([
            'commit' => 'abc', 'branch' => 'main', 'remoteCommit' => 'def', 'remoteDate' => null,
            'behind' => 2, 'ahead' => 0, 'state' => 'never', 'running' => false, 'log' => '', 'error' => null,
        ]);

        $this->call('/internal/update/status');

        self::assertResponseIsSuccessful();
        self::assertSame(2, json_decode((string) $this->browser->getResponse()->getContent(), true)['behind']);
    }

    public function testDeployStartsTheUpdate(): void
    {
        $this->updater->expects(self::once())->method('start')->willReturn(true);

        $this->call('/internal/update/deploy');

        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $this->browser->getResponse()->getContent(), true)['started']);
    }

    public function testBadSignatureAndStaleTimestampAreRejectedWithoutRunningAnything(): void
    {
        $this->updater->expects(self::never())->method('start');
        $this->updater->expects(self::never())->method('status');

        $this->call('/internal/update/deploy', 'forged');
        self::assertResponseStatusCodeSame(401);

        $this->call('/internal/update/deploy', null, time() - 3600);
        self::assertResponseStatusCodeSame(401);
    }
}
