<?php

namespace App\Crawl;

// Règles de robots.txt (RFC 9309) : le groupe qui nomme SemSpider, sinon celui de « * » ;
// la règle la plus longue l'emporte, Allow en cas d'égalité.
final class RobotsTxt
{
    private const AGENT = 'semspider';

    /**
     * @param list<array{bool, string}> $rules    [autorisé, motif]
     * @param list<string>              $sitemaps
     */
    private function __construct(
        private readonly array $rules,
        public readonly array $sitemaps,
    ) {
    }

    public static function allowAll(): self
    {
        return new self([], []);
    }

    public static function parse(string $content): self
    {
        /** @var array<string, list<array{bool, string}>> $groups */
        $groups = [];
        $sitemaps = [];
        $agents = [];
        $readingAgents = false;

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if (!preg_match('/^([a-z-]+)\s*:\s*(.*)$/i', $line, $match)) {
                continue;
            }
            [, $field, $value] = $match;
            $field = strtolower($field);

            if ('sitemap' === $field) {
                $sitemaps[] = $value;
                continue;
            }
            if ('user-agent' === $field) {
                if (!$readingAgents) {
                    $agents = [];
                }
                $agents[] = strtolower($value);
                $readingAgents = true;
                continue;
            }
            $readingAgents = false;
            if (!\in_array($field, ['allow', 'disallow'], true) || '' === $value) {
                continue;
            }
            foreach ($agents as $agent) {
                $groups[$agent][] = ['allow' === $field, $value];
            }
        }

        $rules = [];
        foreach ($groups as $agent => $agentRules) {
            if (self::AGENT === (string) $agent) {
                $rules = array_merge($rules, $agentRules);
            }
        }

        return new self($rules ?: $groups['*'] ?? [], $sitemaps);
    }

    public function allows(string $url): bool
    {
        $path = Url::pathAndQuery($url);
        $best = null;

        foreach ($this->rules as [$allow, $pattern]) {
            if (!self::matches($pattern, $path)) {
                continue;
            }
            $length = \strlen($pattern);
            if (null === $best || $length > $best[1] || ($length === $best[1] && $allow)) {
                $best = [$allow, $length];
            }
        }

        return $best[0] ?? true;
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $regex = str_replace('\*', '.*', preg_quote(rtrim($pattern, '$'), '#'));

        return 1 === preg_match('#^'.$regex.($anchored ? '$' : '').'#', $path);
    }
}
