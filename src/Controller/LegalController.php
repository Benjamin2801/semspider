<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegalController extends AbstractController
{
    public function __construct(
        /** @var array<string, string> */
        #[Autowire(param: 'app.legal')]
        private readonly array $publisher,
    ) {
    }

    #[Route('/{_locale}/legal', name: 'legal', requirements: ['_locale' => '%app.locales%'], methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('legal.html.twig', ['publisher' => $this->publisher]);
    }
}
