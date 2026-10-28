<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LegalControllerTest extends WebTestCase
{
    public function testShowsPublisherAndHost(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/legal');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mentions légales');
        self::assertSelectorTextContains('.prose', 'Éditeur Test');
        self::assertSelectorTextContains('.prose', 'OVH SAS');
        self::assertSelectorExists('.site-footer a[href="/fr/legal"]');

        $client->request('GET', '/en/legal');
        self::assertSelectorTextContains('h1', 'Legal notice');
    }
}
