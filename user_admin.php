<?php
/**
 * ポータル用ユーザー（ID/パスワード）管理 — 管理者専用
 * 要: portal_users に is_admin 列、かつ 1 人以上 is_admin=1
 */
require __DIR__ . '/_auth/bootstrap_session.php';
require __DIR__ . '/_auth/auth.php';
require __DIR__ . '/_auth/config.php';

function ua_err(string $code): void {
    header('Location: /user_admin.php?err=' . rawurlencode($code), true, 303);
    exit;
}
function ua_ok(string $code): void {
    header('Location: /user_admin.php?ok=' . rawurlencode($code), true, 303);
    exit;
}

function ua_count_active_admins(PDO $pdo): int {
    return (int) $pdo->query('SELECT COUNT(*) FROM portal_users WHERE is_active = 1 AND is_admin = 1')->fetchColumn();
}

function ua_valid_login_id(string $s): bool {
    if (strlen($s) < 1 || strlen($s) > 64) {
        return false;
    }
    return (bool) preg_match('/^[a-zA-Z0-9._-]+$/u', $s);
}

$showMigration = !portal_has_is_admin_column();
portal_require_login();
$selfId = portal_current_user_id();

if ($showMigration) {
    ?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DB 更新が必要 — エデュラボ管理システム</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Noto Sans JP',sans-serif;background:#0a0f1a;color:#e8ecf4;min-height:100vh;padding:32px 20px}
.wrap{max-width:640px;margin:0 auto}
.card{background:#111827;border:1px solid rgba(255,255,255,.06);border-radius:20px;padding:32px 28px}
h1{font-size:18px;margin-bottom:12px}
p,pre{font-size:14px;color:#c9d1dd;line-height:1.6;margin-bottom:12px}
pre{white-space:pre-wrap;word-break:break-all;background:#0a0f1a;padding:16px;border-radius:12px;border:1px solid rgba(255,255,255,.08);font-size:12px}
a.back{color:#3b82f6}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>データベースの更新が必要です</h1>
    <p>ユーザー管理の前に、次の SQL を 1 回 phpMyAdmin 等で実行して <code>is_admin</code> 列を追加してください。ファイル: <code>_auth/portal_users_add_is_admin.sql</code></p>
    <pre>ALTER TABLE `portal_users`
  ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1=ユーザー管理画面にアクセス可'
  AFTER `is_active`;</pre>
    <p>次に、あなたのログイン ID の管理者付与（例）を実行し、<a class="back" href="/user_admin.php">再読み込み</a> してください。</p>
    <pre>UPDATE `portal_users` SET `is_admin` = 1 WHERE `login_id` = 'あなたのID' LIMIT 1;</pre>
    <p><a class="back" href="/">トップ（エデュラボ管理システム）へ戻る</a></p>
  </div>
</div>
</body>
</html>
<?php
    exit;
}

portal_require_user_admin();

$pdo  = getPortalPdo();
$err  = isset($_GET['err']) ? (string) $_GET['err'] : '';
$ok   = isset($_GET['ok']) ? (string) $_GET['ok'] : '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string) ($_POST['csrf'] ?? '');
    if (!portal_verify_csrf($token)) {
        ua_err('csrf');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $loginId   = trim((string) ($_POST['login_id'] ?? ''));
            $plainPass  = (string) ($_POST['password'] ?? '');
            $displayName = trim((string) ($_POST['display_name'] ?? ''));
            $asAdmin   = !empty($_POST['make_admin']);
            if (!ua_valid_login_id($loginId)) {
                ua_err('login_id');
            }
            if (strlen($plainPass) < 8) {
                ua_err('pass_short');
            }
            if ($displayName === '') {
                $displayName = $loginId;
            }
            $h = password_hash($plainPass, PASSWORD_DEFAULT);
            $st = $pdo->prepare('INSERT INTO portal_users (login_id, password_hash, display_name, is_active, is_admin) VALUES (?,?,?,1,?)');
            $st->execute([$loginId, $h, $displayName, $asAdmin ? 1 : 0]);
            ua_ok('add');
        } elseif ($action === 'delete_user') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            if ($uid < 1) {
                ua_err('id');
            }
            if ($uid === $selfId) {
                ua_err('self_delete');
            }
            $st = $pdo->prepare('SELECT is_active, is_admin FROM portal_users WHERE id = ?');
            $st->execute([$uid]);
            $r = $st->fetch();
            if (!$r) {
                ua_err('id');
            }
            if ((int) $r['is_active'] === 1 && (int) $r['is_admin'] === 1 && ua_count_active_admins($pdo) <= 1) {
                ua_err('last_admin');
            }
            $st = $pdo->prepare('DELETE FROM portal_users WHERE id = ?');
            $st->execute([$uid]);
            if ($st->rowCount() < 1) {
                ua_err('id');
            }
            ua_ok('delete');
        } elseif ($action === 'toggle_admin') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            if ($uid < 1) {
                ua_err('id');
            }
            if ($uid === $selfId) {
                ua_err('self_admin');
            }
            $st = $pdo->prepare('SELECT is_active, is_admin FROM portal_users WHERE id = ?');
            $st->execute([$uid]);
            $r = $st->fetch();
            if (!$r) {
                ua_err('id');
            }
            if ((int) $r['is_active'] === 0) {
                ua_err('inactive');
            }
            if ((int) $r['is_admin'] === 0) {
                $st = $pdo->prepare('UPDATE portal_users SET is_admin = 1, updated_at = NOW() WHERE id = ?');
                $st->execute([$uid]);
            } else {
                if (ua_count_active_admins($pdo) <= 1) {
                    ua_err('last_admin');
                }
                $st = $pdo->prepare('UPDATE portal_users SET is_admin = 0, updated_at = NOW() WHERE id = ?');
                $st->execute([$uid]);
            }
            ua_ok('admin');
        } elseif ($action === 'set_password') {
            $uid  = (int) ($_POST['user_id'] ?? 0);
            $pass = (string) ($_POST['new_password'] ?? '');
            if ($uid < 1) {
                ua_err('id');
            }
            if (strlen($pass) < 8) {
                ua_err('pass_short');
            }
            $st  = $pdo->prepare('UPDATE portal_users SET password_hash = ?, updated_at = NOW() WHERE id = ?');
            $st->execute([password_hash($pass, PASSWORD_DEFAULT), $uid]);
            if ($st->rowCount() < 1) {
                ua_err('id');
            }
            ua_ok('pass');
        }
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            ua_err('dup');
        }
        throw $e;
    }
    header('Location: /user_admin.php', true, 303);
    exit;
}

