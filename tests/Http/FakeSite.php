<?php

namespace App\Tests\Http;

use Symfony\Component\HttpClient\Response\MockResponse;

// Un petit site complet servi sans réseau : accueil, page sans balises, 404, redirection, PDF, ressources.
final class FakeSite
{
    public const ROOT = 'https://www.site.test/';

    public const HOME = <<<'HTML'
        <!DOCTYPE html>
        <html lang="fr">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width">
            <title>Accueil du site de test : bienvenue et présentation</title>
            <meta name="description" content="Une description assez longue pour passer le seuil des soixante-dix caractères, sans dépasser la limite.">
            <link rel="canonical" href="/">
            <link rel="stylesheet" href="/style.css">
            <link rel="icon" href="/favicon.ico">
            <script src="/app.js" defer></script>
            <script type="application/ld+json">{"@context": "https://schema.org", "@graph": [{"@type": "Organization"}, {"@type": "WebSite"}]}</script>
            <meta property="og:title" content="Accueil">
        </head>
        <body>
            <h1>Bienvenue  sur le   site</h1>
            <h2>Nos services</h2>
            <h3>Conseil</h3>
            <h2>Contact</h2>
            <p>Un texte court, l'accueil est mince.</p>
            <script>var ignore = "ces mots ne comptent pas";</script>
            <img src="/big.jpg">
            <img src="logo.png" alt="Logo" width="80" height="40" loading="lazy">
            <a href="/a">A</a> <a href="/a#ancre">A encore</a> <a href="/b">B</a> <a href="/old">Ancienne</a>
            <a href="/private/x">Privé</a> <a href="/doc.pdf">PDF</a> <a href="https://site.test/a">Sans www</a>
            <a href="https://ailleurs.test/">Ailleurs</a> <a href="mailto:a@site.test">Mail</a>
        </body>
        </html>
        HTML;

    /** @var list<string> */
    public array $requestedUrls = [];

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        $this->requestedUrls[] = $url;
        $html = ['content-type: text/html; charset=utf-8'];

        return match ($url) {
            'https://site.test/' => self::redirect(self::ROOT),
            'https://www.site.test/robots.txt' => new MockResponse("User-agent: *\nDisallow: /private/\n\nSitemap: https://www.site.test/sitemap.xml\n"),
            'https://www.site.test/sitemap.xml' => self::ok(<<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                    <url><loc>https://www.site.test/</loc></url>
                    <url><loc>https://www.site.test/a</loc></url>
                    <url><loc>https://www.site.test/orphan</loc></url>
                    <url><loc>https://ailleurs.test/x</loc></url>
                </urlset>
                XML, ['content-type: application/xml']),
            self::ROOT => self::ok(self::HOME, [...$html, 'content-encoding: gzip']),
            'https://www.site.test/a' => self::ok('<html><body><h1>A</h1><h1>Bis</h1><h3>Saut</h3></body></html>', $html),
            'https://www.site.test/b' => new MockResponse('Introuvable', ['http_code' => 404, 'response_headers' => $html]),
            'https://www.site.test/old' => self::redirect('/a'),
            'https://www.site.test/doc.pdf' => self::ok('%PDF-1.4', ['content-type: application/pdf']),
            'https://www.site.test/style.css' => self::ok('@import "theme.css"; @font-face { src: url("/fonts/a.woff2") format("woff2"); } body { background: url(fond.png); }', ['content-type: text/css']),
            'https://www.site.test/theme.css' => self::ok('h1 { color: red; }', ['content-type: text/css']),
            'https://www.site.test/fonts/a.woff2' => self::ok(str_repeat('f', 20_000), ['content-type: font/woff2']),
            'https://www.site.test/app.js' => self::ok(str_repeat('j', 30_000), ['content-type: application/javascript']),
            'https://www.site.test/big.jpg' => self::ok(str_repeat('i', 150_000), ['content-type: image/jpeg']),
            'https://www.site.test/logo.png' => self::ok(str_repeat('l', 5_000), ['content-type: image/png']),
            'https://down.test/' => new MockResponse('', ['http_code' => 503]),
            default => new MockResponse('Introuvable', ['http_code' => 404]),
        };
    }

    /** @param list<string> $headers */
    private static function ok(string $body, array $headers): MockResponse
    {
        return new MockResponse($body, ['response_headers' => $headers, 'size_download' => \strlen($body)]);
    }

    private static function redirect(string $location): MockResponse
    {
        return new MockResponse('', ['http_code' => 301, 'response_headers' => ['location: '.$location]]);
    }
}
