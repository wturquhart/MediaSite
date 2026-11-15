 <?php
declare(strict_types=1);

namespace VideoScraper;

final class Scraper
{
    private const YTDLP_HOSTS = [
        'youtube.com', 'youtu.be', 'vimeo.com', 'dailymotion.com', 'dai.ly',
        'twitch.tv', 'tiktok.com', 'twitter.com', 'x.com', 'facebook.com',
        'fb.watch', 'instagram.com', 'reddit.com', 'redd.it', 'streamable.com',
        'bitchute.com', 'rumble.com', 'odysee.com', 'bilibili.com',
    ];

    public function __construct(
        private Http $http,
        private Extractor $extractor,
        private Downloader $downloader,
        private int $maxDepth = 2,
    ) {}

    public function scrape(string $pageUrl, ?string $slug = null): array
    {
        $slug  = $slug ?: $this->slug($pageUrl);
        $found = $this->crawl($pageUrl, 0, []);

        if (!$found['media']) {
            // Nothing native — let yt-dlp try the page itself
            if ($result = $this->downloader->fetchWithYtdlp($pageUrl, $slug, Url::base($pageUrl))) {
                return $result + ['title' => $found['title'] ?? null, 'source' => $pageUrl];
            }
            throw new DownloadException('No media found on page or embeds');
        }

        $ranked = $this->rank($found['media'], $pageUrl);
        $errors = [];

        foreach ($ranked as $candidate) {
            try {
                $result = $this->downloader->fetch(
                    $candidate,
                    $slug,
                    $found['referer'] ?? Url::base($pageUrl),
                    $found['poster'] ?? null
                );
                return $result + [
                    'title'  => $found['title'] ?? null,
                    'source' => $pageUrl,
                    'via'    => $candidate['via'],
                ];
            } catch (\Throwable $e) {
                $errors[] = $candidate['url'] . ' → ' . $e->getMessage();
            }
        }

        if ($this->downloader->fetchWithYtdlp($pageUrl, $slug, Url::base($pageUrl))) {
            return $result + ['title' => $found['title'] ?? null, 'source' => $pageUrl];
        }

        throw new DownloadException("All candidates failed:\n" . implode("\n", $errors));
    }

    private function crawl(string $url, int $depth, array $visited): array
    {
        if (in_array($url, $visited, true) || $depth > $this->maxDepth) {
            return ['media' => []];
        }
        $visited[] = $url;

        $res = $this->http->get($url);

        if (str_starts_with($res['content_type'], 'video/')
            || preg_match('#\.(mp4|webm|mkv|m3u8|mpd)(\?|$)#i', $res['url'])) {
            $type = match (true) {
                str_contains($res['content_type'], 'mpegurl') => 'hls',
                str_contains($res['content_type'], 'dash')    => 'dash',
                default => 'file',
            };
            return ['media' => [['url' => $res['url'], 'type' => $type, 'via' => 'direct']]];
        }

        $extracted = $this->extractor->extract($res['body'], $res['url']);
        $media     = $extracted['media'];
        $title     = $this->title($res['body']);
        $poster    = $this->poster($res['body'], $res['url']);

        if ($depth < $this->maxDepth) {
            foreach (array_slice($extracted['embeds'], 0, 8) as $embed) {
                if ($this->isNoise($embed)) {
                    continue;
                }
                try {
                    $child = $this->crawl($embed, $depth + 1, $visited);
                    $media = array_merge($media, $child['media']);
                } catch (\Throwable) {
                    // ignore dead iframes
                }
            }
        }

        return [
            'media'   => $media,
            'title'   => $title,
            'poster'  => $poster,
            'referer' => $url,
        ];
    }

    private function rank(array $media, string $pageUrl): array
    {
        $host = parse_url($pageUrl, PHP_URL_HOST) ?: '';
        $score = function (array $c) use ($host): int {
            $s = match ($c['type']) {
                'hls'   => 100,
                'file'  => 90,
                'dash'  => 80,
                default => 40,
            };
            if ($c['via'] === 'dom' || $c['via'] === 'meta') {
                $s += 15;
            }
            if (parse_url($c['url'], PHP_URL_HOST) === $host) {
                $s += 10;
            }
            if (preg_match('#(thumb|poster|preview|sprite|trailer)#i', $c['url'])) {
                $s -= 20;
            }
            return $s;
        };
        usort($media, fn($a, $b) => $score($b) <=> $score($a));
        return $media;
    }

    private function title(string $html): ?string
    {
        foreach ([
            '#<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)#i',
            '#<title[^>]*>([^<]+)#i',
        ] as $re) {
            if (preg_match($re, $html, $m)) {
                $t = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($t !== '') {
                    return mb_substr($t, 0, 200);
                }
            }
        }
        return null;
    }

    private function poster(string $html, string $base): ?string
    {
        if (preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            return Url::resolve($base, $m[1]);
        }
        if (preg_match('#<video[^>]+poster=["\']([^"\']+)#i', $html, $m)) {
            return Url::resolve($base, $m[1]);
        }
        return null;
    }

    private function isNoise(string $url): bool
    {
        return (bool) preg_match('#(doubleclick|googlesyndication|googletagmanager|facebook\.com/plugins|disqus|addthis|sharethis)#i', $url);
    }

    private function slug(string $url): string
    {
        $h = substr(hash('sha256', $url . microtime()), 0, 8);
        return date('Ymd') . '-' . $h;
    }

    public static function isPlatform(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::YTDLP_HOSTS as $d) {
            if ($host === $d || str_ends_with($host, '.' . $d)) {
                return true;
            }
        }
        return false;
    }
}