$users = $pdo->query('SELECT id, login_id, display_name, is_active, is_admin, created_at FROM portal_users ORDER BY id ASC')->fetchAll();
$csrf  = portal_set_csrf_token();

$errMsg = [
    'csrf'         => 'セッションの有効期限が切れました。再度操作してください。',
    'login_id'     => 'ログインIDは半角英数字と ._- のみ、64 文字以内で入力してください。',
    'pass_short'   => 'パスワードは 8 文字以上にしてください。',
    'id'          => '対象ユーザーが見つかりません。',
    'self_delete'  => '自分自身のアカウントを削除することはできません。',
    'self_admin'  => '自分の管理者権限をここから外すことはできません。',
    'last_admin'  => '有効な管理者を 0 人にすることはできません。',
    'dup'         => 'そのログインIDは既に使われています。',
    'inactive'    => '無効化済みユーザーを管理者にできません。',
];
$okMsg = [
    'add'    => 'ユーザーを登録しました。',
    'delete' => 'ユーザーを削除しました。',
    'admin'  => '管理者権限を切り替えました。',
    'pass'   => 'パスワードを更新しました。',
];
$flashErr  = $err && isset($errMsg[$err]) ? $errMsg[$err] : ($err && $err !== '' ? '更新できませんでした。' : '');
$flashOk   = $ok && isset($okMsg[$ok]) ? $okMsg[$ok] : ($ok && $ok !== '' ? '保存しました。' : '');

