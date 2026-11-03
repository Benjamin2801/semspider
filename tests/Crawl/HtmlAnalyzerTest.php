<?php

namespace App\Tests\Crawl;

use App\Crawl\HtmlAnalyzer;
use App\Crawl\ResourceMeasure;
use App\Crawl\ResourceMeter;
use App\Crawl\ResourceType;
use App\Crawl\SiteScope;
use App\Tests\Http\FakeSite;
use PHPUnit\Framework\TestCase;

final class HtmlAnalyzerTest extends TestCase
{
    public function testReadsSeoTagsLinksAndResources(): void
    {
        $page = (new HtmlAnalyzer())->analyze(FakeSite::HOME, FakeSite::ROOT, new SiteScope(FakeSite::ROOT));

        self::assertSame('Accueil du site de test : bienvenue et présentation', $page->title);
        self::assertStringStartsWith('Une description assez longue', (string) $page->metaDescription);
        self::assertSame(FakeSite::ROOT, $page->canonical);
        self::assertSame('fr', $page->lang);
        self::assertTrue($page->viewport);
        self::assertSame(['Bienvenue sur le site'], $page->h1);
        self::assertSame(['Nos services', 'Contact'], $page->h2);
        self::assertSame(['Conseil'], $page->h3);
        self::assertFalse($page->headingSkip());
        self::assertTrue($page->openGraph);
        self::assertSame(['Organization', 'WebSite'], $page->structuredData);

        self::assertSame([
            'https://www.site.test/a',
            'https://www.site.test/b',
            'https://www.site.test/old',
            'https://www.site.test/private/x',
            'https://www.site.test/doc.pdf',
            'https://site.test/a',
        ], $page->links, 'Liens internes sans doublon ni ancre, www ou non.');
        self::assertSame(1, $page->externalLinks);

        self::assertSame([
            'https://www.site.test/style.css' => ResourceType::Css,
            'https://www.site.test/favicon.ico' => ResourceType::Image,
            'https://www.site.test/app.js' => ResourceType::Js,
            'https://www.site.test/big.jpg' => ResourceType::Image,
            'https://www.site.test/logo.png' => ResourceType::Image,
        ], $page->resources);
        self::assertNull($page->images[0]->alt);
        self::assertFalse($page->images[0]->dimensions);
        self::assertSame('Logo', $page->images[1]->alt);
        self::assertTrue($page->images[1]->dimensions);
        self::assertTrue($page->images[1]->lazy);
    }

    public function testCountsVisibleWordsOnly(): void
    {
        $page = (new HtmlAnalyzer())->analyze(
            '<html><head><title>T</title><style>p { color: red }</style></head><body><p>Un deux, l\'aube trois-quatre 2026.</p><script>cinq six</script><noscript>sept</noscript></body></html>',
            'https://site.test/',
            new SiteScope('https://site.test/'),
        );

        self::assertSame(5, $page->words);
        self::assertSame([], $page->h1);
        self::assertNull($page->lang);
        self::assertFalse($page->viewport);
    }

    public function testDetectsSkippedHeadingsMixedContentAndBase(): void
    {
        $page = (new HtmlAnalyzer())->analyze(
            '<html><head><base href="https://site.test/dossier/"></head><body><h1>T</h1><h3>Sauté</h3><img src="http://site.test/i.png" alt=""><a href="page">P</a></body></html>',
            'https://site.test/',
            new SiteScope('https://site.test/'),
        );

        self::assertTrue($page->headingSkip());
        self::assertSame(1, $page->mixedContent);
        self::assertSame('', $page->images[0]->alt, 'Un alt vide signale une image décorative : il est présent.');
        self::assertSame(['https://site.test/dossier/page'], $page->links);
    }

    public function testHtmlInsideJavascriptStringsIsNotRead(): void
    {
        $page = (new HtmlAnalyzer())->analyze(
            <<<'HTML'
                <html><body>
                <script>lien = function (href) { return '<span class=\x22x\x22>' + href + '</span><a href=\x22' + href + '\x22>Voir</a>'; };</script>
                <script type="application/ld+json">{"@type": "Product", "description": "<p>texte</p>"}</script>
                <a href="/vrai">Vrai lien</a>
                </body></html>
                HTML,
            'https://site.test/dossier/page',
            new SiteScope('https://site.test/'),
        );

        self::assertSame(['https://site.test/vrai'], $page->links);
        self::assertSame(['Product'], $page->structuredData);
        self::assertSame(2, $page->words, 'Le code des scripts n\'est pas du texte.');
    }

