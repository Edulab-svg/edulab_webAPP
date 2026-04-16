<?php
// ==============================================
// DB connection settings
// ==============================================
// ** Change these 3 values to your Xserver DB info **

define('DB_HOST', 'localhost');
define('DB_NAME', 'xs047468_mantan');    
define('DB_USER', 'xs047468_mantan');    
define('DB_PASS', '0Ra^Bx:TH0_C');     
define('DB_CHARSET', 'utf8mb4');

function getDB() {
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]);
        exit;
    }
}