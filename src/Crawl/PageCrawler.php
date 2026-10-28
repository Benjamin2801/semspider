<?php

namespace App\Crawl;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// Analyse un lot de pages : HTML en parallèle, puis toutes leurs ressources en parallèle.
final class PageCrawler
{
    // Au-delà, ce n'est plus une page web : le téléchargement est interrompu.
    private const MAX_HTML_BYTES = 5_000_000;

    public function __construct(
        private readonly HttpClientInterface $siteClient,
        private readonly HtmlAnalyzer $analyzer,
        private readonly ResourceMeter $meter,
        #[Autowire(param: 'app.crawl.max_resources')]
        private readonly int $maxResources,
    ) {
    }

    /**
     * @param list<string> $urls
     *
     * @return list<PageReport>
     */
    public function crawl(array $urls, SiteScope $scope, RobotsTxt $robots, float $deadline): array
    {
        $reports = [];
        $responses = [];
        foreach ($urls as $url) {
            if (!$robots->allows($url)) {
                $reports[$url] = new PageReport($url, 0, error: 'robots');
                continue;
            }
            try {
                $responses[] = $this->siteClient->request('GET', $url, [
                    'headers' => ['Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'],
                    'user_data' => $url,
                ]);
            } catch (TransportExceptionInterface) {
                $reports[$url] = new PageReport($url, 0, error: 'network');
            }
        }

        $pages = [];
        foreach ($this->fetch($responses) as $url => [$response, $html, $error]) {
            if (null === $html) {
                $reports[$url] = $this->report($url, $response, $error);
                continue;
            }
            $content = $this->analyzer->analyze($html, $url, $scope, $response->getHeaders(false)['content-type'][0] ?? null);
            $pages[$url] = [$response, $content, \array_slice($content->resources, 0, $this->maxResources, true), \strlen($html)];
        }

        $wanted = [];
        foreach ($pages as [, , $resources]) {
            $wanted += $resources;
        }
        $measures = $this->meter->measure($wanted, $deadline);

        foreach ($pages as $url => [$response, $content, $resources, $received]) {
            $reports[$url] = $this->report($url, $response, null, $content, $resources, $measures, $received);
        }

        return array_values(array_filter(array_map(static fn (string $url): ?PageReport => $reports[$url] ?? null, $urls)));
    }

    /**
     * @param list<ResponseInterface> $responses
     *
     * @return array<string, array{ResponseInterface, ?string, ?string}> adresse → [réponse, HTML, erreur]
     */
    private function fetch(array $responses): array
    {
        $results = [];
        foreach ($this->siteClient->stream($responses) as $response => $chunk) {
            $url = $response->getInfo('user_data');
            if (isset($results[$url])) {
                continue;
            }
            try {
                if ($chunk->isFirst() && !self::isHtml($response)) {
                    // Un PDF ou une image liés depuis une page : le code HTTP suffit.
                    $response->cancel();
                    $results[$url] = [$response, null, null];
                } elseif ($response->getInfo('size_download') > self::MAX_HTML_BYTES) {
                    $response->cancel();
                    $results[$url] = [$response, null, 'too_large'];
                } elseif ($chunk->isLast()) {
                    $results[$url] = [$response, $response->getContent(false), null];
                }
            } catch (TransportExceptionInterface) {
                $results[$url] = [$response, null, 'network'];
            }
        }

        return $results;
    }

    private static function isHtml(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();
        $type = ResourceType::fromContentType($response->getHeaders(false)['content-type'][0] ?? null, ResourceType::Html);

        return $status >= 200 && $status < 300 && ResourceType::Html === $type;
    }

    /**
     * @param array<string, ResourceType>    $resources
     * @param array<string, ResourceMeasure> $measures
     */
    private function report(string $url, ResponseInterface $response, ?string $error, ?PageContent $content = null, array $resources = [], array $measures = [], int $received = 0): PageReport
    {
        try {
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
        } catch (TransportExceptionInterface) {
            return new PageReport($url, 0, error: $error ?? 'network');
        }
        $info = $response->getInfo();
        $location = $headers['location'][0] ?? null;
        $htmlBytes = ResourceMeter::transferred($info, $received);

        $bytes = array_fill_keys(array_map(static fn (ResourceType $t): string => $t->value, ResourceType::cases()), 0);
        $bytes[ResourceType::Html->value] = $htmlBytes;
        $requests = 1;
        $slowest = 0;
        $broken = [];
        $expected = $resources;
        foreach ($resources as $resourceUrl => $type) {
            $measure = $measures[$resourceUrl] ?? null;
            if (null === $measure) {
                continue;
            }
            // Polices et feuilles importées ne partent qu'une fois leur feuille de style reçue.
            foreach ($measure->children as $childUrl => $childType) {
                $expected[$childUrl] ??= $childType;
                $child = $measures[$childUrl] ?? null;
                if (null !== $child && !isset($resources[$childUrl])) {
                    $slowest = max($slowest, $measure->ms + $child->ms);
                }
            }
            $slowest = max($slowest, $measure->ms);
        }
        foreach (array_intersect_key($measures, $expected) as $measure) {
            $bytes[$measure->type->value] += $measure->bytes;
            ++$requests;
            if ($measure->isBroken()) {
                $broken[] = ['url' => $measure->url, 'status' => $measure->status];
            }
        }

        $heavyImages = [];
        foreach ($content->images ?? [] as $image) {
            $bytesOfImage = $measures[$image->url]->bytes ?? 0;
            if ($bytesOfImage > PageAudit::HEAVY_IMAGE_BYTES) {
                $heavyImages[$image->url] = ['url' => $image->url, 'bytes' => $bytesOfImage];
            }
        }
        $htmlMs = (int) round((float) ($info['total_time'] ?? 0) * 1000);

        return new PageReport(
            url: $url,
            status: $status,
            error: $error,
            contentType: $headers['content-type'][0] ?? null,
            redirect: $status >= 300 && $status < 400 && null !== $location ? Url::resolve($url, $location) : null,
            ttfb: (int) round((float) ($info['starttransfer_time'] ?? 0) * 1000),
            htmlMs: $htmlMs,
            loadMs: null === $content ? null : $htmlMs + $slowest,
            bytes: null === $content ? [ResourceType::Html->value => $htmlBytes] : $bytes,
            requests: $requests,
            compressed: isset($headers['content-encoding']),
            xRobotsTag: $headers['x-robots-tag'][0] ?? null,
            content: $content,
            heavyImages: array_values($heavyImages),
            brokenResources: $broken,
            incomplete: \count(array_intersect_key($measures, $expected)) < \count($expected),
        );
    }
}
