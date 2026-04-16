<?php
// ========================================================
// DB接続設定 — 以下の3か所を書き換えてください
// ========================================================

define('DB_HOST',    'localhost');
define('DB_NAME',    'xs047468_jobinterview');   // ← DB名に書き換え
define('DB_USER',    'xs047468_jobint');         // ← DBユーザー名に書き換え
define('DB_PASS',    'Uff^n#yypa;/');     // ← DBパスワードに書き換え
define('DB_CHARSET', 'utf8mb4');

// ========================================================
// 認証パスワード — ここを書き換えてください
// ※ password_hash('manten10', PASSWORD_DEFAULT) の出力値を貼る
//   例）php -r "echo password_hash('mypass123', PASSWORD_DEFAULT);"
//   または下記オンラインツールで生成:
//   https://bcrypt-generator.com/
// ========================================================
define('APP_PASSWORD_HASH', '$2y$10$QErsHcfBEnxXt0CkoETOp.DtwLREUUvq3ZWqeOWvF1/rnCZK8gOQq');
//                                       ↑ この値を自分のハッシュに書き換え

// セッション設定（変更不要）
define('SESSION_NAME',    'interview_sess');
define('SESSION_LIFETIME', 60 * 60 * 8);   // 8時間

// アップロードファイルの保存先
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', '/jobinterview/api/uploads/');     // ← サイトのパスに合わせて変更

// 許可するファイル種別
define('ALLOWED_MIME',  ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf']);
define('MAX_FILE_SIZE',  10 * 1024 * 1024); // 10MB

// ブルートフォース対策（5分間に5回失敗でロック）
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_WINDOW_SEC',   300);

// ========================================================
// 以下は変更不要
// ========================================================
function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function is_logged_in(): bool {
    start_session();
    return !empty($_SESSION['authenticated'])
        && !empty($_SESSION['expires_at'])
        && time() < $_SESSION['expires_at'];
}

function require_auth(): void {
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'unauthenticated']);
        exit;
    }
}
