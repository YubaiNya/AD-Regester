<?php

declare(strict_types=1);

namespace AdRegister;

use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new \RuntimeException('当前 PHP 未启用 pdo_sqlite，无法使用后台数据库。');
        }

        $dataDir = (string)$config['paths']['data_dir'];
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0770, true);
        }

        $this->pdo = new PDO('sqlite:' . $config['paths']['database_file']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS invites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    note TEXT NOT NULL DEFAULT '',
    max_uses INTEGER NULL,
    used_count INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    deleted_at TEXT NULL
);
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS registered_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    upn TEXT NOT NULL DEFAULT '',
    display_name TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    backend TEXT NOT NULL DEFAULT '',
    group_name TEXT NOT NULL DEFAULT '',
    group_dn TEXT NOT NULL DEFAULT '',
    user_dn TEXT NOT NULL DEFAULT '',
    invite_code TEXT NOT NULL DEFAULT '',
    created_ip TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    deleted_at TEXT NULL,
    deleted_by TEXT NOT NULL DEFAULT '',
    delete_error TEXT NOT NULL DEFAULT ''
);
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS registration_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL,
    username TEXT NOT NULL DEFAULT '',
    display_name TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    invite_code TEXT NOT NULL DEFAULT '',
    success INTEGER NOT NULL DEFAULT 0,
    message TEXT NOT NULL DEFAULT '',
    ref TEXT NOT NULL DEFAULT '',
    ip TEXT NOT NULL DEFAULT '',
    user_agent TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
SQL);

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_invites_code ON invites(code)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_invites_active ON invites(active, expires_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_registered_users_status ON registered_users(status, created_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_registration_events_created ON registration_events(created_at)');
    }
}
