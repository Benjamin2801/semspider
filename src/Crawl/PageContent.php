<?php

namespace App\Crawl;

// Ce que dit le HTML d'une page : balises SEO, texte, liens et ressources à mesurer.
final class PageContent
{
    /**
     * @param list<string>                          $h1
     * @param list<string>                          $h2
     * @param list<string>                          $h3
     * @param list<array{level: int, text: string}> $outline        titres H1 à H6 dans l'ordre de la page
     * @param list<string>                          $links          liens internes, adresses absolues, sans doublon
     * @param list<PageImage>                       $images
     * @param array<string, ResourceType>           $resources      adresse → type attendu
     * @param list<string>                          $structuredData types schema.org des blocs JSON-LD
     */
    public function __construct(
        public readonly ?string $title,
        public readonly ?string $metaDescription,
        public readonly ?string $metaRobots,
        public readonly ?string $canonical,
        public readonly ?string $lang,
        public readonly bool $viewport,
        public readonly array $h1,
        public readonly array $h2,
        public readonly array $h3,
        public readonly array $outline,
        public readonly int $words,
        public readonly string $textHash,
        public readonly array $links,
        public readonly int $externalLinks,
        public readonly array $images,
        public readonly array $resources,
        public readonly int $mixedContent,
        public readonly int $hreflang,
        public readonly bool $openGraph,
        public readonly array $structuredData,
    ) {
    }

    // Un niveau de titre sauté : un H3 directement après le H1, un H4 sous un H2…
    public function headingSkip(): bool
    {
        $previous = 1;
        foreach ($this->outline as ['level' => $level]) {
            if ($level > $previous + 1) {
                return true;
            }
            $previous = $level;
        }

        return false;
    }
}
