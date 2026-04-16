<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'xs047468_wbs');
define('DB_USER', 'xs047468_wbs');
define('DB_PASS', 'Manten2024');

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("ALTER TABLE wbs_tasks ADD COLUMN misPriority VARCHAR(10) DEFAULT '中' AFTER mis");
    echo "✅ misPriority カラムを追加しました！<br>";
    echo "<br><b style='color:red'>⚠️ 完了後、このファイル(fix_column.php)をファイルマネージャで削除してください！</b>";

} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "ℹ️ misPriority カラムは既に存在します。問題ありません。";
    } else {
        echo "❌ エラー: " . $e->getMessage();
    }
}
?>
