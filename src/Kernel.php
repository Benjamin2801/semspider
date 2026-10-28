<?php

namespace App;

use App\Cache\StaleBuildCaches;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    // Le cache ne peut pas être vidé sur le serveur : chaque build a donc son propre dossier.
    public function getCacheDir(): string
    {
        $buildId = $_SERVER['APP_BUILD_ID'] ?? $_ENV['APP_BUILD_ID'] ?? '';

        if (!\is_string($buildId) || !preg_match('/^[A-Za-z0-9_-]+$/', $buildId)) {
            return parent::getCacheDir();
        }

        return $this->getProjectDir().'/var/cache/'.$this->environment.'-'.$buildId;
    }

    public function boot(): void
    {
        parent::boot();

        if (str_starts_with(basename($this->getCacheDir()), $this->environment.'-')) {
            StaleBuildCaches::remove($this->getCacheDir(), $this->environment);
        }
    }
}
