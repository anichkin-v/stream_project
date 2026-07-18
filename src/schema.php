<?php

declare(strict_types=1);

function ensure_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS videos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        original_name TEXT NOT NULL,
        source_path TEXT NOT NULL,
        hls_path TEXT,
        status TEXT NOT NULL DEFAULT 'queued'
            CHECK (status IN ('queued', 'processing', 'ready', 'failed')),
        progress INTEGER NOT NULL DEFAULT 0,
        processing_stage TEXT,
        duration_seconds REAL,
        error_message TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE INDEX IF NOT EXISTS videos_status_idx ON videos(status);
    SQL);

    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(videos)')->fetchAll() as $column) {
        $columns[$column['name']] = true;
    }

    $migrations = [
        'progress' => 'ALTER TABLE videos ADD COLUMN progress INTEGER NOT NULL DEFAULT 0',
        'processing_stage' => 'ALTER TABLE videos ADD COLUMN processing_stage TEXT',
        'duration_seconds' => 'ALTER TABLE videos ADD COLUMN duration_seconds REAL',
    ];

    foreach ($migrations as $column => $sql) {
        if (!isset($columns[$column])) {
            $pdo->exec($sql);
        }
    }
}
