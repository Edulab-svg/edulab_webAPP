<?php
/**
 * 既存ユーザーのパスワードを、password_hash 形式に更新（CLI のみ）
 *
 * 平文のまま DB に入れているとログインできません。こちらで正しいハッシュに置き換えてください。
 *
 * 使い方（サーバのターミナルで public_html/_auth へ移動し）:
 *   php set_password_hash_cli.php <login_id> <新しい平文パス>
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc < 3) {
    fwrite(STDERR, "使い方: php set_password_hash_cli.php <login_id> <新しい平文パス>\n");
    exit(1);
}

require __DIR__ . '/config.php';

$loginId = $argv[1];
$plain   = $argv[2];
$hash    = password_hash($plain, PASSWORD_DEFAULT);
$pdo     = getPortalPdo();

$st = $pdo->prepare('UPDATE portal_users SET password_hash = ?, updated_at = NOW() WHERE login_id = ?');
$st->execute([$hash, $loginId]);
if ($st->rowCount() < 1) {
    fwrite(STDERR, "該当する login_id がありません: {$loginId}\n");
    exit(2);
}

echo "更新しました: {$loginId}（同じ平文でログインできます）\n";
