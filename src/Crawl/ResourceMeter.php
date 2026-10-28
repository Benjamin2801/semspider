<?php

namespace App\Crawl;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// Télécharge en parallèle les ressources des pages d'un lot, puis les polices et feuilles importées
// que déclarent leurs CSS.
final class ResourceMeter
{
    private const CACHE_TTL = 3600;

    private const FONT_EXTENSIONS = ['woff2', 'woff', 'ttf', 'otf', 'eot'];

    public function __construct(
        private readonly HttpClientInterface $siteClient,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param array<string, ResourceType> $resources adresse → type attendu
     *
     * @return array<string, ResourceMeasure> les ressources mesurées avant $deadline (microtime)
     */
    public function measure(array $resources, float $deadline): array
    {
        $measures = [];
        // Deux vagues : les ressources des pages, puis celles que déclarent leurs feuilles de style.
        for ($wave = 0; $wave < 2 && [] !== $resources; ++$wave) {
            $measures += $this->measureWave($resources, $deadline);

            $children = [];
            foreach ($measures as $measure) {
                $children += $measure->children;
            }
            $resources = array_diff_key($children, $measures);
        }

        return $measures;
    }

    /**
     * @param array<string, ResourceType> $resources
     *
     * @return array<string, ResourceMeasure>
     */
    private function measureWave(array $resources, float $deadline): array
    {
        $measures = [];
        $keys = array_map(self::cacheKey(...), array_keys($resources));
        foreach ($this->cache->getItems($keys) as $item) {
            $measure = $item->get();
            if ($item->isHit() && $measure instanceof ResourceMeasure) {
                $measures[$measure->url] = $measure;
            }
        }

        $responses = [];
        foreach (array_diff_key($resources, $measures) as $url => $type) {
            try {
                $responses[] = $this->siteClient->request('GET', $url, [
                    'max_redirects' => 3,
                    // Seul le texte des feuilles de style est lu (polices, @import).
                    'buffer' => ResourceType::Css === $type,
                    'user_data' => [$url, $type],
                ]);
            } catch (TransportExceptionInterface) {
                $measures[$url] = new ResourceMeasure($url, $type, 0, 0, 0);
            }
        }

        $pending = [];
        $received = [];
        foreach ($responses as $response) {
            $pending[spl_object_id($response)] = $response;
            $received[spl_object_id($response)] = 0;
        }
        foreach ($this->siteClient->stream($responses, 1.0) as $response => $chunk) {
            if (microtime(true) > $deadline) {
                break;
            }
            [$url, $type] = $response->getInfo('user_data');
            try {
                if ($chunk->isTimeout()) {
                    continue;
                }
                if ($chunk->isFirst()) {
                    // Code lu ici : une 404 est une mesure, pas une exception.
                    $response->getStatusCode();
                }
                $received[spl_object_id($response)] += \strlen($chunk->getContent());
                if (!$chunk->isLast()) {
                    continue;
                }
                $measure = $this->measureOf($response, $url, $type, $received[spl_object_id($response)]);
            } catch (TransportExceptionInterface) {
                $measure = new ResourceMeasure($url, $type, 0, 0, 0);
            }
            unset($pending[spl_object_id($response)]);
            $measures[$url] = $measure;
            $this->save($measure);
        }
        // Les ressources encore en route à l'échéance restent non mesurées.
        foreach ($pending as $response) {
            $response->cancel();
        }

        return $measures;
    }

    private function measureOf(ResponseInterface $response, string $url, ResourceType $expected, int $received): ResourceMeasure
    {
        $status = $response->getStatusCode();
        $headers = $response->getHeaders(false);
        $type = $status < 400 ? ResourceType::fromContentType($headers['content-type'][0] ?? null, $expected) : $expected;
        $info = $response->getInfo();

        return new ResourceMeasure(
            $url,
            $type,
            self::transferred($info, $received),
            self::milliseconds($info),
            $status,
            ResourceType::Css === $type && $status < 400 ? self::cssChildren($response->getContent(false), (string) ($info['url'] ?? $url)) : [],
        );
    }

    // Les images de fond ne sont pas comptées : seules celles des sélecteurs utilisés sont téléchargées.
    /** @return array<string, ResourceType> */
    private static function cssChildren(string $css, string $base): array
    {
        $children = [];
        preg_match_all('/@import\s+(?:url\(\s*)?["\']?([^"\')\s;]+)/i', $css, $imports);
        foreach ($imports[1] as $href) {
            $url = Url::resolve($base, $href);
            if (null !== $url) {
                $children[$url] = ResourceType::Css;
            }
        }
        preg_match_all('/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $css, $urls);
        foreach ($urls[1] as $href) {
            $extension = strtolower(pathinfo((string) parse_url($href, \PHP_URL_PATH), \PATHINFO_EXTENSION));
            $url = \in_array($extension, self::FONT_EXTENSIONS, true) ? Url::resolve($base, $href) : null;
            if (null !== $url) {
                $children[$url] ??= ResourceType::Font;
            }
        }

        return $children;
    }

    // Toutes les ressources partent en même temps : sans retirer la file d'attente des connexions (6 par serveur)
    // et leur ouverture, que le navigateur fait une fois pour toutes, les dernières paraîtraient lentes.
    /** @param array<string, mixed> $info */
    public static function milliseconds(array $info): int
    {
        $total = (float) ($info['total_time'] ?? 0);
        $queued = (float) ($info['pretransfer_time'] ?? 0);

        return (int) round(max(0.0, $total - $queued) * 1000);
    }

    // curl compte les octets reçus avant décompression ; sans curl, on compte ceux du contenu.
    /** @param array<string, mixed> $info */
    public static function transferred(array $info, int $received): int
    {
        return isset($info['size_download']) ? (int) $info['size_download'] : $received;
    }

    private function save(ResourceMeasure $measure): void
    {
        // Une panne passagère ne doit pas être gardée une heure.
        if (0 === $measure->status) {
            return;
        }
        $item = $this->cache->getItem(self::cacheKey($measure->url));
        $this->cache->save($item->set($measure)->expiresAfter(self::CACHE_TTL));
    }

    private static function cacheKey(string $url): string
    {
        return 'resource.'.hash('xxh128', $url);
    }
}
