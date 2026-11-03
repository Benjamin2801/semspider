<?php

namespace App\Tests\Crawl;

use App\Crawl\HtmlAnalyzer;
use App\Crawl\PageCrawler;
use App\Crawl\ResourceMeter;
use App\Crawl\RobotsTxt;
use App\Crawl\SiteScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Yaml\Yaml;

// En production, toutes les requêtes du crawl passent par NoPrivateNetworkHttpClient (config/services.yaml) :
// une adresse saisie ne doit jamais atteindre le serveur lui-même ni son réseau interne.
final class NetworkGuardTest extends TestCase
{
    /** @return iterable<array{string}> */
    public static function privateAddresses(): iterable
    {
        yield ['http://127.0.0.1/'];
        yield ['http://10.0.0.5/admin'];
        yield ['http://192.168.1.1/'];
        yield ['http://169.254.169.254/latest/meta-data/'];
        yield ['http://[::1]/'];
    }

    #[DataProvider('privateAddresses')]
    public function testPrivateAddressesAreRefused(string $url): void
    {
        $client = new NoPrivateNetworkHttpClient(new MockHttpClient(new MockResponse('secret')));

        $this->expectException(TransportException::class);
        $client->request('GET', $url)->getContent();
    }

    // NoPrivateNetworkHttpClient suit lui-même les redirections : le crawler doit les lui interdire à chaque requête,
    // sinon une page redirigée apparaît en 200 en production.
    public function testRedirectsAreReportedThroughTheGuard(): void
    {
        $client = new NoPrivateNetworkHttpClient(new MockHttpClient(static fn (string $method, string $url): MockResponse => match ($url) {
            // Comme curl, la réponse annonce l'adresse de destination (redirect_url).
            'http://8.8.8.8/ancienne' => new MockResponse('', ['http_code' => 301, 'response_headers' => ['location: /nouvelle'], 'redirect_url' => 'http://8.8.8.8/nouvelle']),
            default => new MockResponse('<html><body><h1>Nouvelle</h1></body></html>', ['response_headers' => ['content-type: text/html']]),
        }));
        $crawler = new PageCrawler($client, new HtmlAnalyzer(), new ResourceMeter($client, new ArrayAdapter()), 150);

        $reports = $crawler->crawl(['http://8.8.8.8/ancienne'], new SiteScope('http://8.8.8.8/'), RobotsTxt::allowAll(), microtime(true) + 10);

        self::assertSame(301, $reports[0]->status);
        self::assertSame('http://8.8.8.8/nouvelle', $reports[0]->redirect);
    }

    public function testTheCrawlClientIsGuardedOutsideTests(): void
    {
        $services = Yaml::parseFile(\dirname(__DIR__, 2).'/config/services.yaml')['services'];

        self::assertSame(NoPrivateNetworkHttpClient::class, $services['app.crawl.http_client']['class']);
        self::assertSame('@app.crawl.http_client', $services['Symfony\Contracts\HttpClient\HttpClientInterface $siteClient']);
    }
}
