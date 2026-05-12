<?php
class Database {
    private PDO $connection;

    public function __construct(string $dbPath = null) {
        if ($dbPath === null) {
            // Single source of truth shared with the Dart API and the mobile
            // app (ForsaDrive_PFE/forsa_drive_api). When this file exists, use
            // it; otherwise fall back to the legacy web-only DB.
            $shared = __DIR__ . '/../../ForsaDrive_PFE/forsa_drive_api/database/DB.db';
            $legacy = __DIR__ . '/../Database/DB.db';
            $dbPath = file_exists($shared) ? $shared : $legacy;
        }

        $dbPath = realpath($dbPath) ?: $dbPath;

        try {
            $this->connection = new PDO("sqlite:" . $dbPath);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // WAL lets PHP and the Dart server share the file concurrently
            $this->connection->exec("PRAGMA journal_mode = WAL");
            $this->connection->exec("PRAGMA foreign_keys = ON");
        } catch (PDOException $e) {
            die("Erreur de connexion à la base de données : " . $e->getMessage());
        }
    }

    public function getConnection(): PDO {
        return $this->connection;
    }
}
