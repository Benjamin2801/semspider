<?php

namespace App\Crawl;

// Mesure d'une ressource (image, CSS, JS, police), gardée une heure : une feuille de style partagée par
// toutes les pages n'est téléchargée qu'une fois par crawl.
final class ResourceMeasure
{
    /**
     * @param array<string, ResourceType>                                          $children  polices et feuilles importées par une feuille de style
     * @param list<array{url: string, key: string, ranges: list<array{int, int}>}> $fontFaces
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
        // Type MIME annoncé (image/jpeg, image/webp…), pour repérer les formats d'image anciens.
        public readonly ?string $mime = null,
        // Durée de cache navigateur en secondes (Cache-Control max-age, sinon Expires) ; 0 si aucune.
        public readonly int $cacheTtl = 0,
        // Pour une feuille de style : ses @font-face dans l'ordre de déclaration.
        public readonly array $fontFaces = [],
    ) {
    }

    /**
     * Polices que le navigateur télécharge pour une page, d'après les caractères de son texte (CSS Fonts, § 4.5) :
     * pour chaque caractère, parmi les @font-face d'une même police (famille, graisse, style), le dernier déclaré
     * dont l'unicode-range le contient. Google Fonts déclare le latin en dernier : un « œ » ne fait pas charger
     * le latin étendu.
     *
     * @param list<int> $codePoints
     *
     * @return array<string, true> adresses des polices utilisées
     */
    public function usedFonts(array $codePoints): array
    {
        $groups = [];
        foreach ($this->fontFaces as $face) {
            $groups[$face['key']][] = $face;
        }

        $used = [];
        foreach ($groups as $faces) {
            $faces = array_reverse($faces);
            foreach ($codePoints as $codePoint) {
                foreach ($faces as $face) {
                    if (self::covers($face['ranges'], $codePoint)) {
                        $used[$face['url']] = true;
                        break;
                    }
                }
            }
        }

        return $used;
    }

    /** @param list<array{int, int}> $ranges sans plage, la police couvre tout */
    private static function covers(array $ranges, int $codePoint): bool
    {
        if ([] === $ranges) {
            return true;
        }
        foreach ($ranges as [$start, $end]) {
            if ($codePoint >= $start && $codePoint <= $end) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, list<string>> $headers en-têtes de la réponse, noms en minuscules */
    public static function cacheTtl(array $headers): int
    {
        $cacheControl = strtolower(implode(',', $headers['cache-control'] ?? []));
        if (str_contains($cacheControl, 'no-store') || str_contains($cacheControl, 'no-cache')) {
            return 0;
        }
        if (preg_match('/(?:^|[,\s])max-age=(\d+)/', $cacheControl, $match)) {
            return (int) $match[1];
        }
        $expires = strtotime($headers['expires'][0] ?? '');
        $date = strtotime($headers['date'][0] ?? '') ?: time();

        return false === $expires ? 0 : max(0, $expires - $date);
    }

    public function isBroken(): bool
    {
        return 0 === $this->status || $this->status >= 400;
    }
}
