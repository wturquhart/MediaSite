<?php
declare(strict_types=1);

namespace VideoScraper;

final class Hls
{
    public function __construct(private Http $http) {}

    /** Returns the highest-bandwidth variant URL, or null for a media playlist. */
    public function bestVariant(string $playlist, string $url): ?string
    {
        if (!str_contains($playlist, '#EXT-X-STREAM-INF')) {
            return null;
        }
        $best = null;
        $bestBw = -1;
        $lines = preg_split('/\R/', $playlist);
        $pendingBw = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#EXT-X-STREAM-INF')) {
                if (preg_match('/BANDWIDTH=(\d+)/', $line, $m)) {
                    $pendingBw = (int) $m[1];
                }
                continue;
            }
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if ($pendingBw !== null && $pendingBw > $bestBw) {
                $bestBw = $pendingBw;
                $best   = Url::resolve($url, $line);
            }
            $pendingBw = null;
        }
        return $best;
    }

    public function segmentUrls(string $playlist, string $url): array
    {
        $urls = [];
        foreach (preg_split('/\R/', $playlist) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $urls[] = Url::resolve($url, $line);
        }
        return $urls;
    }

    /** Concatenate segments into a .ts file. Returns path. */
    public function fetchSegments(array $urls, string $outPath, ?string $referer, int $maxBytes): string
    {
        $tmp = $outPath . '.ts';
        $fh  = fopen($tmp, 'wb');
        if (!$fh) {
            throw new HttpException("Cannot write $tmp");
        }
        $total = 0;
        try {
            foreach ($urls as $i => $seg) {
                if ($total >= $maxBytes) {
                    break;
                }
                $part = sys_get_temp_dir() . "/seg_$i.part";
                $this->http->download($seg, $part, [], $referer, $maxBytes - $total);
                $total += (int) filesize($part);
                stream_copy_to_stream(fopen($part, 'rb'), $fh);
                @unlink($part);
            }
        } finally {
            fclose($fh);
        }
        return $tmp;
    }
}