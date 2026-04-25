<?php
/**
 * 初回ユーザーの登録用（コマンドラインのみ）
 *
 * 例: php create_user_cli.php mylogin マイパス "表示名"
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc < 3) {
    fwrite(STDERR, "使い方: php create_user_cli.php <login_id> <password> [display_name]\n");
    exit(1);
}

require __DIR__ . '/config.php';

$loginId     = $argv[1];
$password    = $argv[2];
$displayName = $argv[3] ?? null;

$hash = password_hash($password, PASSWORD_DEFAULT);
$pdo  = getPortalPdo();

$st = $pdo->prepare('INSERT INTO portal_users (login_id, password_hash, display_name) VALUES (?,?,?)');
$st->execute([$loginId, $hash, $displayName]);

echo "登録しました: " . $loginId . "\n";
