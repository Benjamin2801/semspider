<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\CachePruneSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpKernel\KernelEvents;

final class CachePruneSubscriberTest extends TestCase
{
    public function testRemovesExpiredEntriesAfterTheResponse(): void
    {
        $directory = sys_get_temp_dir().'/semspider-prune-'.bin2hex(random_bytes(4));
        $cache = new FilesystemAdapter('test', 0, $directory);
        $cache->save($cache->getItem('expired')->set('x')->expiresAfter(1));
        $cache->save($cache->getItem('fresh')->set('y')->expiresAfter(3600));
        sleep(2);

        (new CachePruneSubscriber($cache, oneIn: 1))->prune();

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        self::assertCount(1, iterator_to_array($files), 'Seule l\'entrée encore valide reste sur le disque.');
        self::assertTrue($cache->hasItem('fresh'));
        self::assertArrayHasKey(KernelEvents::TERMINATE, CachePruneSubscriber::getSubscribedEvents());

        $cache->clear();
    }
}