    public function testFindsRenderBlockingResources(): void
    {
        $page = (new HtmlAnalyzer())->analyze(
            <<<'HTML'
                <html><head>
                <link rel="stylesheet" href="/a.css"><link rel="Stylesheet" href="/b.css"><link rel="stylesheet" href="/print.css" media="print">
                <script src="/bloque.js"></script><script src="/defer.js" defer></script><script src="/async.js" async></script>
                <script type="module" src="/module.js"></script><script>var enLigne = 1;</script>
                </head><body><img src="/hero.jpg" loading="lazy"><script src="/pied.js"></script></body></html>
                HTML,
            'https://site.test/',
            new SiteScope('https://site.test/'),
        );

        self::assertSame(['https://site.test/bloque.js'], $page->blockingScripts, 'Seuls les scripts externes du <head> sans defer, async ni module bloquent.');
        self::assertSame(2, $page->blockingStyles, 'La feuille media="print" ne bloque pas l\'affichage.');
        self::assertTrue($page->images[0]->lazy);
    }

    public function testReadsCacheLifetime(): void
    {
        self::assertSame(31_536_000, ResourceMeasure::cacheTtl(['cache-control' => ['public, max-age=31536000, immutable']]));
        self::assertSame(0, ResourceMeasure::cacheTtl(['cache-control' => ['no-cache, max-age=600']]));
        self::assertSame(3_600, ResourceMeasure::cacheTtl([
            'date' => ['Wed, 28 Oct 2026 10:00:00 GMT'],
            'expires' => ['Wed, 28 Oct 2026 11:00:00 GMT'],
        ]));
        self::assertSame(0, ResourceMeasure::cacheTtl([]));
    }

    public function testReadsUnicodeRanges(): void
    {
        self::assertSame([[0, 255], [0x131, 0x131], [0x400, 0x4FF]], ResourceMeter::unicodeRanges('U+0000-00FF, U+0131, U+04??'));
        self::assertSame([], ResourceMeter::unicodeRanges('n\'importe quoi'));
    }

    public function testPicksFontsLikeABrowser(): void
    {
        // Comme Google Fonts : latin étendu, puis latin, déclarés dans cet ordre pour la même police.
        $css = new ResourceMeasure('https://site.test/s.css', ResourceType::Css, 0, 0, 200, fontFaces: [
            ['url' => 'https://site.test/latin-ext.woff2', 'key' => 'a|400|normal|', 'ranges' => [[0x100, 0x24F]]],
            ['url' => 'https://site.test/latin.woff2', 'key' => 'a|400|normal|', 'ranges' => [[0, 0xFF], [0x152, 0x153]]],
            ['url' => 'https://site.test/gras.woff2', 'key' => 'a|700|normal|', 'ranges' => []],
        ]);

        self::assertSame(
            ['https://site.test/latin.woff2' => true, 'https://site.test/gras.woff2' => true],
            $css->usedFonts([0x41, 0x153]),
            '« œ » est couvert par les deux : la dernière déclarée (latin) suffit. Sans plage, la police sert à tout.',
        );
        self::assertEqualsCanonicalizing(
            ['https://site.test/latin-ext.woff2', 'https://site.test/latin.woff2', 'https://site.test/gras.woff2'],
            array_keys($css->usedFonts([0x41, 0x142])),
            '« ł » n\'existe qu\'en latin étendu.',
        );
    }

    public function testReadsLatin1Pages(): void
    {
        $html = mb_convert_encoding('<html><head><title>Café crème</title></head><body></body></html>', 'ISO-8859-1', 'UTF-8');
        $page = (new HtmlAnalyzer())->analyze($html, 'https://site.test/', new SiteScope('https://site.test/'), 'text/html; charset=ISO-8859-1');

        self::assertSame('Café crème', $page->title);
    }
}
