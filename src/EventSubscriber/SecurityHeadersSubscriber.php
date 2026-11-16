<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Strict headers for a public page that handles an e-mail and an amount. */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%env(CYLLOS_BASE_URL)%')]
        private readonly string $cyllosBaseUrl,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        // Client logos are served by Cyllos: allow images from that origin only.
        $parts = parse_url($this->cyllosBaseUrl);
        $logoOrigin = isset($parts['scheme'], $parts['host'])
            ? ' ' . $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            : '';
        // form-action lists Stripe: browsers also apply it to the redirect that follows a POST.
        $headers->set('Content-Security-Policy', "default-src 'none'; style-src 'self'; img-src 'self' data:{$logoOrigin}; form-action 'self' https://checkout.stripe.com; frame-ancestors 'none'; base-uri 'none'");
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Cache-Control', 'no-store, private');
    }
}
