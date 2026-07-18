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

    CREATE TABLE IF NOT EXISTS series (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL UNIQUE,
        description TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
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
        source_width INTEGER,
        source_height INTEGER,
        qualities_json TEXT,
        preview_vtt_path TEXT,
        series_id INTEGER REFERENCES series(id) ON DELETE SET NULL,
        season_number INTEGER NOT NULL DEFAULT 1,
        episode_number INTEGER,
        storage_profile_json TEXT,
        error_message TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL,
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
        'source_width' => 'ALTER TABLE videos ADD COLUMN source_width INTEGER',
        'source_height' => 'ALTER TABLE videos ADD COLUMN source_height INTEGER',
        'qualities_json' => 'ALTER TABLE videos ADD COLUMN qualities_json TEXT',
        'preview_vtt_path' => 'ALTER TABLE videos ADD COLUMN preview_vtt_path TEXT',
        'series_id' => 'ALTER TABLE videos ADD COLUMN series_id INTEGER REFERENCES series(id) ON DELETE SET NULL',
        'season_number' => 'ALTER TABLE videos ADD COLUMN season_number INTEGER NOT NULL DEFAULT 1',
        'episode_number' => 'ALTER TABLE videos ADD COLUMN episode_number INTEGER',
        'storage_profile_json' => 'ALTER TABLE videos ADD COLUMN storage_profile_json TEXT',
    ];

    foreach ($migrations as $column => $sql) {
        if (!isset($columns[$column])) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS videos_series_idx
         ON videos(series_id, season_number, episode_number)'
    );

    $defaults = [
        'site_title' => 'Детское видео',
        'site_tagline' => 'Добрые видео для детей',
        'player_accent_color' => '#ff4e63',
        'player_default_volume' => '80',
        'player_autoplay_next' => '1',
        'player_next_delay' => '5',
        'player_seek_step' => '10',
        'player_preview_interval' => '10',
        'player_show_quality' => '1',
        'player_show_volume' => '1',
        'player_show_fullscreen' => '1',
        'player_show_next' => '1',
        'player_show_preview' => '1',
        'player_brand_name' => 'KidsTub',
        'storage_driver' => 'local',
        'storage_source_path' => dirname(__DIR__) . '/storage/uploads',
        'storage_media_path' => dirname(__DIR__) . '/public/media',
        'storage_public_url' => '/media',
        'storage_s3_bucket' => '',
        'storage_s3_region' => 'us-east-1',
        'storage_s3_prefix' => 'kidstub',
        'storage_s3_endpoint' => '',
        'storage_s3_path_style' => '0',
    ];
    $insertSetting = $pdo->prepare(
        'INSERT OR IGNORE INTO settings (setting_key, setting_value)
         VALUES (:key, :value)'
    );
    foreach ($defaults as $key => $value) {
        $insertSetting->execute(['key' => $key, 'value' => $value]);
    }
}
