 <?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use VideoScraper\{Http, Extractor, Hls, Downloader, Scraper, JobQueue, DownloadException};

$cfg = require __DIR__ . '/../../config.php';

header('Content-Type: application/json');

if (!hash_equals($cfg['api_token'], $_SERVER['HTTP_X_API_TOKEN'] ?? '')) {
    http_response_code(401);
    exit(json_encode(['error' => 'unauthorized']));
}

$input = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST;
$url   = trim((string) ($input['url'] ?? ''));

if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
    http_response_code(422);
    exit(json_encode(['error' => 'valid http(s) url required']));
}

// Async: enqueue and return
if (!empty($input['async'])) {
    $queue = new JobQueue($cfg['db']);
    exit(json_encode(['job' => $queue->push($url)], JSON_UNESCAPED_SLASHES));
}

// Sync: do it inline
set_time_limit(0);
ignore_user_abort(true);

$http = new Http($cfg['connect_timeout'], $cfg['read_timeout'], $cfg['allow_private_hosts']);
$scraper = new Scraper(
    $http,
    new Extractor(),
    new Downloader($http, new Hls($http), $cfg['ffmpeg'], $cfg['yt_dlp'], $cfg['storage_dir'], $cfg['max_bytes']),
    $cfg['max_depth']
);

try {
    $result = $scraper->scrape($url);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (DownloadException $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()]);
}