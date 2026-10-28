<?php

namespace App\Tests\Crawl;

use App\Crawl\RobotsTxt;
use App\Crawl\SitemapReader;
use PHPUnit\Framework\TestCase;

final class RobotsTxtTest extends TestCase
{
    public function testLongestRuleWinsAndAllowWinsTies(): void
    {
        $robots = RobotsTxt::parse(<<<'TXT'
            User-agent: *
            Disallow: /admin/
            Allow: /admin/public/
            Disallow: /*.pdf$
            Disallow: /*?session=
            Disallow:
            Allow: /egal
            Disallow: /egal

            Sitemap: https://site.test/sitemap.xml
            TXT);

        self::assertTrue($robots->allows('https://site.test/'));
        self::assertFalse($robots->allows('https://site.test/admin/page'));
        self::assertTrue($robots->allows('https://site.test/admin/public/page'));
        self::assertFalse($robots->allows('https://site.test/docs/guide.pdf'));
        self::assertTrue($robots->allows('https://site.test/docs/guide.pdf?v=2'), 'Le $ ancre le motif en fin d\'adresse.');
        self::assertFalse($robots->allows('https://site.test/panier?session=42'));
        self::assertTrue($robots->allows('https://site.test/egal'));
        self::assertSame(['https://site.test/sitemap.xml'], $robots->sitemaps);
    }

    public function testOwnGroupReplacesTheGenericOne(): void
    {
        $robots = RobotsTxt::parse(<<<'TXT'
            User-agent: *
            Disallow: /

            User-agent: Googlebot
            User-agent: SemSpider
            Disallow: /prive # commentaire
            TXT);

        self::assertTrue($robots->allows('https://site.test/page'));
        self::assertFalse($robots->allows('https://site.test/prive/page'));
    }

    public function testEmptyFileAllowsEverything(): void
    {
        self::assertTrue(RobotsTxt::parse('')->allows('https://site.test/a'));
        self::assertTrue(RobotsTxt::allowAll()->allows('https://site.test/a'));
    }

    public function testSitemapPagesAndIndex(): void
    {
        $pages = SitemapReader::read(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
                <url><loc> https://site.test/a </loc><image:image><image:loc>https://site.test/i.jpg</image:loc></image:image></url>
                <url><loc>https://site.test/b#x</loc></url>
            </urlset>
            XML);
        $index = SitemapReader::read('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://site.test/s1.xml</loc></sitemap></sitemapindex>');

        self::assertSame(['pages' => ['https://site.test/a', 'https://site.test/b'], 'sitemaps' => []], $pages);
        self::assertSame(['pages' => [], 'sitemaps' => ['https://site.test/s1.xml']], $index);
        self::assertSame(['pages' => [], 'sitemaps' => []], SitemapReader::read('<html>pas un sitemap'));
    }
}
