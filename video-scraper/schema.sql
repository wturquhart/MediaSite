 CREATE TABLE scrape_jobs (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_url   TEXT NOT NULL,
    status       ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
    file_path    VARCHAR(512) NULL,
    file_bytes   BIGINT UNSIGNED NULL,
    title        VARCHAR(255) NULL,
    error        TEXT NULL,
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at   TIMESTAMP NULL,
    finished_at  TIMESTAMP NULL,
    INDEX idx_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;