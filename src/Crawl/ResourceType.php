<?php

namespace App\Crawl;

// Les postes du poids d'une page.
enum ResourceType: string
{
    case Html = 'html';
    case Css = 'css';
    case Js = 'js';
    case Image = 'image';
    case Font = 'font';
    case Other = 'other';

    // Le type annoncé par le serveur l'emporte sur celui que laisse attendre la balise.
    public static function fromContentType(?string $contentType, self $expected): self
    {
        $mime = strtolower(trim(explode(';', (string) $contentType)[0]));

        return match (true) {
            '' === $mime, 'application/octet-stream' === $mime => $expected,
            str_starts_with($mime, 'image/') => self::Image,
            'text/css' === $mime => self::Css,
            str_contains($mime, 'javascript'), str_contains($mime, 'ecmascript') => self::Js,
            str_starts_with($mime, 'font/'), str_contains($mime, 'font') => self::Font,
            'text/html' === $mime, 'application/xhtml+xml' === $mime => self::Html,
            default => self::Other,
        };
    }
}
