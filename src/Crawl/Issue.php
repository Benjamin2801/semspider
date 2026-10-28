<?php

namespace App\Crawl;

// Problèmes détectés. Ceux d'une page sont calculés ici (PageAudit) ; ceux qui demandent de connaître tout
// le site (doublons, pages orphelines, liens cassés, profondeur) le sont par le navigateur (public/js/app.js).
enum Issue: string
{
    case HttpError = 'http_error';
    case Redirect = 'redirect';
    case Blocked = 'blocked';
    case Noindex = 'noindex';
    case CanonicalElsewhere = 'canonical_elsewhere';
    case TitleMissing = 'title_missing';
    case TitleShort = 'title_short';
    case TitleLong = 'title_long';
    case MetaMissing = 'meta_missing';
    case MetaShort = 'meta_short';
    case MetaLong = 'meta_long';
    case H1Missing = 'h1_missing';
    case H1Multiple = 'h1_multiple';
    case HeadingSkip = 'heading_skip';
    case ThinContent = 'thin_content';
    case LangMissing = 'lang_missing';
    case ViewportMissing = 'viewport_missing';
    case ImageAltMissing = 'image_alt_missing';
    case ImageHeavy = 'image_heavy';
    case ImageDimensions = 'image_dimensions';
    case HeavyPage = 'heavy_page';
    case SlowTtfb = 'slow_ttfb';
    case SlowLoad = 'slow_load';
    case ManyRequests = 'many_requests';
    case NoCompression = 'no_compression';
    case BrokenResources = 'broken_resources';
    case MixedContent = 'mixed_content';
    case Incomplete = 'incomplete';
    // Calculés par le navigateur, une fois le site connu.
    case DuplicateTitle = 'duplicate_title';
    case DuplicateMeta = 'duplicate_meta';
    case DuplicateContent = 'duplicate_content';
    case BrokenLinks = 'broken_links';
    case RedirectLinks = 'redirect_links';
    case Orphan = 'orphan';
    case NotInSitemap = 'not_in_sitemap';
    case Deep = 'deep';

    public function severity(): string
    {
        return match ($this) {
            self::HttpError, self::TitleMissing, self::H1Missing, self::BrokenResources, self::MixedContent,
            self::BrokenLinks, self::DuplicateTitle, self::DuplicateContent => 'error',
            self::Redirect, self::Blocked, self::CanonicalElsewhere, self::MetaShort, self::HeadingSkip, self::LangMissing,
            self::ImageDimensions, self::ManyRequests, self::Incomplete, self::NotInSitemap, self::Deep => 'notice',
            default => 'warning',
        };
    }
}
