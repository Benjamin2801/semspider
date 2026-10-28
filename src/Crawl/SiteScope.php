<?php

namespace App\Crawl;

// Le site crawlé : les pages de son domaine, avec ou sans « www. », en http comme en https.
final class SiteScope
{
    private readonly string $host;

    public function __construct(public readonly string $root)
    {
        $this->host = self::bareHost($root);
    }

    public function contains(string $url): bool
    {
        return '' !== $this->host && self::bareHost($url) === $this->host;
    }

    private static function bareHost(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');

        return (str_starts_with($host, 'www.') ? substr($host, 4) : $host).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
