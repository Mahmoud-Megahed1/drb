<?php
/**
 * Database Connection Helper
 * SQLite with WAL mode for concurrent access
 */
date_default_timezone_set('Asia/Baghdad');

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        $dbPath = __DIR__ . '/../database/app.db';
        $dbDir = dirname($dbPath);
        
        // Create database directory if not exists
        if (!file_exists($dbDir)) {
            mkdir($dbDir, 0755, true);
        }
        
        $isNewDb = !file_exists($dbPath);
        
        $this->pdo = new PDO("sqlite:$dbPath");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // SQLite optimizations
        $this->pdo->exec("PRAGMA journal_mode = WAL");
        $this->pdo->exec("PRAGMA busy_timeout = 5000");
        $this->pdo->exec("PRAGMA synchronous = NORMAL");
        $this->pdo->exec("PRAGMA cache_size = -2000");
        $this->pdo->exec("PRAGMA foreign_keys = ON");
        
        // Initialize schema if new database
        if ($isNewDb) {
            $this->initSchema();
        }
    }
    
    private function initSchema() {
        $schemaFile = __DIR__ . '/../database/schema.sql';
        if (file_exists($schemaFile)) {
            $schema = file_get_contents($schemaFile);
            $this->pdo->exec($schema);
        }
    }
    
    public static function get() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->pdo;
    }
    
    public static function reset() {
        self::$instance = null;
    }
}

/**
 * Helper function for quick access
 */
function db() {
    return Database::get();
}

/**
 * Transaction helper
 */
function dbTransaction($callback) {
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $result = $callback($pdo);
        $pdo->commit();
        return $result;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
