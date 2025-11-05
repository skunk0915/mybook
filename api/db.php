<?php
/**
 * データベース接続と初期化
 */

class Database {
    private $db = null;
    private $config;

    public function __construct() {
        // 設定ファイルの読み込み
        $configPath = __DIR__ . '/config.php';
        if (!file_exists($configPath)) {
            throw new Exception("設定ファイルが見つかりません: config.php");
        }
        $this->config = require $configPath;

        $this->connect();
        $this->initialize();
    }

    /**
     * データベース接続
     */
    private function connect() {
        try {
            $dbConfig = $this->config['db'];
            $dsn = sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=%s",
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['database'],
                $dbConfig['charset']
            );

            $this->db = new PDO(
                $dsn,
                $dbConfig['username'],
                $dbConfig['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$dbConfig['charset']}"
                ]
            );
        } catch (PDOException $e) {
            throw new Exception("データベース接続エラー: " . $e->getMessage());
        }
    }

    /**
     * テーブル初期化
     */
    private function initialize() {
        $sql = "CREATE TABLE IF NOT EXISTS books (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(500) NOT NULL,
            start_date DATE,
            end_date DATE,
            rating INT CHECK(rating >= 1 AND rating <= 5),
            review TEXT,
            cover_image VARCHAR(500),
            status VARCHAR(20) NOT NULL DEFAULT '未読' CHECK(status IN ('未読', '読書中', '読了')),
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

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
