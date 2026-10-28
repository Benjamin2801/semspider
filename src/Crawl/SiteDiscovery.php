<?php

namespace App\Crawl;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Avant le crawl : l'adresse réelle de l'accueil (après redirections), robots.txt et les sitemaps.
final class SiteDiscovery
{
    private const ROBOTS_TTL = 3600;

    private const MAX_REDIRECTS = 5;

    private const MAX_SITEMAPS = 10;

    public function __construct(
        private readonly HttpClientInterface $siteClient,
        private readonly CacheInterface $cache,
    ) {
    }

    /** @throws SiteUnreachableException */
    public function discover(string $input, int $maxSitemapPages): Site
    {
        $input = trim($input);
        $url = preg_match('#^[a-z][a-z0-9+.-]*://#i', $input) ? Url::normalize($input) : Url::normalize('https://'.$input);
        if (null === $url || !str_contains((string) parse_url($url, \PHP_URL_HOST), '.')) {
            throw new SiteUnreachableException('invalid');
        }

        $root = $this->followRedirects($url);
        $robots = $this->robots($root);
        if (!$robots->allows($root)) {
            throw new SiteUnreachableException('robots');
        }

        return new Site($root, $this->sitemapPages($root, $robots, $maxSitemapPages));
    }

    public function robots(string $url): RobotsTxt
    {
        $origin = Url::origin($url);
        $content = $this->cache->get('robots.'.hash('xxh128', $origin), function (ItemInterface $item) use ($origin): string {
            $item->expiresAfter(self::ROBOTS_TTL);
            try {
                $response = $this->siteClient->request('GET', $origin.'/robots.txt', ['max_redirects' => 3, 'timeout' => 5]);

                return 200 === $response->getStatusCode() ? $response->getContent() : '';
            } catch (ExceptionInterface) {
                // Pas de robots.txt lisible : tout est permis, comme pour les moteurs quand le fichier est absent.
                return '';
            }
        });

        return RobotsTxt::parse($content);
    }

    private function followRedirects(string $url): string
    {
        for ($i = 0; $i <= self::MAX_REDIRECTS; ++$i) {
            try {
                $response = $this->siteClient->request('GET', $url, ['headers' => ['Accept' => 'text/html']]);
                $status = $response->getStatusCode();
                $location = $response->getHeaders(false)['location'][0] ?? null;
                $response->cancel();
            } catch (ExceptionInterface) {
                throw new SiteUnreachableException('network');
            }

            if ($status >= 300 && $status < 400 && null !== $location) {
                $url = Url::resolve($url, $location) ?? throw new SiteUnreachableException('network');
                continue;
            }
            if ($status >= 400) {
                throw new SiteUnreachableException('status', $status);
            }

            return $url;
        }

        throw new SiteUnreachableException('redirects');
    }

    /** @return list<string> */
    private function sitemapPages(string $root, RobotsTxt $robots, int $max): array
    {
        $scope = new SiteScope($root);
        $queue = $robots->sitemaps ?: [Url::origin($root).'/sitemap.xml'];
        $pages = [];

        for ($read = 0; [] !== $queue && $read < self::MAX_SITEMAPS && \count($pages) < $max; ++$read) {
            $sitemap = Url::normalize((string) array_shift($queue));
            if (null === $sitemap || !$scope->contains($sitemap)) {
                continue;
            }
            try {
                $response = $this->siteClient->request('GET', $sitemap, ['max_redirects' => 3, 'timeout' => 5]);
                $found = 200 === $response->getStatusCode() ? SitemapReader::read($response->getContent()) : null;
            } catch (ExceptionInterface) {
                $found = null;
            }
            foreach ($found['pages'] ?? [] as $page) {
                if ($scope->contains($page)) {
                    $pages[$page] = true;
                }
            }
            array_push($queue, ...$found['sitemaps'] ?? []);
        }

        return \array_slice(array_keys($pages), 0, $max);
    }
}
