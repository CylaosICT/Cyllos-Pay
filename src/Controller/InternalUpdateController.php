<?php

namespace App\Controller;

use App\Update\SelfUpdater;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Called by the Cyllos back-office ("Mises à jour"): reports this site's version
 * and starts its update. Same authentication as the other direction: an
 * HMAC-SHA256 of "{timestamp}.{raw body}" with PAY_INTERNAL_SECRET. Disabled (503)
 * when no secret is configured.
 */
class InternalUpdateController extends AbstractController
{
    private const MAX_CLOCK_SKEW_SECONDS = 120;

    public function __construct(
        private readonly SelfUpdater $updater,
        #[Autowire('%env(PAY_INTERNAL_SECRET)%')]
        private readonly string $secret,
    ) {
    }

    #[Route(path: '/internal/update/status', name: 'internal_update_status', methods: ['POST'])]
    public function status(Request $request): Response
    {
        return $this->guarded($request, fn (): array => $this->updater->status());
    }

    #[Route(path: '/internal/update/deploy', name: 'internal_update_deploy', methods: ['POST'])]
    public function deploy(Request $request): Response
    {
        return $this->guarded($request, fn (): array => ['started' => $this->updater->start()]);
    }

    /**
     * @param callable(): array<string, mixed> $action
     */
    private function guarded(Request $request, callable $action): Response
    {
        if ($this->secret === '') {
            return new JsonResponse(['error' => 'disabled'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $timestamp = $request->headers->get('X-Cyllos-Timestamp', '');
        $signature = $request->headers->get('X-Cyllos-Signature', '');
        $valid = ctype_digit($timestamp)
            && abs(time() - (int) $timestamp) <= self::MAX_CLOCK_SKEW_SECONDS
            && hash_equals(hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $this->secret), $signature);

        return $valid ? new JsonResponse($action()) : new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
    }
}
