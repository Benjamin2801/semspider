<?php

namespace App\Crawl;

final class SiteUnreachableException extends \RuntimeException
{
    // $reason : invalid, network, status, redirects ou robots (clé de traduction crawl.error.*).
    public function __construct(
        public readonly string $reason,
        public readonly ?int $status = null,
    ) {
        parent::__construct($reason);
    }
}
