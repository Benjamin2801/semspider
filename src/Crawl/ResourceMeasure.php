<?php

namespace App\Crawl;

// Mesure d'une ressource (image, CSS, JS, police), gardée une heure : une feuille de style partagée par
// toutes les pages n'est téléchargée qu'une fois par crawl.
final class ResourceMeasure
{
    /**
     * @param array<string, ResourceType> $children polices et feuilles importées par une feuille de style
     */
    public function __construct(
        public readonly string $url,
        public readonly ResourceType $type,
        // Octets transférés (compressés si le serveur compresse) ; 0 si la ressource n'a pas pu être lue.
        public readonly int $bytes,
        // Attente de la réponse et téléchargement, sans la file d'attente ni l'ouverture de la connexion.
        public readonly int $ms,
        // Code HTTP ; 0 si le serveur n'a pas répondu.
        public readonly int $status,
        public readonly array $children = [],
    ) {
    }

    public function isBroken(): bool
    {
        return 0 === $this->status || $this->status >= 400;
    }
}
