<?php

namespace App\Services;

use App\Models\Page;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;

class CrawlService
{
    /* ==========================================================
     | PUBLIC API
     ========================================================== */

    public function prepareQueue(Site $site): array
    {
        $queue = [];
        $visited = [];

        $baseUrl  = rtrim($site->url, '/') . '/';
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);

        if (!empty($site->include_pages)) {
            foreach ($site->include_pages as $path) {
                $queue[] = [
                    'url'   => $this->resolveUrl($path, $baseUrl),
                    'depth' => 0,
                ];
            }
        } else {
            $queue[] = ['url' => $baseUrl, 'depth' => 0];
        }

        $allUrls = [];

        while ($queue) {
            $current = array_shift($queue);
            $url     = $this->normalizeUrl($current['url']);
            $depth   = $current['depth'];

            if (!$url || $depth > $site->crawl_depth) continue;
            if (in_array($url, $visited, true)) continue;

            if ($this->isExcluded($url, $site)) continue;

            $visited[] = $url;
            $allUrls[] = ['url' => $url, 'depth' => $depth];

            foreach ($this->extractInternalLinks($url, $baseHost, $site) as $link) {
                if (!in_array($link, $visited, true)) {
                    $queue[] = ['url' => $link, 'depth' => $depth + 1];
                }
            }
        }

