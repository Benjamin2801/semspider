<?php

namespace App\Crawl;

use function Symfony\Component\String\u;

// Problèmes qu'une page révèle à elle seule. Les seuils suivent les repères publics les plus courants.
final class PageAudit
{
    // Google coupe le titre vers 600 px, soit 55 à 60 caractères ; en dessous de 30, il décrit mal la page.
    public const TITLE_LENGTH = [30, 60];
    // Extrait coupé vers 155-160 caractères ; en dessous de 70, Google le remplace souvent.
    public const META_LENGTH = [70, 160];
    public const THIN_CONTENT_WORDS = 300;
    // web.dev : un TTFB est « bon » jusqu'à 800 ms.
    public const SLOW_TTFB_MS = 800;
    public const SLOW_LOAD_MS = 3000;
    // Lighthouse pénalise le poids total à partir d'environ 2,6 Mo.
    public const HEAVY_PAGE_BYTES = 2_500_000;
    public const HEAVY_IMAGE_BYTES = 100_000;
    public const MANY_REQUESTS = 80;
    // En dessous, la compression ne fait rien gagner.
    private const COMPRESSIBLE_BYTES = 1_000;

    /** @return list<Issue> */
    public static function issues(PageReport $page): array
    {
        if ('robots' === $page->error) {
            return [Issue::Blocked];
        }
        if (0 === $page->status || $page->status >= 400) {
            return [Issue::HttpError];
        }
        if (null !== $page->redirect) {
            return [Issue::Redirect];
        }
        $content = $page->content;
        if (null === $content) {
            return [];
        }

        $robots = strtolower($content->metaRobots.' '.$page->xRobotsTag);
        $title = u($content->title ?? '')->length();
        $meta = u($content->metaDescription ?? '')->length();
        $images = $content->images;

        $found = array_filter([
            Issue::Noindex->value => str_contains($robots, 'noindex') || str_contains($robots, 'none'),
            Issue::CanonicalElsewhere->value => null !== $content->canonical && $content->canonical !== $page->url,
            Issue::TitleMissing->value => 0 === $title,
            Issue::TitleShort->value => $title > 0 && $title < self::TITLE_LENGTH[0],
            Issue::TitleLong->value => $title > self::TITLE_LENGTH[1],
            Issue::MetaMissing->value => 0 === $meta,
            Issue::MetaShort->value => $meta > 0 && $meta < self::META_LENGTH[0],
            Issue::MetaLong->value => $meta > self::META_LENGTH[1],
            Issue::H1Missing->value => [] === array_filter($content->h1),
            Issue::H1Multiple->value => \count($content->h1) > 1,
            Issue::HeadingSkip->value => $content->headingSkip(),
            Issue::ThinContent->value => $content->words < self::THIN_CONTENT_WORDS,
            Issue::LangMissing->value => '' === (string) $content->lang,
            Issue::ViewportMissing->value => !$content->viewport,
            Issue::ImageAltMissing->value => [] !== array_filter($images, static fn (PageImage $i): bool => null === $i->alt),
            Issue::ImageHeavy->value => [] !== $page->heavyImages,
            Issue::ImageDimensions->value => [] !== array_filter($images, static fn (PageImage $i): bool => !$i->dimensions),
            Issue::HeavyPage->value => $page->totalBytes() > self::HEAVY_PAGE_BYTES,
            Issue::SlowTtfb->value => $page->ttfb > self::SLOW_TTFB_MS,
            Issue::SlowLoad->value => $page->loadMs > self::SLOW_LOAD_MS,
            Issue::ManyRequests->value => $page->requests > self::MANY_REQUESTS,
            Issue::NoCompression->value => !$page->compressed && ($page->bytes[ResourceType::Html->value] ?? 0) > self::COMPRESSIBLE_BYTES,
            Issue::BrokenResources->value => [] !== $page->brokenResources,
            Issue::MixedContent->value => $content->mixedContent > 0,
            Issue::Incomplete->value => $page->incomplete,
        ]);

        return array_map(static fn (string $code): Issue => Issue::from($code), array_keys($found));
    }
}
