<?php

namespace App\Tests\Crawl;

use App\Crawl\HtmlAnalyzer;
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

    public function testReadsLatin1Pages(): void
    {
        $html = mb_convert_encoding('<html><head><title>Café crème</title></head><body></body></html>', 'ISO-8859-1', 'UTF-8');
        $page = (new HtmlAnalyzer())->analyze($html, 'https://site.test/', new SiteScope('https://site.test/'), 'text/html; charset=ISO-8859-1');

        self::assertSame('Café crème', $page->title);
    }
}
