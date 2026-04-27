<?php
require __DIR__ . '/_auth/bootstrap_session.php';
require __DIR__ . '/_auth/auth.php';
require __DIR__ . '/_auth/config.php';

$rawRedirect = '/';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['redirect'])) {
    $rawRedirect = $_POST['redirect'];
} elseif (isset($_GET['redirect'])) {
    $rawRedirect = $_GET['redirect'];
}
$redirect = portal_safe_redirect_path($rawRedirect);
$error    = '';

if (portal_is_logged_in()) {
    header('Location: ' . $redirect);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (!portal_verify_csrf($token)) {
        $error = 'セッションの有効期限が切れました。再度お試しください。';
    } else {
        $loginId = trim((string)($_POST['login_id'] ?? ''));
        $pass    = (string)($_POST['password'] ?? '');
        if ($loginId === '' || $pass === '') {
            $error = 'ID と パスワードを入力してください。';
        } else {
            try {
                $pdo = getPortalPdo();
                $row = null;
                try {
                    $st = $pdo->prepare('SELECT id, password_hash, is_active, is_admin FROM portal_users WHERE login_id = ? LIMIT 1');
                    $st->execute([$loginId]);
                    $row = $st->fetch();
                } catch (Throwable $e) {
                    $st = $pdo->prepare('SELECT id, password_hash, is_active FROM portal_users WHERE login_id = ? LIMIT 1');
                    $st->execute([$loginId]);
                    $row = $st->fetch();
                }
                if ($row && (int)$row['is_active'] === 1 && password_verify($pass, $row['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['portal_user_id']  = (int) $row['id'];
                    $_SESSION['portal_is_admin'] = isset($row['is_admin']) && (int) $row['is_admin'] === 1;
                    header('Location: ' . $redirect);
                    exit;
                }
            } catch (Throwable $e) {
                $error = 'サーバーでエラーが発生しました。';
            }
            if ($error === '') {
                $error = 'ID または パスワードが正しくありません。';
            }
        }
    }
}

$csrf = portal_set_csrf_token();
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ログイン — エデュラボ管理システム</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Noto Sans JP',sans-serif;background:#0a0f1a;color:#e8ecf4;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.card{width:100%;max-width:400px;background:#111827;border:1px solid rgba(255,255,255,.06);border-radius:20px;padding:40px 32px}
h1{font-size:20px;font-weight:700;margin-bottom:8px}
.sub{font-size:13px;color:#8892a8;margin-bottom:28px}
label{display:block;font-size:12px;color:#8892a8;margin-bottom:6px}
input{width:100%;padding:12px 14px;border:1px solid rgba(255,255,255,.1);border-radius:10px;background:#0a0f1a;color:#e8ecf4;font-size:15px}
input:focus{outline:none;border-color:#3b82f6}
.m{margin-bottom:16px}
button{width:100%;padding:12px;border:none;border-radius:10px;background:linear-gradient(135deg,#3b82f6,#10b981);color:#fff;font-size:15px;font-weight:600;cursor:pointer}
button:hover{filter:brightness(1.05)}
.err{background:rgba(193,41,46,.12);color:#f87171;padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:16px}
</style>
</head>
<body>
<div class="card">
  <h1>エデュラボ管理システム</h1>
  <p class="sub">ログインして続行してください</p>
  <?php if ($error !== ''): ?><div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
  <form method="post" action="">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="m">
      <label for="login_id">ID</label>
      <input type="text" id="login_id" name="login_id" autocomplete="username" required>
    </div>
    <div class="m">
      <label for="password">パスワード</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>
    </div>
    <button type="submit">ログイン</button>
  </form>
</div>
</body>
</html>
