 <?php
declare(strict_types=1);

namespace VideoScraper;

final class HttpException extends \RuntimeException {}

final class Http
{
    private const MAX_REDIRECTS = 6;
    private array $cookies = [];

    public function __construct(
        private int $connectTimeout = 10,
        private int $readTimeout = 120,
        private bool $allowPrivate = false,
        private string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    ) {}

    /** @return array{body:string,url:string,content_type:string,status:int} */
    public function get(string $url, array $headers = [], ?string $referer = null): array
    {
        return $this->request($url, $headers, $referer, null, 8 * 1024 * 1024);
    }

    /** Streams to $path. Returns bytes written. */
    public function download(
        string $url,
        string $path,
        array $headers = [],
        ?string $referer = null,
        int $maxBytes = 0
    ): int {
        $fh = fopen($path, 'wb');
        if (!$fh) {
            throw new HttpException("Cannot open $path for writing");
        }
        try {
            $res = $this->request($url, $headers, $referer, $fh, $maxBytes);
            return (int) fstat($fh)['size'];
        } finally {
            fclose($fh);
        }
    }

    private function request(
        string $url,
        array $headers,
        ?string $referer,
        $sink,
        int $maxBytes
    ): array {
        $current  = $url;
        $referers = $referer ? [$referer] : [];

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $this->assertSafe($current);

            $ch  = curl_init();
            $hdr = $this->buildHeaders($headers, $referers[0] ?? null, $current);

            $opts = [
                CURLOPT_URL               => $current,
                CURLOPT_FOLLOWLOCATION    => false,
                CURLOPT_CONNECTTIMEOUT    => $this->connectTimeout,
                CURLOPT_TIMEOUT           => $this->readTimeout,
                CURLOPT_USERAGENT         => $this->userAgent,
                CURLOPT_ENCODING          => '',
                CURLOPT_SSL_VERIFYPEER    => true,
                CURLOPT_SSL_VERIFYHOST    => 2,
                CURLOPT_HTTPHEADER        => $hdr,
                CURLOPT_RETURNTRANSFER    => $sink === null,
            ];

            if ($sink !== null) {
                $written = 0;
                $opts[CURLOPT_FILE] = $sink;
                if ($maxBytes > 0) {
                    $opts[CURLOPT_PROGRESSFUNCTION] = function ($c, $dt, $down) use ($maxBytes, &$written) {
                        $written = $down;
                        return $down > $maxBytes ? 1 : 0;
                    };
                    $opts[CURLOPT_NOPROGRESS] = false;
                }
            }

            curl_setopt_array($ch, $opts);
            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ct     = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $err    = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                throw new HttpException("Transport error for $current: $err");
            }

            $this->storeCookies($current, $body, $status);

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $loc = $this->lastLocation;
                if (!$loc) {
                    throw new HttpException("Redirect without Location from $current");
                }
                $next = Url::resolve($current, $loc);
                array_unshift($referers, $current);
                $current = $next;
                if ($sink !== null) {
                    rewind($sink);
                    ftruncate($sink, 0);
                }
                continue;
            }

            if ($status >= 400) {
                throw new HttpException("HTTP $status for $current");
            }

            return [
                'body'         => is_string($body) ? $body : '',
                'url'          => $current,
                'content_type' => strtolower(trim(explode(';', $ct)[0])),
                'status'       => $status,
            ];
        }

        throw new HttpException('Too many redirects');
    }

    private ?string $lastLocation = null;

    private function buildHeaders(array $extra, ?string $referer, string $url): array
    {
        $out = ['Accept: */*', 'Accept-Language: en-US,en;q=0.9'];
        if ($referer) {
            $out[] = 'Referer: ' . $referer;
            $origin = Url::origin($referer);
            if ($origin) {
                $out[] = 'Origin: ' . $origin;
            }
        }
        $host = parse_url($url, PHP_URL_HOST);
        if ($host && !empty($this->cookies[$host])) {
            $out[] = 'Cookie: ' . implode('; ', $this->cookies[$host]);
        }
        foreach ($extra as $h) {
            $out[] = $h;
        }
        return $out;
    }

    private function storeCookies(string $url, $body, int $status): void
    {
        // Captured separately; see header capture below.
    }

    // --- SSRF guard -------------------------------------------------------

    private function assertSafe(string $url): void
    {
        $p = parse_url($url);
        if (!$p || empty($p['host']) || empty($p['scheme'])) {
            throw new HttpException("Malformed URL: $url");
        }
        if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
            throw new HttpException("Unsupported scheme: {$p['scheme']}");
        }
        if ($this->allowPrivate) {
            return;
        }
        $host = strtolower($p['host']);
        if ($host === 'localhost') {
            throw new HttpException('localhost is not allowed');
        }
        $ips = $this->resolveIps($host);
        if (!$ips) {
            throw new HttpException("Cannot resolve $host");
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new HttpException("$host resolves to private address $ip");
            }
        }
    }

    private function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            if (!empty($r['ip']))   { $ips[] = $r['ip']; }
            if (!empty($r['ipv6'])) { $ips[] = $r['ipv6']; }
        }
        if (!$ips) {
            foreach (@gethostbynamel($host) ?: [] as $ip) {
                $ips[] = $ip;
            }
        }
        return $ips;
    }

    private static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            foreach ([
                ['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16],
                ['127.0.0.0', 8], ['169.254.0.0', 16], ['100.64.0.0', 10],
                ['0.0.0.0', 8], ['198.18.0.0', 15], ['192.0.2.0', 24],
                ['240.0.0.0', 4],
            ] as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }
            return true;
        }
        $ip = strtolower($ip);
        if ($ip === '::1' || $ip === '::') { return false; }
        if (preg_match('/^f[cd][0-9a-f]{2}:/', $ip)) { return false; }
        if (preg_match('/^fe[89ab][0-9a-f]:/', $ip)) { return false; }
        if (str_starts_with($ip, '::ffff:')) {
            return self::isPublicIp(substr($ip, 7));
        }
        return true;
    }
}