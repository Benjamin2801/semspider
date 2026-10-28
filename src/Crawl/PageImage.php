<?php

namespace App\Crawl;

final class PageImage
{
    public function __construct(
        public readonly string $url,
        // null : attribut absent ; "" : image décorative, ce qui est correct.
        public readonly ?string $alt,
        public readonly bool $dimensions,
        public readonly bool $lazy,
    ) {
    }
}
