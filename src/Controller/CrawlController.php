<?php

namespace App\Controller;

use App\Crawl\Issue;
use App\Crawl\PageAudit;
use App\Crawl\PageCrawler;
use App\Crawl\SiteDiscovery;
use App\Crawl\SiteScope;
use App\Crawl\SiteUnreachableException;
use App\Crawl\Url;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatorBagInterface;

use function Symfony\Component\String\u;

// Le crawl est piloté par le navigateur : il garde la file des pages et demande des lots de quelques pages,
// chacun analysé en moins de 30 s. Aucune requête n'approche la limite d'exécution de l'hébergement.
final class CrawlController extends AbstractController
{
    private const BATCH_SECONDS = 30;

    private const MAX_URL_LENGTH = 2048;

    public function __construct(
        private readonly SiteDiscovery $discovery,
        private readonly PageCrawler $crawler,
        #[Autowire(service: 'translator')]
        private readonly TranslatorBagInterface $translations,
        private readonly int $maxPages,
        private readonly int $batchSize,
    ) {
    }

    #[Route('/{_locale}/crawl', name: 'crawl', requirements: ['_locale' => '%app.locales%'], methods: ['GET'])]
    public function start(Request $request): Response
    {
        $input = u($request->query->getString('url'))->trim()->truncate(self::MAX_URL_LENGTH)->toString();
        if ('' === $input) {
            return $this->redirectToRoute('home', ['_locale' => $request->getLocale()]);
        }

        try {
            $site = $this->discovery->discover($input, $this->maxPages);
        } catch (SiteUnreachableException $e) {
            return $this->render('home/index.html.twig', ['url' => $input, 'error' => $e], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $messages = $this->translations->getCatalogue($request->getLocale())->all('messages');
        $issues = [];
        foreach (Issue::cases() as $issue) {
            $issues[$issue->value] = ['label' => $messages['issue.'.$issue->value] ?? $issue->value, 'severity' => $issue->severity()];
        }

        return $this->render('crawl/results.html.twig', [
            'site' => $site,
            'config' => [
                'root' => $site->root,
                'endpoint' => $this->generateUrl('crawl_pages', ['_locale' => $request->getLocale()]),
                'maxPages' => $this->maxPages,
                'batchSize' => $this->batchSize,
                'sitemap' => $site->sitemapPages,
                'issues' => $issues,
                // Les mêmes seuils que PageAudit, pour les couleurs et les jauges du tableau.
                'thresholds' => [
                    'title' => PageAudit::TITLE_LENGTH,
                    'meta' => PageAudit::META_LENGTH,
                    'words' => PageAudit::THIN_CONTENT_WORDS,
                    'ttfb' => PageAudit::SLOW_TTFB_MS,
                    'load' => PageAudit::SLOW_LOAD_MS,
                    'weight' => PageAudit::HEAVY_PAGE_BYTES,
                    'requests' => PageAudit::MANY_REQUESTS,
                ],
                'i18n' => self::withPrefix($messages, 'js.'),
            ],
        ]);
    }

    #[Route('/{_locale}/crawl/pages', name: 'crawl_pages', requirements: ['_locale' => '%app.locales%'], methods: ['POST'], format: 'json')]
    public function pages(Request $request): JsonResponse
    {
        $deadline = microtime(true) + self::BATCH_SECONDS;
        $payload = $request->toArray();

        $root = \is_string($payload['root'] ?? null) ? Url::normalize($payload['root']) : null;
        $urls = $payload['urls'] ?? null;
        if (null === $root || !\is_array($urls) || [] === $urls || \count($urls) > $this->batchSize) {
            throw new BadRequestHttpException('root et urls (1 à '.$this->batchSize.' adresses) sont attendus.');
        }

        $scope = new SiteScope($root);
        $pages = [];
        foreach ($urls as $url) {
            $url = \is_string($url) ? Url::normalize($url) : null;
            if (null === $url || !$scope->contains($url)) {
                throw new BadRequestHttpException('Seules les pages du site crawlé sont analysées.');
            }
            $pages[$url] = true;
        }

        return $this->json([
            'pages' => $this->crawler->crawl(array_keys($pages), $scope, $this->discovery->robots($root), $deadline),
        ]);
    }

    /**
     * @param array<string, string> $messages
     *
     * @return array<string, string>
     */
    private static function withPrefix(array $messages, string $prefix): array
    {
        $found = [];
        foreach ($messages as $key => $message) {
            if (str_starts_with($key, $prefix)) {
                $found[substr($key, \strlen($prefix))] = $message;
            }
        }

        return $found;
    }
}
