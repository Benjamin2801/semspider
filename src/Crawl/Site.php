<?php

namespace App\Crawl;

final class Site
{
    /** @param list<string> $sitemapPages */
    public function __construct(
        public readonly string $root,
        public readonly array $sitemapPages,
    ) {
    }
}