$esc = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ユーザー管理 — エデュラボ管理システム</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Noto Sans JP',sans-serif;background:#0a0f1a;color:#e8ecf4;min-height:100vh;padding:28px 20px 48px}
a{color:#3b82f6;text-decoration:none}
a:hover{text-decoration:underline}
h1{font-size:20px;font-weight:700;margin-bottom:4px}
.sub{font-size:13px;color:#8892a8;margin-bottom:20px;max-width:800px}
.wrap{max-width:960px;margin:0 auto}
.top{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:20px}
.note{font-size:12px;color:#6b7a9a;margin-top:8px}
.msg{background:rgba(16,185,129,.1);color:#4ade80;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
.msg.e{background:rgba(193,41,46,.12);color:#f87171}
.grid{display:grid;grid-template-columns:1fr;gap:20px}
@media (min-width: 880px) {
  .grid{grid-template-columns:1fr 320px;align-items:start}
}
.card{background:#111827;border:1px solid rgba(255,255,255,.06);border-radius:20px;padding:24px 20px 22px}
.card h2{font-size:15px;font-weight:600;margin-bottom:14px}
label{display:block;font-size:12px;color:#8892a8;margin-bottom:4px}
input[type=text], input[type=password]{
  width:100%;padding:10px 12px;border:1px solid rgba(255,255,255,.1);border-radius:10px;
  background:#0a0f1a;color:#e8ecf4;font-size:14px;margin-bottom:10px
}
input:focus{outline:none;border-color:#3b82f6}
.pw-line{display:flex;gap:8px;align-items:stretch;margin-bottom:10px}
.pw-line input{flex:1;min-width:0;margin-bottom:0}
.pw-line .gen-btn{flex:0 0 auto;padding:0 12px;border-radius:10px;border:1px solid rgba(59,130,246,.35);background:rgba(59,130,246,.12);color:#93c5fd;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;font-family:inherit}
.pw-line .gen-btn:hover{border-color:#3b82f6;filter:brightness(1.08)}
.gen-hint{min-height:1.2em;font-size:11px;color:#6b7a9a;margin:0 0 10px}
.cbm{display:flex;align-items:center;gap:8px;font-size:13px;color:#b8c0d0;margin:8px 0 14px}
.cbm input{width:auto}
.btn{width:100%;padding:11px;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;background:linear-gradient(135deg,#3b82f6,#10b981);color:#fff}
.btn:hover{filter:brightness(1.05)}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:8px 10px;text-align:left;border-bottom:1px solid rgba(255,255,255,.06);vertical-align:top}
th{color:#8892a8;font-weight:500;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
.muted{color:#64748b}
form.inline{display:inline}
form.rowmini{margin:2px 0 0;gap:4px}
form.rowmini .btn2{font-size:11px;padding:4px 8px;border-radius:6px;border:1px solid rgba(255,255,255,.1);background:#0a0f1a;color:#a8b4cc;cursor:pointer}
form.rowmini .btn2.danger{background:rgba(193,41,46,.15);color:#f87171;border-color:rgba(248,113,113,.25)}
.pwform{margin-top:8px;max-width:200px}
.pwform .btn2{width:100%;margin-top:4px}
.badge{display:inline-block;font-size:10px;padding:2px 6px;border-radius:4px}
.b-on{background:rgba(16,185,129,.15);color:#4ade80}
.b-off{background:rgba(148,163,184,.15);color:#94a3b8}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <h1>ユーザー管理</h1>
      <p class="sub">新規のログインID・パスワードの登録、削除、パスワード再設定が行えます。パスワードは本人に必ず安全な方法で知らせてください。</p>
    </div>
    <a href="/">トップへ戻る</a>
  </div>
  <?php if ($flashErr !== ''): ?><div class="msg e"><?php echo $esc($flashErr); ?></div><?php endif; ?>
  <?php if ($flashOk !== '' && $flashErr === ''): ?><div class="msg"><?php echo $esc($flashOk); ?></div><?php endif; ?>

  <div class="grid">
    <div class="card" style="overflow-x:auto">
      <h2>登録ユーザー</h2>
      <table>
        <thead>
          <tr>
            <th>ID / 表示名</th>
            <th>状態</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u) {
            $uid  = (int) $u['id'];
            $on   = (int) $u['is_active'] === 1;
            $adm  = (int) $u['is_admin'] === 1;
            $self = $uid === $selfId;
            ?>
        <tr>
          <td>
            <div><strong><?php echo $esc($u['login_id']); ?></strong> <?php if ($self) { ?><span class="muted">（あなた）</span><?php } ?></div>
            <div class="muted" style="font-size:12px"><?php echo $esc($u['display_name'] ?? ''); ?></div>
            <div class="muted" style="font-size:11px;margin-top:4px">登録 <?php echo $esc($u['created_at'] ?? ''); ?></div>
          </td>
          <td>
            <span class="badge <?php echo $on ? 'b-on' : 'b-off'; ?>"><?php echo $on ? '有効' : '無効'; ?></span>
            <span class="badge <?php echo $adm ? 'b-on' : 'b-off'; ?>"><?php echo $adm ? '管理者' : '一般'; ?></span>
          </td>
          <td>
            <form class="rowmini" method="post" action="" style="display:block" onsubmit="return confirm('このユーザーを完全に削除します。よろしいですか？');">
              <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
              <input type="hidden" name="action" value="delete_user">
              <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
              <button class="btn2 danger" type="submit" <?php echo $self ? ' disabled title="自分自身は削除できません"' : ''; ?>>ユーザーを削除</button>
            </form>
            <form class="rowmini" method="post" action="" style="display:block">
              <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
              <input type="hidden" name="action" value="toggle_admin">
              <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
              <button class="btn2" type="submit" <?php
                echo $self
                    ? ' disabled title="自分の管理者解消は他の管理者に依頼、または次の手順で" '
                    : (!$on ? ' disabled' : ''); ?>>
                <?php echo $adm ? '管理者を外す' : '管理者にする'; ?>
              </button>
            </form>
            <form class="pwform" method="post" action="" autocomplete="off">
              <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
              <input type="hidden" name="action" value="set_password">
              <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
              <label>新パス（8文字以上）</label>
              <input type="password" name="new_password" required minlength="8" placeholder="新しいパスワード" <?php if (!$on) { echo 'disabled'; } ?>>
              <button class="btn2" type="submit" <?php if (!$on) { echo ' disabled'; } ?>>パスワード更新</button>
            </form>
          </td>
        </tr>
        <?php } ?>
        </tbody>
      </table>
    </div>
    <div>
      <div class="card">
        <h2>ユーザーを追加</h2>
        <form method="post" action="" autocomplete="off">
          <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
          <input type="hidden" name="action" value="add">
          <label for="aid">ログインID</label>
          <input id="aid" name="login_id" type="text" required pattern="[a-zA-Z0-9._\-]{1,64}" title="半角英数字と ._- のみ">
          <label for="apw">パスワード</label>
          <div class="pw-line">
            <input id="apw" name="password" type="password" required minlength="8" autocomplete="new-password" maxlength="200">
            <button type="button" class="gen-btn" id="apw-gen">ランダム生成</button>
          </div>
          <p class="gen-hint" id="apw-gen-hint" aria-live="polite"></p>
          <label for="adn">表示名（省略時はIDと同じ）</label>
          <input id="adn" name="display_name" type="text" placeholder="社名 担当者 など">
          <label class="cbm">
            <input name="make_admin" type="checkbox" value="1"> 管理画面（このページ）にアクセスさせる
          </label>
          <button class="btn" type="submit">登録</button>
        </form>
        <p class="note">同じIDは登録できません。平文のパスワードは、対面や社内手順など安全な経路で相手に伝えてください。</p>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  function makeRandomPassword() {
    const lower = 'abcdefghijkmnopqrstuvwxyz';
    const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const digits = '23456789';
    const sym = '@#$%&*?!-';
    const all = lower + upper + digits + sym;
    const n = 16;
    const buf = new Uint32Array(32);
    if (!window.crypto || !crypto.getRandomValues) { return null; }
    crypto.getRandomValues(buf);
    let s = '';
    s += lower[buf[0] % lower.length] + upper[buf[1] % upper.length]
      + digits[buf[2] % digits.length] + sym[buf[3] % sym.length];
    for (let i = 4; i < n; i++) {
      s += all[buf[i] % all.length];
    }
    const a = s.split('');
    crypto.getRandomValues(buf);
    for (let j = a.length - 1; j > 0; j--) {
      const k = buf[j] % (j + 1);
      const tmp = a[j];
      a[j] = a[k];
      a[k] = tmp;
    }
    return a.join('');
  }
  const apw = document.getElementById('apw');
  const gen = document.getElementById('apw-gen');
  const hint = document.getElementById('apw-gen-hint');
  if (!apw || !gen) { return; }
  gen.addEventListener('click', function () {
    if (hint) { hint.textContent = ''; }
    const p = makeRandomPassword();
    if (p == null) {
      if (hint) { hint.textContent = 'このブラウザでは乱数が使えません。手で入力してください。'; }
      return;
    }
    apw.value = p;
    apw.dispatchEvent(new Event('input', { bubbles: true }));
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(p).then(function () {
        if (hint) { hint.textContent = '16文字を生成し、クリップボードにコピーしました。相手へは安全な経路で渡してください。'; }
      }).catch(function () { copyFallback(); });
    } else {
      copyFallback();
    }
  });
  function copyFallback() {
    apw.type = 'text';
    apw.focus();
    apw.select();
    if (hint) { hint.textContent = '平文表示しています。Ctrl/Cmd+C でコピーし、共有後に欄外をクリックで非表示に戻ります。'; }
  }
  apw.addEventListener('blur', function () {
    if (apw.type === 'text') { apw.type = 'password'; }
  });
}());
</script>
</body>
</html>
