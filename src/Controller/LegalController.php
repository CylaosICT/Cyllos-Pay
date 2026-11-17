<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Static legal pages of the public payment site. The higher priority keeps them
 * ahead of the "/{slug}" client routes, so these paths are never read as a client.
 */
class LegalController extends AbstractController
{
    #[Route(path: '/mentions-legales', name: 'legal_notice', methods: ['GET'], priority: 10)]
    public function notice(): Response
    {
        return $this->render('legal/notice.html.twig');
    }

    #[Route(path: '/confidentialite', name: 'legal_privacy', methods: ['GET'], priority: 10)]
    public function privacy(): Response
    {
        return $this->render('legal/privacy.html.twig');
    }

    #[Route(path: '/conditions-utilisation', name: 'legal_terms', methods: ['GET'], priority: 10)]
    public function terms(): Response
    {
        return $this->render('legal/terms.html.twig');
    }
}
