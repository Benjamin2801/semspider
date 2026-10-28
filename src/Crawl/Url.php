<?php

namespace App\Crawl;

// Adresses absolues normalisées : http(s) seulement, sans fragment, sans port par défaut, sans « . » ni « .. ».
final class Url
{
    public static function normalize(string $url): ?string
    {
        $url = str_replace(' ', '%20', (string) preg_replace('/#.*$/s', '', trim($url)));
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || '' === $parts['host']) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if (('http' === $scheme && 80 === $port) || ('https' === $scheme && 443 === $port)) {
            $port = null;
        }
        $query = $parts['query'] ?? '';

        return $scheme.'://'.strtolower($parts['host']).(null === $port ? '' : ':'.$port)
            .self::removeDotSegments($parts['path'] ?? '/')
            .('' === $query ? '' : '?'.$query);
    }

    // Adresse d'un lien (href, src) lu sur la page $base ; null pour mailto:, javascript:, data:, une ancre…
    public static function resolve(string $base, string $href): ?string
    {
        $href = trim($href);
        if ('' === $href || str_starts_with($href, '#')) {
            return null;
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $href, $match)) {
            return \in_array(strtolower($match[1]), ['http', 'https'], true) ? self::normalize($href) : null;
        }

        $parts = parse_url($base);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            return self::normalize($parts['scheme'].':'.$href);
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = $parts['path'] ?? '/';

        return self::normalize(match (true) {
            str_starts_with($href, '/') => $origin.$href,
            str_starts_with($href, '?') => $origin.$path.$href,
            default => $origin.substr($path, 0, (int) strrpos($path, '/') + 1).$href,
        });
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    // Chemin et paramètres : ce que lisent les règles de robots.txt.
    public static function pathAndQuery(string $url): string
    {
        $parts = parse_url($url);
        $query = $parts['query'] ?? '';

        return ($parts['path'] ?? '/').('' === $query ? '' : '?'.$query);
    }

    private static function removeDotSegments(string $path): string
    {
        $segments = explode('/', $path);
        $output = [];
        foreach ($segments as $segment) {
            if ('..' === $segment) {
                if (\count($output) > 1) {
                    array_pop($output);
                }
            } elseif ('.' !== $segment) {
                $output[] = $segment;
            }
        }
        if (\in_array(end($segments), ['.', '..'], true)) {
            $output[] = '';
        }

        return '/'.ltrim(implode('/', $output), '/');
    }
}
