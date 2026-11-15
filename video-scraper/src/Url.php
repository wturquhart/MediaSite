 <?php
declare(strict_types=1);

namespace VideoScraper;

final class Url
{
    public static function resolve(string $base, string $rel): string
    {
        $rel = trim(html_entity_decode($rel, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($rel === '') {
            return $base;
        }
        if (str_starts_with($rel, '//')) {
            return (parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $rel;
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $rel)) {
            return $rel;
        }

        $b = parse_url($base);
        if (!$b || empty($b['scheme']) || empty($b['host'])) {
            return $rel;
        }
        $root = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

        if ($rel[0] === '/') {
            return $root . $rel;
        }
        if ($rel[0] === '?') {
            return $root . ($b['path'] ?? '/') . $rel;
        }
        if ($rel[0] === '#') {
            return $base . $rel;
        }

        $dir = isset($b['path']) ? preg_replace('#/[^/]*$#', '/', $b['path']) : '/';
        return $root . self::normalize($dir . $rel);
    }

    public static function normalize(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        return '/' . implode('/', $out);
    }

    public static function origin(string $url): ?string
    {
        $p = parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) {
            return null;
        }
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    public static function base(string $url): string
    {
        return self::origin($url) . '/';
    }
}