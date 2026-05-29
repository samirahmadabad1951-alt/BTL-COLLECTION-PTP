<?php
//works2/backend/config/database.php

/**
 * Database Configuration
 * EcoStore E-commerce Platform
 */

class Database {
    private $host = "localhost";
    private $db_name = "ecostore";
    private $username = "root";
    private $password = "root";
    private $charset = "utf8mb4";
    public $conn;
    
    private $log_file = __DIR__ . "/../logs/db_errors.log";
    
    public function __construct() {
        $log_dir = dirname($this->log_file);
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0777, true);
        }
    }
    
    public function getConnection() {
        $this->conn = null;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ];

        // Try MAMP port 8889 first, then standard 3306, then no explicit port
        foreach ([8889, 3306, null] as $port) {
            try {
                $portPart = $port ? ";port=$port" : "";
                $dsn = "mysql:host={$this->host}{$portPart};dbname={$this->db_name};charset={$this->charset}";
                $this->conn = new PDO($dsn, $this->username, $this->password, $options);
                return $this->conn;
            } catch (PDOException $e) {
                $this->conn = null;
            }
        }

        $this->logError("All connection attempts failed for database: {$this->db_name}");
        error_log("Database connection failed: {$this->db_name}");
        return $this->conn;
    }
    
    private function logError($message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] {$message}" . PHP_EOL;
        file_put_contents($this->log_file, $log_entry, FILE_APPEND);
    }
    
    public function executeQuery($sql, $params = []) {
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch(PDOException $e) {
            $this->logError("Query error: " . $e->getMessage());
            return false;
        }
    }
    
    public function lastInsertId() {
        return $this->conn->lastInsertId();
    }
    
    public function beginTransaction() {
        return $this->conn->beginTransaction();
    }
    
    public function commit() {
        return $this->conn->commit();
    }
    
    public function rollback() {
        return $this->conn->rollback();
    }
}

function getDB() {
    $database = new Database();
    return $database->getConnection();
}

function dbQuery($sql, $params = []) {
    $db = getDB();
    if (!$db) return false;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
?>