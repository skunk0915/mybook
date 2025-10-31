<?php
/**
 * データベース接続と初期化
 */

class Database {
    private $db = null;
    private $dbPath;

    public function __construct() {
        $this->dbPath = dirname(__DIR__) . '/data/books.db';
        $this->connect();
        $this->initialize();
    }

    /**
     * データベース接続
     */
    private function connect() {
        try {
            // データディレクトリが存在しない場合は作成
            $dataDir = dirname($this->dbPath);
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0777, true);
            }

            $this->db = new PDO('sqlite:' . $this->dbPath);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("データベース接続エラー: " . $e->getMessage());
        }
    }

    /**
     * テーブル初期化
     */
    private function initialize() {
        $sql = "CREATE TABLE IF NOT EXISTS books (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            start_date TEXT,
            end_date TEXT,
            rating INTEGER CHECK(rating >= 1 AND rating <= 5),
            review TEXT,
            cover_image TEXT,
            status TEXT NOT NULL DEFAULT '未読' CHECK(status IN ('未読', '読書中', '読了')),
            created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
        )";

        $this->db->exec($sql);
    }

    /**
     * データベース接続を取得
     */
    public function getConnection() {
        return $this->db;
    }

    /**
     * トランザクション開始
     */
    public function beginTransaction() {
        return $this->db->beginTransaction();
    }

    /**
     * コミット
     */
    public function commit() {
        return $this->db->commit();
    }

    /**
     * ロールバック
     */
    public function rollback() {
        return $this->db->rollback();
    }
}
