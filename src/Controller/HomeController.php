<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        /** @var list<string> */
        private readonly array $enabledLocales,
    ) {
    }

    #[Route('/', name: 'root', methods: ['GET'])]
    public function root(Request $request): RedirectResponse
    {
        $locale = $request->getPreferredLanguage($this->enabledLocales) ?? $this->enabledLocales[0];

        return $this->redirectToRoute('home', ['_locale' => $locale]);
    }

    #[Route('/{_locale}', name: 'home', requirements: ['_locale' => '%app.locales%'], methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('home/index.html.twig');
    }
}
