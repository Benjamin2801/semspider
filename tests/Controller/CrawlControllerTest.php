<?php

namespace App\Tests\Controller;

use App\Tests\Http\FakeSite;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CrawlControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $cache = static::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();
    }

    public function testHomeShowsTheForm(): void
    {
        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9,en;q=0.8']);
        self::assertResponseRedirects('/fr');

        $this->client->request('GET', '/fr');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lead', '500 pages');
        self::assertSelectorExists('form.crawl-form input[name="url"]');
        self::assertSelectorNotExists('.form-error');
    }

    public function testStartsTheCrawlInTheBrowser(): void
    {
        $crawler = $this->client->request('GET', '/fr/crawl', ['url' => 'site.test']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Crawl de www.site.test');
        self::assertSelectorTextContains('.crawl-meta', '3 pages dans le sitemap');
        self::assertSelectorExists('meta[name="robots"][content="noindex"]');

        $config = json_decode($crawler->filter('#crawl-config')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(FakeSite::ROOT, $config['root']);
        self::assertSame('/fr/crawl/pages', $config['endpoint']);
        self::assertSame(500, $config['maxPages']);
        self::assertSame(['label' => 'H1 absent', 'severity' => 'error'], $config['issues']['h1_missing']);
        self::assertSame('Poids', $config['i18n']['col.weight']);
    }

    public function testUnreachableSiteGoesBackToTheForm(): void
    {
        $this->client->request('GET', '/en/crawl', ['url' => 'https://down.test/']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error', 'HTTP code 503');
        self::assertInputValueSame('url', 'https://down.test/');
    }

    public function testEmptyAddressRedirectsHome(): void
    {
        $this->client->request('GET', '/fr/crawl', ['url' => '  ']);

        self::assertResponseRedirects('/fr');
    }

    public function testAnalysesABatchOfPages(): void
    {
        $this->client->jsonRequest('POST', '/fr/crawl/pages', ['root' => FakeSite::ROOT, 'urls' => [FakeSite::ROOT, 'https://site.test/b']]);

        self::assertResponseIsSuccessful();
        $pages = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['pages'];
        self::assertCount(2, $pages);

        [$home, $missing] = $pages;
        self::assertSame(FakeSite::ROOT, $home['url']);
        self::assertTrue($home['html']);
        self::assertSame(['Bienvenue sur le site'], $home['h1']);
        self::assertSame(['Nos services', 'Contact'], $home['h2']);
        self::assertSame(\strlen(FakeSite::HOME), $home['bytes']['html']);
        self::assertContains('image_heavy', $home['issues']);
        self::assertContains('https://www.site.test/a', $home['links']);
        self::assertSame(['https://www.site.test/big.jpg'], $home['imagesWithoutAlt']);

        self::assertSame(404, $missing['status']);
        self::assertSame(['http_error'], $missing['issues']);
    }

    public function testRefusesPagesOutsideTheSite(): void
    {
        $this->client->jsonRequest('POST', '/fr/crawl/pages', ['root' => FakeSite::ROOT, 'urls' => ['https://ailleurs.test/']]);
        self::assertResponseStatusCodeSame(400);

        $this->client->jsonRequest('POST', '/fr/crawl/pages', ['root' => FakeSite::ROOT, 'urls' => array_fill(0, 5, FakeSite::ROOT)]);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', '/fr/crawl/pages', content: 'pas du json');
        self::assertResponseStatusCodeSame(400);
    }
}
