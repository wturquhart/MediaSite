<?php
declare(strict_types=1);

namespace VideoScraper;

final class DownloadException extends \RuntimeException {}

final class Downloader
{
    public function __construct(
        private Http $http,
        private Hls $hls,
        private ?string $ffmpeg,
        private ?string $ytdlp,
        private string $storageDir,
        private int $maxBytes,
    ) {
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0775, true)) {
            throw new DownloadException("Cannot create {$this->storageDir}");
        }
    }

    /**
     * @param array{url:string,type:string} $candidate
     * @return array{path:string,bytes:int,mime:string}
     */
    public function fetch(array $candidate, string $slug, ?string $referer, ?string $poster = null): array
    {
        $referer = $referer ?: Url::base($candidate['url']);
        $base    = $this->storageDir . '/' . $slug;

        $path = match ($candidate['type']) {
            'file'  => $this->fetchDirect($candidate['url'], $base . '.bin', $referer),
            'hls'   => $this->fetchHls($candidate['url'], $base, $referer),
            'dash'  => $this->fetchDash($candidate['url'], $base, $referer),
            default => throw new DownloadException('Unresolvable candidate type'),
        };

        // Normalise to MP4
        $mp4 = $base . '.mp4';
        if ($path !== $mp4) {
            $this->remux($path, $mp4, $poster);
            @unlink($path);
        }
        if (!is_file($mp4) || filesize($mp4) === 0) {
            throw new DownloadException('Produced empty file');
        }
        return [
            'path'  => $mp4,
            'bytes' => (int) filesize($mp4),
            'mime'  => 'video/mp4',
        ];
    }

    private function fetchDirect(string $url, string $dest, ?string $referer): string
    {
        $this->http->download($url, $dest, [], $referer, $this->maxBytes);
        return $dest;
    }

    private function fetchHls(string $url, string $base, ?string $referer): string
    {
        if ($this->ffmpeg) {
            return $this->ffmpegFetch($url, $base . '.ts', $referer);
        }

        // Pure-PHP fallback
        $res      = $this->http->get($url, [], $referer);
        $playlist = $res['body'];
        $variant  = $this->hls->bestVariant($playlist, $res['url']);
        if ($variant) {
            $res      = $this->http->get($variant, [], $referer);
            $playlist = $res['body'];
            $url      = $res['url'];
        }
        $segments = $this->hls->segmentUrls($playlist, $url);
        if (!$segments) {
            throw new DownloadException('No segments in playlist');
        }
        return $this->hls->fetchSegments($segments, $base, $referer, $this->maxBytes);
    }

    private function fetchDash(string $url, string $base, ?string $referer): string
    {
        if (!$this->ffmpeg) {
            throw new DownloadException('DASH requires ffmpeg');
        }
        return $this->ffmpegFetch($url, $base . '.mp4', $referer);
    }

    private function ffmpegFetch(string $url, string $dest, ?string $referer): string
    {
        $hdr = "Referer: $referer\r\n";
        $cmd = sprintf(
            '%s -hide_banner -loglevel error -y -headers %s -i %s -c copy -bsf:a aac_adtstoasc %s 2>&1',
            escapeshellarg($this->ffmpeg),
            escapeshellarg($hdr),
            escapeshellarg($url),
            escapeshellarg($dest)
        );
        $out = shell_exec($cmd);
        if (!is_file($dest) || filesize($dest) === 0) {
            throw new DownloadException('ffmpeg failed: ' . trim((string) $out));
        }
        return $dest;
    }

    private function remux(string $src, string $dest, ?string $poster): void
    {
        if ($this->ffmpeg) {
            $cmd = sprintf(
                '%s -hide_banner -loglevel error -y -i %s -c copy -movflags +faststart %s 2>&1',
                escapeshellarg($this->ffmpeg),
                escapeshellarg($src),
                escapeshellarg($dest)
            );
            shell_exec($cmd);
            if ($poster) {
                $thumb = preg_replace('#\.mp4$#', '.jpg', $dest);
                shell_exec(sprintf(
                    '%s -hide_banner -loglevel error -y -ss 00:00:03 -i %s -frames:v 1 -vf scale=640:-1 %s 2>&1',
                    escapeshellarg($this->ffmpeg),
                    escapeshellarg($dest),
                    escapeshellarg($thumb)
                ));
            }
            return;
        }
        // No ffmpeg: TS/unknown container stays as-is
        if (str_ends_with($src, '.ts') || str_ends_with($src, '.bin')) {
            rename($src, preg_replace('#\.(ts|bin)$#', '.mp4', $dest));
        }
    }

    public function fetchWithYtdlp(string $pageUrl, string $slug, string $referer): ?array
    {
        if (!$this->ytdlp) {
            return null;
        }
        $dest = $this->storageDir . '/' . $slug . '.%(ext)s';
        $cmd  = sprintf(
            '%s --no-playlist --no-warnings --restrict-filenames -f "bv*+ba/b" --merge-output-format mp4 -o %s %s 2>&1',
            escapeshellarg($this->ytdlp),
            escapeshellarg($dest),
            escapeshellarg($pageUrl)
        );
        shell_exec($cmd);
        foreach (glob($this->storageDir . '/' . $slug . '.*') as $f) {
            if (preg_match('#\.(mp4|mkv|webm)$#', $f) && filesize($f) > 0) {
                $final = $this->storageDir . '/' . $slug . '.mp4';
                if ($f !== $final) {
                    rename($f, $final);
                }
                return ['path' => $final, 'bytes' => (int) filesize($final), 'mime' => 'video/mp4'];
            }
        }
        return null;
    }
}