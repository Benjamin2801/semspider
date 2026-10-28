<?php

namespace App\EventSubscriber;

use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

// Pas de tâche planifiée sur l'hébergement : on purge le cache de temps en temps, une fois la réponse envoyée.
final class CachePruneSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly PruneableInterface $cache,
        private readonly int $oneIn = 200,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'prune'];
    }

    public function prune(): void
    {
        if (1 === random_int(1, $this->oneIn)) {
            $this->cache->prune();
        }
    }
}
