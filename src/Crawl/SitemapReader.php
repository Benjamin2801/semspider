<?php

namespace App\Crawl;

// Lit un sitemap XML : les adresses des pages (urlset) ou des autres sitemaps (sitemapindex).
final class SitemapReader
{
    /** @return array{pages: list<string>, sitemaps: list<string>} */
    public static function read(string $xml): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = '' !== trim($xml) && $document->loadXML($xml, \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || null === $document->documentElement) {
            return ['pages' => [], 'sitemaps' => []];
        }

        $locations = [];
        foreach ((new \DOMXPath($document))->query('/*/*/*[local-name()="loc"]') ?: [] as $node) {
            $url = Url::normalize((string) $node->nodeValue);
            if (null !== $url) {
                $locations[] = $url;
            }
        }
        $isIndex = 'sitemapindex' === $document->documentElement->localName;

        return ['pages' => $isIndex ? [] : $locations, 'sitemaps' => $isIndex ? $locations : []];
    }
}
