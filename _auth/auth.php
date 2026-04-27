<?php

function portal_safe_redirect_path($url) {
    if (!is_string($url) || $url === '') {
        return '/';
    }
    if (strpos($url, '/') !== 0) {
        return '/';
    }
    if (isset($url[1]) && $url[0] === '/' && $url[1] === '/') {
        return '/';
    }
    return $url;
}

function portal_is_logged_in() {
    return !empty($_SESSION['portal_user_id']);
}

function portal_require_login() {
    if (portal_is_logged_in()) {
        return;
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: /login.php?redirect=' . rawurlencode($uri));
    exit;
}

function portal_set_csrf_token() {
    if (empty($_SESSION['portal_csrf'])) {
        $_SESSION['portal_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['portal_csrf'];
}

function portal_verify_csrf($token) {
    return is_string($token) && !empty($_SESSION['portal_csrf']) && hash_equals($_SESSION['portal_csrf'], $token);
}

/**
 * 認証用の PDO。config.php が未読み込みならここで読み込みます。
 */
function portal_get_pdo() {
    static $done = null;
    if ($done === null) {
        require_once __DIR__ . '/config.php';
        $done = getPortalPdo();
    }
    return $done;
}

/**
 * portal_users.is_admin 列の有無（1 度だけ DB 参照）
 */
function portal_has_is_admin_column() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $pdo = portal_get_pdo();
        $st  = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_users' AND COLUMN_NAME = 'is_admin' LIMIT 1");
        $st->execute();
        $cached = (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function portal_current_user_id() {
    return (int) ($_SESSION['portal_user_id'] ?? 0);
}

/**
 * DB の is_active=1 かつ is_admin=1
 */
function portal_is_user_admin() {
    if (!portal_is_logged_in() || !portal_has_is_admin_column()) {
        return false;
    }
    $id = portal_current_user_id();
    if ($id < 1) {
        return false;
    }
    try {
        $pdo = portal_get_pdo();
        $st  = $pdo->prepare('SELECT is_active, is_admin FROM portal_users WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row
            && (int) $row['is_active'] === 1
            && (int) $row['is_admin'] === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function portal_require_user_admin() {
    portal_require_login();
    if (portal_is_user_admin()) {
        return;
    }
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>アクセスできません — ユーザー管理</title><link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">'
        . '<style>body{font-family:\'Noto Sans JP\',sans-serif;background:#0a0f1a;color:#e8ecf4;min-height:100vh;margin:0;display:flex;align-items:center;justify-content:center;padding:24px}'
        . '.c{max-width:400px;width:100%;background:#111827;border:1px solid rgba(255,255,255,.06);border-radius:20px;padding:32px 28px}'
        . 'h1{font-size:18px;margin:0 0 12px}'
        . 'p{font-size:14px;color:#94a3b8;line-height:1.6;margin:0 0 20px}'
        . 'a{display:inline-block;color:#3b82f6;font-size:14px;text-decoration:none}.a2{margin-top:8px;display:block;color:#64748b;font-size:12px}.a1:hover,a:hover{text-decoration:underline}</style></head><body><div class="c">'
        . '<h1>管理者権限が必要です</h1><p>ユーザー管理画面にアクセスするには、<strong>管理者</strong>として付与されたアカウントが必要です。社内の管理者の方に権限付与を依頼するか、別の管理者アカウントでログインし直してください。</p>'
        . '<a class="a1" href="/">トップ（エデュラボ管理システム）へ戻る</a><a class="a2" href="/logout.php">いったんログアウトする</a></div></body></html>';
    exit;
}
