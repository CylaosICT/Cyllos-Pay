<?php

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `asset_v('css/pay.css')`: the asset's URL with its modification time, so a
 * browser or a CDN (Cloudflare) never keeps serving a stylesheet from before an update.
 */
final class AssetVersionExtension extends AbstractExtension
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('asset_v', $this->url(...))];
    }

    public function url(string $path): string
    {
        $path = ltrim($path, '/');
        $mtime = @filemtime($this->publicDir . '/' . $path);

        return '/' . $path . ($mtime !== false ? '?v=' . $mtime : '');
    }
}
