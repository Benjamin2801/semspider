<?php

namespace App\Tests\Crawl;

use App\Crawl\Issue;
use App\Crawl\PageAudit;
use App\Crawl\PageCrawler;
use App\Crawl\PageReport;
use App\Crawl\SiteDiscovery;
use App\Crawl\SiteScope;
use App\Crawl\SiteUnreachableException;
use App\Tests\Http\FakeSite;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PageCrawlerTest extends KernelTestCase
{
    protected function setUp(): void
    {
        $cache = static::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function service(string $class): object
    {
        $service = static::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /** @return array<string, PageReport> */
    private static function crawl(string ...$paths): array
    {
        $discovery = self::service(SiteDiscovery::class);

        $reports = self::service(PageCrawler::class)->crawl(
            array_values(array_map(static fn (string $path): string => FakeSite::ROOT.$path, $paths)),
            new SiteScope(FakeSite::ROOT),
            $discovery->robots(FakeSite::ROOT),
            microtime(true) + 30,
        );

        return array_combine(array_map(static fn (PageReport $r): string => $r->url, $reports), $reports);
    }

    public function testMeasuresHomeAndItsResources(): void
    {
        $home = self::crawl('')[FakeSite::ROOT];

        self::assertSame(200, $home->status);
        self::assertTrue($home->compressed);
        self::assertSame(\strlen(FakeSite::HOME), $home->bytes['html']);
        // style.css + theme.css importé ; la police déclarée par style.css ; l'image de fond n'est pas comptée.
        self::assertSame(\strlen('@import "theme.css"; @font-face { src: url("/fonts/a.woff2") format("woff2"); } body { background: url(fond.png); }') + \strlen('h1 { color: red; }'), $home->bytes['css']);
        self::assertSame(20_000, $home->bytes['font']);
        self::assertSame(30_000, $home->bytes['js']);
        // big.jpg, logo.png et la favicon introuvable (11 octets de page d'erreur).
        self::assertSame(155_011, $home->bytes['image']);
        self::assertSame(8, $home->requests, 'HTML, 2 CSS, police, JS, 3 images.');
        self::assertSame([['url' => 'https://www.site.test/big.jpg', 'bytes' => 150_000]], $home->heavyImages);
        self::assertSame([['url' => 'https://www.site.test/favicon.ico', 'status' => 404]], $home->brokenResources);
        self::assertFalse($home->incomplete);
        self::assertGreaterThanOrEqual($home->htmlMs, $home->loadMs);

        $issues = PageAudit::issues($home);
        self::assertContains(Issue::ThinContent, $issues);
        self::assertContains(Issue::ImageAltMissing, $issues);
        self::assertContains(Issue::ImageHeavy, $issues);
        self::assertContains(Issue::ImageDimensions, $issues);
        self::assertContains(Issue::BrokenResources, $issues);
        self::assertNotContains(Issue::TitleMissing, $issues);
        self::assertNotContains(Issue::CanonicalElsewhere, $issues);
        self::assertNotContains(Issue::NoCompression, $issues);
    }

    public function testReportsErrorsRedirectsFilesAndRobots(): void
    {
        $reports = self::crawl('a', 'b', 'old', 'doc.pdf', 'private/x');

        self::assertSame([Issue::TitleMissing, Issue::MetaMissing, Issue::H1Multiple, Issue::HeadingSkip, Issue::ThinContent, Issue::LangMissing, Issue::ViewportMissing],
            PageAudit::issues($reports['https://www.site.test/a']));
        self::assertSame(404, $reports['https://www.site.test/b']->status);
        self::assertSame([Issue::HttpError], PageAudit::issues($reports['https://www.site.test/b']));
        self::assertSame('https://www.site.test/a', $reports['https://www.site.test/old']->redirect);
        self::assertFalse($reports['https://www.site.test/doc.pdf']->isHtml());
        self::assertSame([], PageAudit::issues($reports['https://www.site.test/doc.pdf']));
        self::assertSame('robots', $reports['https://www.site.test/private/x']->error);
        self::assertSame([Issue::Blocked], PageAudit::issues($reports['https://www.site.test/private/x']));
        self::assertNotContains('https://www.site.test/private/x', self::service(FakeSite::class)->requestedUrls);
    }

    public function testSharedResourcesAreDownloadedOnce(): void
    {
        self::crawl('');
        $site = self::service(FakeSite::class);
        $site->requestedUrls = [];

        self::service(PageCrawler::class)->crawl([FakeSite::ROOT], new SiteScope(FakeSite::ROOT), self::service(SiteDiscovery::class)->robots(FakeSite::ROOT), microtime(true) + 30);

        self::assertSame([FakeSite::ROOT], $site->requestedUrls, 'Les ressources mesurées une fois ne sont plus téléchargées.');
    }

    public function testDiscoveryFollowsRedirectsAndReadsTheSitemap(): void
    {
        $site = self::service(SiteDiscovery::class)->discover('site.test', 500);

        self::assertSame(FakeSite::ROOT, $site->root);
        self::assertSame([FakeSite::ROOT, 'https://www.site.test/a', 'https://www.site.test/orphan'], $site->sitemapPages);
    }

    public function testDiscoveryRejectsUnreachableSites(): void
    {
        $discovery = self::service(SiteDiscovery::class);

        foreach (['https://down.test' => 'status', 'ftp://site.test' => 'invalid', 'https:// ' => 'invalid'] as $input => $reason) {
            try {
                $discovery->discover($input, 500);
                self::fail($input.' aurait dû être refusé.');
            } catch (SiteUnreachableException $e) {
                self::assertSame($reason, $e->reason, $input);
            }
        }
    }
}
