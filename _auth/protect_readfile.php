<?php
/**
 * 子アプリ用: 直前に $PORTAL_READFILE = __DIR__ . '/index.html'; などを定義してから
 *   require __DIR__ . '/../_auth/protect_readfile.php';
 * （パス階層に合わせて _auth への相対パスを変える。例: __DIR__ . '/../_auth/' や '/../../_auth/'）
 */
if (empty($PORTAL_READFILE) || !is_readable($PORTAL_READFILE)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Configuration error: PORTAL_READFILE';
    exit;
}
require __DIR__ . '/bootstrap_session.php';
require __DIR__ . '/auth.php';
portal_require_login();
readfile($PORTAL_READFILE);
