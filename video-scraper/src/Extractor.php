 <?php
declare(strict_types=1);

namespace VideoScraper;

final class Extractor
{
    /** @return array{media: array<int,array>, embeds: string[]} */
    public function extract(string $html, string $pageUrl): array
    {
        $media  = [];
        $embeds = [];
        $base   = $this->documentBase($html, $pageUrl);

        // 1. Inline player configs / JS string literals first — they're the
        //    highest-signal source on most embed pages.
        foreach ($this->fromScripts($html, $base) as $m) {
            $media[] = $m;
        }

        // 2. DOM pass
        $dom = $this->loadDom($html);
        if ($dom) {
            $xp = new \DOMXPath($dom);

            foreach ($xp->query('//video[@src] | //video/source[@src] | //audio[@src]') as $n) {
                $media[] = $this->candidate((string) $n->getAttribute('src'), $base, 'dom');
            }

            foreach ($xp->query('//iframe[@src] | //embed[@src] | //object[@data]') as $n) {
                $src = (string) ($n->getAttribute('src') ?: $n->getAttribute('data'));
                if ($src !== '') {
                    $embeds[] = Url::resolve($base, $src);
                }
            }

            // metadata
            foreach ([
                '//meta[@property="og:video:secure_url"]/@content',
                '//meta[@property="og:video:url"]/@content',
                '//meta[@property="og:video"]/@content',
                '//meta[@name="twitter:player:stream"]/@content',
                '//meta[@itemprop="contentUrl"]/@content',
            ] as $q) {
                foreach ($xp->query($q) as $a) {
                    $media[] = $this->candidate((string) $a->nodeValue, $base, 'meta');
                }
            }

            foreach ($xp->query('//link[@rel="video_src"]/@href') as $a) {
                $media[] = $this->candidate((string) $a->nodeValue, $base, 'link');
            }

            // JSON-LD
            foreach ($xp->query('//script[@type="application/ld+json"]') as $s) {
                $json = json_decode((string) $s->textContent, true);
                foreach ($this->walkJson($json) as $val) {
                    if (is_string($val) && $this->looksLikeMedia($val)) {
                        $media[] = $this->candidate($val, $base, 'ldjson');
                    }
                }
            }
        }

        // 3. Bare URL sweep as a last resort
        foreach ($this->bareUrls($html, $base) as $m) {
            $media[] = $m;
        }

        return ['media' => $this->dedupe($media), 'embeds' => array_values(array_unique($embeds))];
    }

    // --- internals --------------------------------------------------------

    private function documentBase(string $html, string $pageUrl): string
    {
        if (preg_match('#<base\s+href=["\']([^"\']+)#i', $html, $m)) {
            return Url::resolve($pageUrl, $m[1]);
        }
        return Url::base($pageUrl);
    }

    private function loadDom(string $html): ?\DOMDocument
    {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? $dom : null;
    }

    private function fromScripts(string $html, string $base): array
    {
        $out = [];

        // JSON-ish keys used by JW Player, Video.js, Plyr, custom players
        $patterns = [
            '/["\']file["\']\s*:\s*["\']([^"\']+)["\']/i',
            '/["\']src["\']\s*:\s*["\']([^"\']+\.(?:m3u8|mpd|mp4|webm|mkv)(?:\?[^"\']*)?)["\']/i',
            '/["\']hls["\']\s*:\s*["\']([^"\']+)["\']/i',
            '/["\'](?:hls|dash|mp4|video_?url|stream_?url|playlist)["\']\s*:\s*["\']([^"\']+)["\']/i',
            '/sources\s*:\s*\[\s*\{[^}]*["\']file["\']\s*:\s*["\']([^"\']+)["\']/is',
        ];
        foreach ($patterns as $re) {
            if (preg_match_all($re, $html, $m)) {
                foreach ($m[1] as $u) {
                    $out[] = $this->candidate($u, $base, 'script');
                }
            }
        }

        // raw escaped m3u8 / mp4 in any JS
        if (preg_match_all('#https?:(?:\\\\?/){2}[^\s"\'<>\\\\]+?\.(?:m3u8|mpd|mp4)(?:\?[^\s"\'<>\\\\]*)?#i', $html, $m)) {
            foreach ($m[0] as $u) {
                $out[] = $this->candidate($u, $base, 'script-raw');
            }
        }
        return $out;
    }

    private function bareUrls(string $html, string $base): array
    {
        $out = [];
        if (preg_match_all('#https?://[^\s"\'<>()\\\\]+?\.(?:m3u8|mpd|mp4|webm)(?:\?[^\s"\'<>()\\\\]*)?#i', $html, $m)) {
            foreach ($m[0] as $u) {
                $out[] = $this->candidate($u, $base, 'sweep');
            }
        }
        return $out;
    }

    private function walkJson($node): \Generator
    {
        if (is_array($node)) {
            foreach ($node as $v) {
                yield from $this->walkJson($v);
            }
        } elseif (is_string($node)) {
            yield $node;
        }
    }

    private function looksLikeMedia(string $v): bool
    {
        return (bool) preg_match('#\.(m3u8|mpd|mp4|webm|mkv)(\?|$)#i', $v)
            || str_contains($v, '/manifest');
    }

    private function candidate(string $raw, string $base, string $via): array
    {
        $raw = trim($raw);
        // JS escaping
        $raw = str_replace(['\\/', '\\u0026', '\\u003d', '\\u003f'], ['/', '&', '=', '?'], $raw);
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($raw === '' || str_starts_with($raw, 'data:') || str_starts_with($raw, 'blob:')) {
            return ['url' => $raw, 'type' => 'skip', 'via' => $via];
        }

        $url = Url::resolve($base, $raw);
        $type = match (true) {
            (bool) preg_match('#\.m3u8(\?|$)#i', $url)   => 'hls',
            (bool) preg_match('#\.mpd(\?|$)#i', $url)    => 'dash',
            (bool) preg_match('#\.(mp4|webm|mkv|mov)(\?|$)#i', $url) => 'file',
            default => 'unknown',
        };
        return ['url' => $url, 'type' => $type, 'via' => $via];
    }

    private function dedupe(array $items): array
    {
        $seen = [];
        $out  = [];
        foreach ($items as $i) {
            if ($i['type'] === 'skip' || $i['url'] === '') {
                continue;
            }
            $k = $i['url'];
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $i;
        }
        return $out;
    }
}