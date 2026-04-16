<!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8"><title>DB権限テスト</title>
<style>body{font-family:sans-serif;max-width:800px;margin:40px auto;padding:20px}.ok{color:green;font-weight:bold}.ng{color:red;font-weight:bold}pre{background:#f0f0f0;padding:10px;border-radius:5px;font-size:12px;overflow:auto}</style>
</head><body>
<h1>🔧 DB権限テスト</h1>
<?php
require_once __DIR__ . '/api/config.php';
$db = getDB();

echo '<h2>1. 接続情報</h2>';
echo '<pre>DB_HOST: ' . DB_HOST . "\nDB_NAME: " . DB_NAME . "\nDB_USER: " . DB_USER . '</pre>';

echo '<h2>2. テスト用レコード作成 (INSERT)</h2>';
try {
    $testId = 'test-' . bin2hex(random_bytes(4));
    $db->prepare("INSERT INTO projects (id, name, open_date) VALUES (?, 'DELETE権限テスト', '2099-01-01')")->execute([$testId]);
    echo '<p class="ok">✅ INSERT OK (id=' . $testId . ')</p>';
} catch (Exception $e) {
    echo '<p class="ng">❌ INSERT失敗: ' . $e->getMessage() . '</p>';
    echo '</body></html>';
    exit;
}

echo '<h2>3. レコード確認 (SELECT)</h2>';
try {
    $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->execute([$testId]);
    $row = $stmt->fetch();
    echo $row ? '<p class="ok">✅ SELECT OK: ' . htmlspecialchars($row['name']) . '</p>' : '<p class="ng">❌ レコードが見つからない</p>';
} catch (Exception $e) {
    echo '<p class="ng">❌ SELECT失敗: ' . $e->getMessage() . '</p>';
}

echo '<h2>4. DELETE実行</h2>';
try {
    $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
    $result = $stmt->execute([$testId]);
    $affected = $stmt->rowCount();
    echo '<p>execute() 戻り値: ' . ($result ? 'true' : 'false') . '</p>';
    echo '<p>影響行数 (rowCount): ' . $affected . '</p>';
    if ($affected > 0) {
        echo '<p class="ok">✅ DELETE成功！ ' . $affected . '行削除</p>';
    } else {
        echo '<p class="ng">❌ DELETE実行されたが0行（権限不足の可能性）</p>';
    }
} catch (Exception $e) {
    echo '<p class="ng">❌ DELETE失敗: ' . $e->getMessage() . '</p>';
}

echo '<h2>5. 削除確認 (SELECT)</h2>';
try {
    $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->execute([$testId]);
    $row = $stmt->fetch();
    if (!$row) {
        echo '<p class="ok">✅ レコード削除済み（正常）</p>';
    } else {
        echo '<p class="ng">❌ レコードがまだ存在する！DELETEが効いていません</p>';
        echo '<p>→ phpMyAdminでユーザー権限を確認してください</p>';
    }
} catch (Exception $e) {
    echo '<p class="ng">❌ ' . $e->getMessage() . '</p>';
}

echo '<h2>6. ユーザー権限確認</h2>';
try {
    $grants = $db->query("SHOW GRANTS")->fetchAll(PDO::FETCH_COLUMN);
    echo '<pre>';
    foreach ($grants as $g) echo htmlspecialchars($g) . "\n";
    echo '</pre>';
} catch (Exception $e) {
    echo '<p class="ng">権限確認できず: ' . $e->getMessage() . '</p>';
}

echo '<h2>7. 既存プロジェクト一覧</h2>';
try {
    $rows = $db->query("SELECT id, name, status FROM projects")->fetchAll();
    echo '<pre>';
    foreach ($rows as $r) echo $r['id'] . ' | ' . $r['name'] . ' | ' . $r['status'] . "\n";
    echo count($rows) . '件</pre>';
} catch (Exception $e) {
    echo '<p class="ng">❌ ' . $e->getMessage() . '</p>';
}
?>
</body></html>
