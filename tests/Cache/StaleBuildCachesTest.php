<?php

namespace App\Tests\Cache;

use App\Cache\StaleBuildCaches;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class StaleBuildCachesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/semspider-cache-'.uniqid();
        foreach (['prod-20261001000000', 'prod-20261010000000', 'prod-20261017000000', 'dev'] as $dir) {
            mkdir($this->root.'/'.$dir, recursive: true);
            file_put_contents($this->root.'/'.$dir.'/fichier.php', '<?php');
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testRemovesTheCachesOfPreviousBuildsOnce(): void
    {
        $current = $this->root.'/prod-20261017000000';
        StaleBuildCaches::remove($current, 'prod');

        self::assertSame(['dev', 'prod-20261017000000'], array_values(array_diff(scandir($this->root) ?: [], ['.', '..'])), 'Le build courant et les autres environnements restent.');
        self::assertFileExists($current.'/fichier.php');

        mkdir($this->root.'/prod-20261020000000');
        StaleBuildCaches::remove($current, 'prod');
        self::assertDirectoryExists($this->root.'/prod-20261020000000');
    }
}
