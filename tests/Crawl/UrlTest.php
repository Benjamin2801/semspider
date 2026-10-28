<?php

namespace App\Tests\Crawl;

use App\Crawl\SiteScope;
use App\Crawl\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    /** @return iterable<array{string, ?string}> */
    public static function normalized(): iterable
    {
        yield 'hôte en minuscules, chemin vide' => ['HTTPS://WWW.Site.TEST', 'https://www.site.test/'];
        yield 'port par défaut retiré' => ['http://site.test:80/a', 'http://site.test/a'];
        yield 'autre port gardé' => ['https://site.test:8443/a', 'https://site.test:8443/a'];
        yield 'fragment retiré' => ['https://site.test/a?x=1#haut', 'https://site.test/a?x=1'];
        yield 'points résolus' => ['https://site.test/a/./b/../c', 'https://site.test/a/c'];
        yield 'remontée au-delà de la racine' => ['https://site.test/../../a', 'https://site.test/a'];
        yield 'espace encodé' => ['https://site.test/mon fichier.pdf', 'https://site.test/mon%20fichier.pdf'];
        yield 'protocole refusé' => ['ftp://site.test/a', null];
        yield 'sans hôte' => ['/a', null];
    }

    #[DataProvider('normalized')]
    public function testNormalize(string $url, ?string $expected): void
    {
        self::assertSame($expected, Url::normalize($url));
    }

    /** @return iterable<array{string, ?string}> */
    public static function resolved(): iterable
    {
        yield 'absolu' => ['https://ailleurs.test/x', 'https://ailleurs.test/x'];
        yield 'depuis la racine' => ['/x', 'https://site.test/x'];
        yield 'relatif au dossier' => ['c.html', 'https://site.test/a/c.html'];
        yield 'dossier parent' => ['../c', 'https://site.test/c'];
        yield 'sans protocole' => ['//cdn.test/s.js', 'https://cdn.test/s.js'];
        yield 'paramètres seuls' => ['?page=2', 'https://site.test/a/b?page=2'];
        yield 'ancre' => ['#haut', null];
        yield 'vide' => ['  ', null];
        yield 'mail' => ['mailto:a@site.test', null];
        yield 'javascript' => ['javascript:void(0)', null];
        yield 'image en ligne' => ['data:image/png;base64,AAA', null];
    }

    #[DataProvider('resolved')]
    public function testResolve(string $href, ?string $expected): void
    {
        self::assertSame($expected, Url::resolve('https://site.test/a/b?x=1', $href));
    }

    public function testScopeIgnoresWwwAndScheme(): void
    {
        $scope = new SiteScope('https://www.site.test/');

        self::assertTrue($scope->contains('http://site.test/a'));
        self::assertTrue($scope->contains('https://www.site.test/b'));
        self::assertFalse($scope->contains('https://blog.site.test/'));
        self::assertFalse($scope->contains('https://site.test:8080/'));
        self::assertFalse($scope->contains('https://site.test.ailleurs.test/'));
    }
}