        return $allUrls;
    }

    public function crawlSinglePage(
        Site $site,
        string $url,
        int $depth,
        ?string $crawlJobId = null
    ): ?Page {

        // 🔒 RÈGLE ABSOLUE : jamais scrapper une page exclue
        if ($this->isExcluded($url, $site)) {
            Log::info("URL exclue ignorée (no scrape): {$url}", [
                'site_id' => $site->id,
            ]);
            return null;
        }

        try {
            $client = new HttpBrowser(HttpClient::create([
                'timeout' => 60,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; CrawlBot/1.0)',
                ],
            ]));

            $client->request('GET', $url);
            $crawler = $client->getCrawler();

            $title = $crawler->filter('title')->count()
                ? trim($crawler->filter('title')->text())
                : '';

            $main = $this->extractBestContent($crawler);
            if (!$main) return null;

            $this->cleanDom($main);

            $sections = $this->extractStructuredSections($main);

            if (empty($sections)) {
                $sections = $this->extractLooseSections($main);
            }

            if (empty($sections)) return null;

            return Page::create([
                'id'           => (string) Str::uuid(),
                'site_id'      => $site->id,
                'crawl_job_id' => $crawlJobId,
                'url'          => $url,
                'title'        => $title,
                'content'      => json_encode($sections, JSON_UNESCAPED_UNICODE),
                'source'       => 'crawl',
            ]);

        } catch (\Throwable $e) {
            Log::error("Crawl error {$url}", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /* ==========================================================
     | CONTENT EXTRACTION
     ========================================================== */

    private function extractBestContent(Crawler $crawler): ?Crawler
    {
        foreach (['main', 'article', '[role="main"]'] as $selector) {
            if ($crawler->filter($selector)->count()) {
                return $crawler->filter($selector);
            }
        }

        // Heuristique densité de texte
        $bestNode  = null;
        $bestScore = 0;

        $crawler->filter('div, section')->each(function (Crawler $node) use (&$bestNode, &$bestScore) {
            $textLength = mb_strlen(trim($node->text()));
            $linkCount  = $node->filter('a')->count();

            if ($textLength < 300) return;
            if ($linkCount > ($textLength / 100)) return;

            $score = $textLength - ($linkCount * 50);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestNode  = $node;
            }
        });

        return $bestNode ?: ($crawler->filter('body')->count() ? $crawler->filter('body') : null);
    }

    private function cleanDom(Crawler $crawler): void
    {
        $crawler->filter(
            'script,style,nav,footer,header,aside,form,iframe,button,svg'
        )->each(fn ($n) =>
        $n->getNode(0)?->parentNode?->removeChild($n->getNode(0))
        );
    }

    /* ==========================================================
     | SECTION EXTRACTION
     ========================================================== */

    private function extractStructuredSections(Crawler $content): array
    {
        $sections = [];
        $currentTitle = null;
        $buffer = [];

        $content->filter('h1,h2,h3,p,li')->each(function (Crawler $node) use (&$sections, &$currentTitle, &$buffer) {
            $tag = strtolower($node->nodeName());

            if (in_array($tag, ['h1', 'h2', 'h3'])) {
                if ($currentTitle && $buffer) {
                    $sections[] = [
                        'title'   => $currentTitle,
                        'content' => implode("\n", $buffer),
                    ];
                }
                $currentTitle = trim($node->text());
                $buffer = [];
                return;
            }

            $text = trim($node->text());
            if (mb_strlen($text) > 40) {
                $buffer[] = $text;
            }
        });

        if ($currentTitle && $buffer) {
            $sections[] = [
                'title'   => $currentTitle,
                'content' => implode("\n", $buffer),
            ];
        }

        return $sections;
    }

    private function extractLooseSections(Crawler $content): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $content->text()));
        if (mb_strlen($text) < 300) return [];

        $sentences = preg_split('/(?<=[.!?])\s+/', $text);
        $sections = [];
        $buffer = '';

        foreach ($sentences as $sentence) {
            if (mb_strlen($buffer) < 800) {
                $buffer .= ' ' . $sentence;
            } else {
                $sections[] = [
                    'title'   => null,
                    'content' => trim($buffer),
                ];
                $buffer = $sentence;
            }
        }

        if (mb_strlen($buffer) > 200) {
            $sections[] = [
                'title'   => null,
                'content' => trim($buffer),
            ];
        }

        return $sections;
    }

    /* ==========================================================
     | LINK EXTRACTION
     ========================================================== */

    private function extractInternalLinks(string $url, string $baseHost, Site $site): array
    {
        $links = [];

        try {
            $client = new HttpBrowser(HttpClient::create(['timeout' => 30]));
            $client->request('GET', $url);
            $crawler = $client->getCrawler();

            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links, $baseHost, $site) {
                $href = trim($node->attr('href'));
                if (!$href || preg_match('/^(#|mailto|tel|javascript|data):/i', $href)) return;

                $abs = $this->resolveUrl($href, rtrim($site->url, '/') . '/');
                if (!$abs) return;

                if (parse_url($abs, PHP_URL_HOST) !== $baseHost) return;

                $norm = $this->normalizeUrl($abs);
                if ($this->isExcluded($norm, $site)) return;

                if (!in_array($norm, $links, true)) {
                    $links[] = $norm;
                }
            });
        } catch (\Throwable $e) {
            Log::warning("Link extraction failed {$url}");
        }

        return $links;
    }

    /* ==========================================================
     | HELPERS
     ========================================================== */

    public function isExcluded(string $url, Site $site): bool
    {
        foreach ($site->exclude_pages ?? [] as $pattern) {
            if ($this->urlMatchesPattern($url, $pattern)) return true;
        }
        return false;
    }

    private function normalizeUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) return null;

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host   = strtolower($parts['host']);
        $path   = $this->normalizePath($parts['path'] ?? '/');

        return $scheme . '://' . $host . $path;
    }

    private function resolveUrl(string $relative, string $base): ?string
    {
        if (parse_url($relative, PHP_URL_SCHEME)) return $relative;
        if (str_starts_with($relative, '/')) {
            $p = parse_url($base);
            return "{$p['scheme']}://{$p['host']}{$relative}";
        }
        return rtrim($base, '/') . '/' . ltrim($relative, '/');
    }

    private function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $p) {
            if ($p === '' || $p === '.') continue;
            if ($p === '..') array_pop($parts);
            else $parts[] = $p;
        }
        return '/' . implode('/', $parts);
    }

    private function urlMatchesPattern(string $url, string $pattern): bool
    {
        $pattern = rtrim($pattern, '/');
        $url     = rtrim($url, '/');

        if (str_contains($pattern, '*')) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
            return (bool) preg_match($regex, $url);
        }

        return $url === $pattern || str_starts_with($url . '/', $pattern . '/');
    }
}
