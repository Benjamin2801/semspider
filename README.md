# SemSpider

[![CI](https://github.com/Benjamin2801/semspider/actions/workflows/ci.yml/badge.svg)](https://github.com/Benjamin2801/semspider/actions/workflows/ci.yml)

Technical and SEO crawler for your own website. Type an address: SemSpider follows the internal links (and the
sitemap) up to 500 pages and fills a table with the weight, speed and SEO tags of every page.

Companion of [SemSeeker](https://github.com/Benjamin2801/semseeker), same stack and same constraints: shared hosting,
no database, no front-end build.

## What it measures

- **Performance**: transferred weight (compressed when the server compresses), TTFB, estimated load time, number of
  requests, and the share of images, HTML, CSS, JavaScript, fonts and other files. Stylesheets are read to count the
  fonts and imported stylesheets they declare.
- **SEO tags**: title, meta description, H1, H2 and H3 with their values and length, canonical, meta robots and
  `X-Robots-Tag`, `lang`, hreflang, Open Graph, JSON-LD types, word count.
- **Page issues**: missing or badly sized tags, several H1, skipped heading levels, thin content, images without `alt`,
  without dimensions or over 100 KB, heavy pages, slow TTFB, uncompressed HTML, broken resources, mixed content.
- **Site issues**, once every page is known: duplicate titles, descriptions and content, links to errors or redirects,
  click depth, inbound links, orphan sitemap pages, pages missing from the sitemap.

Results can be sorted, filtered by issue, opened page by page and exported as CSV.

## How a crawl runs

```
CrawlController::start      the home page (after redirects), robots.txt, sitemaps
public/js/app.js            the queue: batches of 4 pages, depth, inbound links, site-wide issues
└── CrawlController::pages  one batch, under 30 s (the host stops PHP after 165 s)
    └── PageCrawler             HTML of the 4 pages in parallel, then all their resources in parallel
        ├── HtmlAnalyzer        DOM: tags, text, links, resources
        ├── ResourceMeter       images, CSS, JS, fonts; each file measured once an hour (shared stylesheets)
        └── PageAudit           page issues against public thresholds (web.dev, Lighthouse, Google snippets)
```

The browser drives the crawl, so no request comes near the hosting time limit and nothing has to be stored on the
server. Every request goes through Symfony's `NoPrivateNetworkHttpClient`: an address typed by a visitor can never
reach the server itself or its private network. robots.txt is followed.

The load time is an estimate: the HTML, then the slowest resource downloaded in parallel (fonts after their
stylesheet). JavaScript is not run, so content injected by scripts is not seen.

## Stack

- Symfony 7.4 LTS, PHP 8.2 — no database, no front-end build (hand-written CSS and JavaScript)
- Symfony HttpClient (curl, parallel requests) and PHP's DOM

## Quality

PHPStan level 8, PHP-CS-Fixer (Symfony rules) and PHPUnit against a fake website (`tests/Http/FakeSite.php`), with no
network, run by GitHub Actions on every push. Code comments are in French.

## Getting started

```bash
composer install
php -S 127.0.0.1:8000 -t public
```

Or `docker compose up`, then open http://127.0.0.1:8000.

```bash
composer check    # style, static analysis, tests
```

The page limit, batch size and resources per page are set in `config/services.yaml` (`app.crawl.*`).

## Deployment

`deploy/build.ps1` builds a production copy in `build/`. Each build gets its own Symfony cache directory
(`APP_BUILD_ID`), so a new version never reads the previous one's cache.

## License

[MIT](LICENSE)
