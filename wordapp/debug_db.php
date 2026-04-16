<?php
// =============================================
// DB接続診断ファイル ── 確認後すぐ削除してください
// =============================================
$configPath = __DIR__ . '/api/config.php';
echo '<style>body{font-family:sans-serif;padding:24px;line-height:1.7} .ok{color:green;font-weight:bold} .ng{color:red;font-weight:bold} table{border-collapse:collapse;margin-bottom:16px} td,th{border:1px solid #ccc;padding:8px 14px} h3{margin-top:24px;border-left:4px solid #2563eb;padding-left:10px}</style>';
echo '<h2>🔍 DB接続診断</h2>';

echo '<h3>① ファイル確認</h3><table>';
$files = ['api/config.php'=>__DIR__.'/api/config.php','api/index.php'=>__DIR__.'/api/index.php','.htaccess'=>__DIR__.'/.htaccess','index.html'=>__DIR__.'/index.html'];
foreach ($files as $label => $path) {
    $ok = file_exists($path);
    echo "<tr><td>{$label}</td><td>".($ok?'<span class="ok">✅ 存在する</span>':'<span class="ng">❌ 見つからない</span>')."</td></tr>";
}
echo '</table>';

if (!file_exists($configPath)) { echo '<p class="ng">❌ api/config.php が見つかりません。</p>'; exit; }
require_once $configPath;

echo '<h3>② config.php の設定値</h3><table>';
foreach (['DB_HOST'=>DB_HOST,'DB_NAME'=>DB_NAME,'DB_USER'=>DB_USER] as $k=>$v) {
    $ng = in_array($v,['YOUR_DB_NAME','YOUR_DB_USER','YOUR_DB_PASSWORD','localhost'])?($k==='DB_HOST'?false:true):false;
    echo "<tr><td><strong>{$k}</strong></td><td>{$v}</td><td>".($ng?'<span class="ng">❌ 未変更</span>':'<span class="ok">✅ OK</span>')."</td></tr>";
}
echo "<tr><td><strong>DB_PASS</strong></td><td>".str_repeat('*',8)."</td><td>".((DB_PASS==='YOUR_DB_PASSWORD')?'<span class="ng">❌ 未変更</span>':'<span class="ok">✅ OK</span>')."</td></tr>";
echo '</table>';

echo '<h3>③ DB接続テスト</h3>';
try {
    $db = getDB();
    echo '<p class="ok">✅ DB接続成功！</p>';
    echo '<h3>④ テーブル確認</h3><table><tr><th>テーブル</th><th>状態</th><th>件数</th></tr>';
    foreach (['units','questions'] as $tbl) {
        try {
            $cnt = $db->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
            echo "<tr><td>{$tbl}</td><td><span class='ok'>✅ 存在する</span></td><td>{$cnt}件</td></tr>";
        } catch(Exception $e) {
            echo "<tr><td>{$tbl}</td><td><span class='ng'>❌ 存在しない → setup.sqlを実行してください</span></td><td>－</td></tr>";
        }
    }
    echo '</table>';
    $base = (isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['REQUEST_URI']);
    $apiUrl = rtrim($base,'/').'api/index.php?action=list_units&_t='.time();
    echo "<h3>⑤ API直接確認</h3><p>以下のURLをクリックしてJSONが返るか確認してください：<br><a href='{$apiUrl}' target='_blank'>{$apiUrl}</a></p>";
} catch(PDOException $e) {
    echo '<p class="ng">❌ DB接続失敗：'.htmlspecialchars($e->getMessage()).'</p>';
    if(str_contains($e->getMessage(),'Access denied')) echo '<p>→ DB名・ユーザー名・パスワードが間違っています。Xserverパネルで確認してください。</p>';
    elseif(str_contains($e->getMessage(),'Unknown database')) echo '<p>→ データベースが存在しません。phpMyAdminで作成してください。</p>';
    elseif(str_contains($e->getMessage(),"Can't connect")) echo '<p>→ DB_HOSTを確認してください（通常はlocalhost）。</p>';
}
echo '<hr><p style="color:#999;font-size:12px;">⚠️ 確認後すぐ削除してください</p>';
