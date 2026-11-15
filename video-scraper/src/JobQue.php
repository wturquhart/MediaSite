 <?php
declare(strict_types=1);

namespace VideoScraper;

final class JobQueue
{
    private \PDO $db;

    public function __construct(array $cfg)
    {
        $this->db = new \PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    public function push(string $url): int
    {
        $st = $this->db->prepare('INSERT INTO scrape_jobs (source_url) VALUES (?)');
        $st->execute([$url]);
        return (int) $this->db->lastInsertId();
    }

    public function claim(): ?array
    {
        $this->db->beginTransaction();
        $st = $this->db->query(
            "SELECT * FROM scrape_jobs WHERE status = 'queued' AND attempts < 3
             ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED"
        );
        $job = $st->fetch();
        if (!$job) {
            $this->db->commit();
            return null;
        }
        $this->db->prepare(
            "UPDATE scrape_jobs SET status='running', attempts=attempts+1, started_at=NOW() WHERE id=?"
        )->execute([$job['id']]);
        $this->db->commit();
        return $job;
    }

    public function complete(int $id, array $result): void
    {
        $this->db->prepare(
            "UPDATE scrape_jobs SET status='done', file_path=?, file_bytes=?, title=?, finished_at=NOW() WHERE id=?"
        )->execute([$result['path'], $result['bytes'], $result['title'] ?? null, $id]);
    }

    public function fail(int $id, string $error): void
    {
        $this->db->prepare(
            "UPDATE scrape_jobs SET status='failed', error=?, finished_at=NOW() WHERE id=?"
        )->execute([mb_substr($error, 0, 4000), $id]);
    }

    public function find(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM scrape_jobs WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
}