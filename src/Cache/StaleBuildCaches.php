<?php

namespace App\Cache;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

// Supprime une seule fois les dossiers de cache laissés par les builds précédents.
final class StaleBuildCaches
{
    private const MARKER = '.stale-builds-removed';

    public static function remove(string $currentDir, string $environment): void
    {
        $marker = $currentDir.'/'.self::MARKER;
        if (is_file($marker) || !is_dir($currentDir)) {
            return;
        }

        $filesystem = new Filesystem();
        try {
            foreach (glob(\dirname($currentDir).'/'.$environment.'-*', \GLOB_ONLYDIR) ?: [] as $dir) {
                if (realpath($dir) !== realpath($currentDir)) {
                    $filesystem->remove($dir);
                }
            }
        } catch (IOExceptionInterface) {
        }
        try {
            $filesystem->dumpFile($marker, '');
        } catch (IOExceptionInterface) {
        }
    }
}
