<?php

namespace App\Crawl;

use function Symfony\Component\String\u;

// Lit le HTML d'une page avec le parseur de PHP (DOM), sans exécuter son JavaScript.
final class HtmlAnalyzer
{
    private const IGNORED_TEXT = 'ancestor::script or ancestor::style or ancestor::noscript or ancestor::template or ancestor::svg';

    public function analyze(string $html, string $url, SiteScope $scope, ?string $contentType = null): PageContent
    {
        $xpath = new \DOMXPath(self::load($html, $contentType));
        $base = Url::resolve($url, self::attribute($xpath, '//base[@href]', 'href') ?? '') ?? $url;

        [$links, $externalLinks] = self::links($xpath, $base, $scope);
        [$images, $resources] = self::resources($xpath, $base);
        $text = u(implode(' ', array_map(
            static fn (\DOMNode|\DOMNameSpaceNode $node): string => (string) $node->nodeValue,
            iterator_to_array($xpath->query('//body//text()[not('.self::IGNORED_TEXT.')]') ?: []),
        )))->collapseWhitespace()->trim()->toString();

        $mixed = str_starts_with($url, 'https:')
            ? \count(array_filter(array_keys($resources), static fn (string $r): bool => str_starts_with($r, 'http:')))
            : 0;
        $canonical = self::attribute($xpath, '//link[contains(concat(" ", normalize-space(translate(@rel, "CANONIL", "canonil")), " "), " canonical ")]', 'href');

        return new PageContent(
            title: self::text($xpath, '//title'),
            metaDescription: self::meta($xpath, 'description'),
            metaRobots: self::meta($xpath, 'robots'),
            canonical: null === $canonical ? null : Url::resolve($base, $canonical),
            lang: self::attribute($xpath, '/html[@lang]', 'lang'),
            viewport: null !== self::meta($xpath, 'viewport'),
            h1: self::texts($xpath, '//h1'),
            h2: self::texts($xpath, '//h2'),
            h3: self::texts($xpath, '//h3'),
            outline: self::outline($xpath),
            words: preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $text) ?: 0,
            textHash: hash('xxh128', mb_strtolower($text)),
            links: $links,
            externalLinks: $externalLinks,
            images: $images,
            resources: $resources,
            mixedContent: $mixed,
            hreflang: ($xpath->query('//link[@hreflang]') ?: new \DOMNodeList())->count(),
            openGraph: ($xpath->query('//meta[starts-with(@property, "og:")]') ?: new \DOMNodeList())->count() > 0,
            structuredData: self::structuredData($xpath),
        );
    }

    private static function load(string $html, ?string $contentType): \DOMDocument
    {
        $charset = preg_match('/charset=["\']?([\w-]+)/i', (string) $contentType, $match) ? strtolower($match[1]) : 'utf-8';
        if ('utf-8' !== $charset && \in_array($charset, array_map('strtolower', mb_list_encodings()), true)) {
            $html = mb_convert_encoding($html, 'UTF-8', $charset) ?: $html;
        } elseif (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252') ?: $html;
        }

        // Le parseur HTML de libxml (règles HTML 4) ferme un script au premier « </ » : le HTML écrit dans une
        // chaîne JavaScript ('<a href=…></a>') deviendrait des liens. Le code des scripts est donc vidé avant
        // la lecture ; dans les données structurées JSON-LD, gardées, « </ » devient « <\/ », la même valeur en JSON.
        $html = (string) preg_replace_callback(
            '#(<script\b[^>]*>)(.*?)(</script\s*>)#is',
            static fn (array $m): string => $m[1].(str_contains(strtolower($m[1]), 'ld+json') ? str_replace('</', '<\/', $m[2]) : '').$m[3],
            $html,
        );

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // La déclaration XML fait lire le texte en UTF-8 au parseur HTML, qui suppose sinon ISO-8859-1.
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, \LIBXML_NONET | \LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /** @return array{list<string>, int} */
    private static function links(\DOMXPath $xpath, string $base, SiteScope $scope): array
    {
        $internal = [];
        $external = 0;
        foreach ($xpath->query('//a[@href]') ?: [] as $link) {
            \assert($link instanceof \DOMElement);
            $url = Url::resolve($base, $link->getAttribute('href'));
            if (null === $url) {
                continue;
            }
            if ($scope->contains($url)) {
                $internal[$url] = true;
            } else {
                ++$external;
            }
        }

        return [array_keys($internal), $external];
    }

    /** @return array{list<PageImage>, array<string, ResourceType>} */
    private static function resources(\DOMXPath $xpath, string $base): array
    {
        $resources = [];
        $add = static function (?string $href, ResourceType $type) use ($base, &$resources): ?string {
            $url = null === $href ? null : Url::resolve($base, $href);
            if (null !== $url) {
                $resources[$url] ??= $type;
            }

            return $url;
        };

        foreach ($xpath->query('//link[@href][@rel]') ?: [] as $link) {
            \assert($link instanceof \DOMElement);
            $rel = ' '.strtolower($link->getAttribute('rel')).' ';
            $type = match (true) {
                str_contains($rel, ' stylesheet ') => ResourceType::Css,
                str_contains($rel, ' icon ') => ResourceType::Image,
                str_contains($rel, ' modulepreload ') => ResourceType::Js,
                str_contains($rel, ' preload ') => match (strtolower($link->getAttribute('as'))) {
                    'style' => ResourceType::Css,
                    'script' => ResourceType::Js,
                    'font' => ResourceType::Font,
                    'image' => ResourceType::Image,
                    default => null,
                },
                default => null,
            };
            if (null !== $type) {
                $add($link->getAttribute('href'), $type);
            }
        }
        foreach ($xpath->query('//script[@src]') ?: [] as $script) {
            \assert($script instanceof \DOMElement);
            $add($script->getAttribute('src'), ResourceType::Js);
        }
        foreach ($xpath->query('//video[@poster]') ?: [] as $video) {
            \assert($video instanceof \DOMElement);
            $add($video->getAttribute('poster'), ResourceType::Image);
        }
        foreach ($xpath->query('//iframe[@src]') ?: [] as $frame) {
            \assert($frame instanceof \DOMElement);
            $add($frame->getAttribute('src'), ResourceType::Other);
        }

        $images = [];
        foreach ($xpath->query('//img') ?: [] as $image) {
            \assert($image instanceof \DOMElement);
            // Sans src, le navigateur prend une des images de srcset : la première sert de mesure.
            $src = $image->getAttribute('src') ?: explode(' ', trim(explode(',', $image->getAttribute('srcset'))[0]))[0];
            $url = $add($src, ResourceType::Image);
            if (null !== $url) {
                $images[] = new PageImage(
                    $url,
                    $image->hasAttribute('alt') ? trim($image->getAttribute('alt')) : null,
                    $image->hasAttribute('width') && $image->hasAttribute('height'),
                    'lazy' === strtolower($image->getAttribute('loading')),
                );
            }
        }

        return [$images, $resources];
    }

    // Les titres H1 à H6 dans l'ordre de la page.
    /** @return list<array{level: int, text: string}> */
    private static function outline(\DOMXPath $xpath): array
    {
        $outline = [];
        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $heading) {
            $outline[] = [
                'level' => (int) substr($heading->nodeName, 1),
                'text' => u((string) $heading->nodeValue)->collapseWhitespace()->trim()->toString(),
            ];
        }

        return $outline;
    }

    /** @return list<string> */
    private static function structuredData(\DOMXPath $xpath): array
    {
        $types = [];
        $collect = static function (mixed $data) use (&$collect, &$types): void {
            if (!\is_array($data)) {
                return;
            }
            foreach ((array) ($data['@type'] ?? []) as $type) {
                if (\is_string($type)) {
                    $types[$type] = true;
                }
            }
            foreach ($data as $key => $value) {
                if ('@type' !== $key) {
                    $collect($value);
                }
            }
        };
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $collect(json_decode((string) $script->nodeValue, true));
        }

        return \array_slice(array_keys($types), 0, 10);
    }

    private static function meta(\DOMXPath $xpath, string $name): ?string
    {
        return self::attribute($xpath, '//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="'.$name.'"]', 'content');
    }

    private static function attribute(\DOMXPath $xpath, string $query, string $attribute): ?string
    {
        $node = ($xpath->query($query) ?: new \DOMNodeList())->item(0);

        return $node instanceof \DOMElement ? u($node->getAttribute($attribute))->collapseWhitespace()->trim()->toString() : null;
    }

    private static function text(\DOMXPath $xpath, string $query): ?string
    {
        return self::texts($xpath, $query)[0] ?? null;
    }

    /** @return list<string> */
    private static function texts(\DOMXPath $xpath, string $query): array
    {
        $texts = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            $texts[] = u((string) $node->nodeValue)->collapseWhitespace()->trim()->toString();
        }

        return $texts;
    }
}
