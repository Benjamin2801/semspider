<?php

namespace App\Crawl;

// Une ligne du tableau : mesures et contenu d'une page, envoyés au navigateur en JSON.
final class PageReport implements \JsonSerializable
{
    /**
     * @param array<string, int>                    $bytes           octets par type (ResourceType), HTML compris
     * @param list<array{url: string, bytes: int}>  $heavyImages
     * @param list<array{url: string, status: int}> $brokenResources
     */
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly ?string $error = null,
        public readonly ?string $contentType = null,
        public readonly ?string $redirect = null,
        public readonly ?int $ttfb = null,
        public readonly ?int $htmlMs = null,
        public readonly ?int $loadMs = null,
        public readonly array $bytes = [],
        public readonly int $requests = 0,
        public readonly bool $compressed = false,
        public readonly ?string $xRobotsTag = null,
        public readonly ?PageContent $content = null,
        public readonly array $heavyImages = [],
        public readonly array $brokenResources = [],
        public readonly bool $incomplete = false,
    ) {
    }

    public function totalBytes(): int
    {
        return array_sum($this->bytes);
    }

    public function isHtml(): bool
    {
        return null !== $this->content;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $content = $this->content;

        return [
            'url' => $this->url,
            'status' => $this->status,
            'error' => $this->error,
            'contentType' => $this->contentType,
            'redirect' => $this->redirect,
            'ttfb' => $this->ttfb,
            'htmlMs' => $this->htmlMs,
            'loadMs' => $this->loadMs,
            'bytes' => (object) $this->bytes,
            'totalBytes' => $this->totalBytes(),
            'requests' => $this->requests,
            'compressed' => $this->compressed,
            'html' => $this->isHtml(),
            'title' => $content?->title,
            'metaDescription' => $content?->metaDescription,
            'robots' => implode(', ', array_filter([$content?->metaRobots, $this->xRobotsTag])) ?: null,
            'canonical' => $content?->canonical,
            'lang' => $content?->lang,
            'h1' => $content->h1 ?? [],
            'h2' => $content->h2 ?? [],
            'h3' => $content->h3 ?? [],
            'outline' => \array_slice($content->outline ?? [], 0, 80),
            'words' => $content?->words,
            'textHash' => $content?->textHash,
            'links' => $content->links ?? [],
            'externalLinks' => $content?->externalLinks,
            'images' => \count($content->images ?? []),
            'imagesWithoutAlt' => array_values(array_map(
                static fn (PageImage $image): string => $image->url,
                array_filter($content->images ?? [], static fn (PageImage $image): bool => null === $image->alt),
            )),
            'heavyImages' => $this->heavyImages,
            'brokenResources' => $this->brokenResources,
            'hreflang' => $content?->hreflang,
            'openGraph' => $content?->openGraph,
            'structuredData' => $content->structuredData ?? [],
            'incomplete' => $this->incomplete,
            'issues' => array_map(static fn (Issue $issue): string => $issue->value, PageAudit::issues($this)),
        ];
    }
}
