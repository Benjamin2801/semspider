<?php

namespace App\Tests\Crawl;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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

    public function testTheCrawlClientIsGuardedOutsideTests(): void
    {
        $services = Yaml::parseFile(\dirname(__DIR__, 2).'/config/services.yaml')['services'];

        self::assertSame(NoPrivateNetworkHttpClient::class, $services['app.crawl.http_client']['class']);
        self::assertSame('@app.crawl.http_client', $services['Symfony\Contracts\HttpClient\HttpClientInterface $siteClient']);
    }
}
