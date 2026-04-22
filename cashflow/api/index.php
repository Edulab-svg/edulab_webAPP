<?php
// ============================================================
//  api/index.php  — API エンドポイント（認証なし）
//  GET  /api/?action=xxx&param=yyy
//  POST /api/  body: { action: "xxx", ... }
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = '';
$body   = [];

if ($method === 'GET') {
    $action = trim($_GET['action'] ?? '');
    // GET のクエリを各 action に渡す（従来 $body が空で year/month 等が常に「今月」になっていた）
    $body = $_GET;
} elseif ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = $raw ? (json_decode($raw, true) ?? []) : $_POST;
    $action = trim($body['action'] ?? '');
} else {
    json_error('Method not allowed', 405);
}

// ============================================================
//  ルーティング
// ============================================================
switch ($action) {

    // --- ダッシュボード ---
    case 'dashboard_summary':  action_dashboard_summary();       break;

    // --- 預金残高 ---
    case 'balance_list':       action_balance_list($body);       break;
    case 'balance_save':       action_balance_save($body);       break;
    case 'balance_delete':     action_balance_delete($body);     break;

    // --- 科目マスタ ---
    case 'categories_list':    action_categories_list();         break;
    case 'category_save':      action_category_save($body);      break;
    case 'category_move':      action_category_move($body);      break;
    case 'category_delete':    action_category_delete($body);    break;
    case 'category_templates_list':  action_category_templates_list();   break;
    case 'category_template_save':   action_category_template_save($body); break;
    case 'category_template_delete': action_category_template_delete($body); break;

    // --- 口座マスタ ---
    case 'accounts_list':      action_accounts_list();           break;
    case 'account_save':       action_account_save($body);       break;
    case 'account_move':       action_account_move($body);       break;
    case 'account_delete':     action_account_delete($body);     break;

    // --- アラート設定 ---
    case 'alerts_list':        action_alerts_list();             break;
    case 'alert_save':         action_alert_save($body);         break;

    // --- 日次CF ---
    case 'daily_grid':         action_daily_grid($body);         break;
    case 'daily_cell_save':    action_daily_cell_save($body);    break;
    case 'daily_apply_templates': action_daily_apply_templates($body); break;
    case 'daily_entry_save':   action_daily_entry_save($body);   break;
    case 'daily_entry_update': action_daily_entry_update($body); break;
    case 'daily_entry_delete': action_daily_entry_delete($body); break;

    default:
        json_error("Unknown action: {$action}", 404);
}

// ============================================================
//  ACTION: ダッシュボードサマリー
// ============================================================
function action_dashboard_summary(): void {
    $pdo = get_pdo();
    $ym  = date('Y-m-01');

    // 当月 収入計・支出計（実績優先、なければ予定）
    $sql = "
        SELECT c.type,
               SUM(e.plan_amount) AS plan,
               SUM(COALESCE(e.actual_amount, e.plan_amount)) AS actual
        FROM cashflow_entries e
        JOIN categories c ON c.id = e.category_id
        WHERE e.entry_date = :ym
        GROUP BY c.type
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':ym' => $ym]);
    $rows = $st->fetchAll();

    $s = ['income' => ['plan'=>0,'actual'=>0], 'expense' => ['plan'=>0,'actual'=>0]];
    foreach ($rows as $r) {
        $s[$r['type']] = ['plan' => (int)$r['plan'], 'actual' => (int)$r['actual']];
    }
    $s['net_plan']   = $s['income']['plan']   - $s['expense']['plan'];
    $s['net_actual'] = $s['income']['actual'] - $s['expense']['actual'];

    // 直近12か月 残高推移（各月の最終登録残高の合計）
    $sql2 = "
        SELECT DATE_FORMAT(b.balance_date,'%Y-%m') AS ym,
               SUM(b.balance) AS total
        FROM bank_balances b
        WHERE b.balance_date >= DATE_SUB(:ym, INTERVAL 11 MONTH)
        GROUP BY ym
        ORDER BY ym
    ";
    $st2 = $pdo->prepare($sql2);
    $st2->execute([':ym' => $ym]);

    json_ok([
        'summary'       => $s,
        'balance_trend' => $st2->fetchAll(),
        'alerts'        => check_alerts($pdo),
        'current_month' => date('Y-m'),
    ]);
}

function check_alerts(PDO $pdo): array {
    $alerts = [];
    $settings = $pdo->query("SELECT * FROM alert_settings WHERE is_active=1")->fetchAll();

    foreach ($settings as $s) {
        if ($s['alert_type'] === 'plan_balance_low') {
            $threshold = (int)$s['threshold'];
            $from = date('Y-m-01');
            $to   = date('Y-m-t');

            // 当月の日次予定 収入・支出 合計を日付ごとに取得
            $st = $pdo->prepare("
                SELECT d.entry_date,
                       COALESCE(SUM(CASE WHEN c.type='income'  THEN d.amount ELSE 0 END), 0) AS inc,
                       COALESCE(SUM(CASE WHEN c.type='expense' THEN d.amount ELSE 0 END), 0) AS exp
                FROM daily_cashflow_entries d
                INNER JOIN categories c ON c.id = d.category_id
                WHERE d.entry_date BETWEEN ? AND ?
                  AND d.entry_type = 'plan'
                GROUP BY d.entry_date
                ORDER BY d.entry_date
            ");
            $st->execute([$from, $to]);
            $dailyMap = [];
            foreach ($st->fetchAll() as $r) {
                $dailyMap[$r['entry_date']] = [(int)$r['inc'], (int)$r['exp']];
            }

            // 繰越残高: 当月開始日より前の最新残高合計
            $bSt = $pdo->prepare("
                SELECT COALESCE(SUM(b2.balance), 0) AS total
                FROM bank_balances b2
                INNER JOIN (
                    SELECT account_id, MAX(balance_date) AS max_date
                    FROM bank_balances
                    WHERE balance_date < ?
                    GROUP BY account_id
                ) latest ON b2.account_id = latest.account_id
                         AND b2.balance_date = latest.max_date
                INNER JOIN bank_accounts a ON a.id = b2.account_id
                WHERE a.is_active = 1
            ");
            $bSt->execute([$from]);
            $bRow    = $bSt->fetch();
            $carryIn = ($bRow && (int)$bRow['total'] > 0) ? (int)$bRow['total'] : null;

            // 繰越が不明な場合は当月内最初の実残高を起点にする
            if ($carryIn === null) {
                $fSt = $pdo->prepare("
                    SELECT COALESCE(SUM(b.balance), 0) AS total
                    FROM bank_balances b
                    INNER JOIN bank_accounts a ON a.id = b.account_id
                    WHERE b.balance_date = (
                        SELECT MIN(balance_date) FROM bank_balances
                        WHERE balance_date BETWEEN ? AND ?
                    ) AND a.is_active = 1
                ");
                $fSt->execute([$from, $to]);
                $fRow    = $fSt->fetch();
                $carryIn = ($fRow && (int)$fRow['total'] > 0) ? (int)$fRow['total'] : null;
            }

            if ($carryIn === null) {
                continue; // 起点残高が特定できないためスキップ
            }

            // 日別予定残高を積み上げてアラート判定
            $cur      = $from;
            $running  = $carryIn;
            $minBal   = PHP_INT_MAX;
            $hasAlert = false;
            while ($cur <= $to) {
                $inc     = $dailyMap[$cur][0] ?? 0;
                $exp     = $dailyMap[$cur][1] ?? 0;
                $running += ($inc - $exp);
                if ($running < $threshold) {
                    $hasAlert = true;
                    $minBal   = min($minBal, $running);
                }
                $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
            }

            if ($hasAlert) {
                $alerts[] = [
                    'type'    => 'plan_balance_low',
                    'message' => '当月の予定残高が警戒ライン（'.number_format($threshold).'円）を下回る日があります（最小: '.number_format($minBal).'円）',
                    'level'   => 'danger',
                ];
            }
        }
    }
    return $alerts;
}

// ============================================================
//  ACTION: 預金残高一覧
// ============================================================
function action_balance_list(array $b): void {
    $pdo   = get_pdo();
    $year  = intval($b['year']  ?? date('Y'));
    $month = intval($b['month'] ?? date('n'));

    $sql = "
        SELECT bb.id, a.id AS account_id, a.name AS account_name,
               bb.balance_date, bb.balance, bb.memo
        FROM bank_balances bb
        JOIN bank_accounts a ON a.id = bb.account_id
        WHERE YEAR(bb.balance_date)=:y AND MONTH(bb.balance_date)=:m
          AND a.is_active=1
        ORDER BY a.sort_order ASC, a.id ASC, bb.balance_date
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':y'=>$year, ':m'=>$month]);

    $accounts = $pdo->query("SELECT * FROM bank_accounts WHERE is_active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
    json_ok(['balances' => $st->fetchAll(), 'accounts' => $accounts]);
}

// ============================================================
//  ACTION: 預金残高保存
// ============================================================
function action_balance_save(array $b): void {
    $pdo     = get_pdo();
    $acc_id  = intval($b['account_id'] ?? 0);
    $date    = preg_replace('/[^0-9\-]/', '', $b['balance_date'] ?? '');
    $balance = intval($b['balance'] ?? 0);
    $memo    = substr($b['memo'] ?? '', 0, 255);

    if (!$acc_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_error('パラメータ不正');
    }
    $sql = "
        INSERT INTO bank_balances (account_id, balance_date, balance, memo)
        VALUES (:aid, :date, :bal, :memo)
        ON DUPLICATE KEY UPDATE balance=VALUES(balance), memo=VALUES(memo)
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':aid'=>$acc_id, ':date'=>$date, ':bal'=>$balance, ':memo'=>$memo]);
    json_ok(['affected' => $st->rowCount()]);
}

// ============================================================
//  ACTION: 預金残高削除
// ============================================================
function action_balance_delete(array $b): void {
    $pdo = get_pdo();
    $id  = intval($b['id'] ?? 0);
    if (!$id) json_error('ID不正');
    $pdo->prepare("DELETE FROM bank_balances WHERE id=?")->execute([$id]);
    json_ok();
}

// ============================================================
//  ACTION: 科目マスタ一覧
// ============================================================
function action_categories_list(): void {
    $pdo = get_pdo();
    json_ok(['categories' => $pdo->query(
        "SELECT c.*, a.name AS account_name
         FROM categories c
         LEFT JOIN bank_accounts a ON a.id = c.account_id
         ORDER BY c.type DESC, c.sort_order ASC, c.id ASC"
    )->fetchAll()]);
}

// ============================================================
//  ACTION: 科目 保存
// ============================================================
function action_category_save(array $b): void {
    $pdo    = get_pdo();
    $id     = intval($b['id'] ?? 0);
    $type   = in_array($b['type'] ?? '', ['income','expense']) ? $b['type'] : 'income';
    $cf     = in_array($b['cf_section'] ?? '', ['operating','investing','financing']) ? $b['cf_section'] : 'operating';
    $name   = substr(trim($b['name'] ?? ''), 0, 100);
    $sort   = intval($b['sort_order'] ?? 0);
    $accRaw = $b['account_id'] ?? '';
    $acc_id = ($accRaw !== '' && $accRaw !== null && intval($accRaw) > 0) ? intval($accRaw) : null;
    if (!$name) json_error('名称は必須です');

    if ($id) {
        $pdo->prepare("UPDATE categories SET type=?,cf_section=?,name=?,sort_order=?,account_id=? WHERE id=?")
            ->execute([$type,$cf,$name,$sort,$acc_id,$id]);
    } else {
        $pdo->prepare("INSERT INTO categories (type,cf_section,name,sort_order,account_id) VALUES (?,?,?,?,?)")
            ->execute([$type,$cf,$name,$sort,$acc_id]);
        $id = (int)$pdo->lastInsertId();
    }
    json_ok(['id' => $id]);
}

// ============================================================
//  ACTION: 科目 並び替え（同一種別内で1段上/下）
// ============================================================
function action_category_move(array $b): void {
    $pdo = get_pdo();
    $id  = intval($b['id'] ?? 0);
    $dir = ($b['dir'] ?? '') === 'down' ? 'down' : 'up';
    if (!$id) {
        json_error('ID不正');
    }

    $st = $pdo->prepare('SELECT type FROM categories WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_error('科目がありません');
    }
    $type = $row['type'];

    $st = $pdo->prepare('SELECT id FROM categories WHERE type=? ORDER BY sort_order ASC, id ASC');
    $st->execute([$type]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    $ids = array_map('intval', $ids);
    $i   = array_search($id, $ids, true);
    if ($i === false) {
        json_error('科目がありません');
    }
    $j = $dir === 'up' ? $i - 1 : $i + 1;
    if ($j < 0 || $j >= count($ids)) {
        json_ok();
    }

    $tmp      = $ids[$i];
    $ids[$i]  = $ids[$j];
    $ids[$j]  = $tmp;

    $upd = $pdo->prepare('UPDATE categories SET sort_order=? WHERE id=?');
    foreach ($ids as $ord => $cid) {
        $upd->execute([$ord, $cid]);
    }
    json_ok();
}

// ============================================================
//  ACTION: 科目 削除
// ============================================================
function action_category_delete(array $b): void {
    $pdo = get_pdo();
    $id  = intval($b['id'] ?? 0);
    if (!$id) json_error('ID不正');
    $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
    json_ok();
}

// ============================================================
//  日次テンプレート: カレンダー日が「毎月 dom 日」に該当するか（31=短い月は月末）
// ============================================================
function daily_template_matches_date(string $ymd, int $templateDom): bool {
    $templateDom = max(1, min(31, $templateDom));
    $ts = strtotime($ymd . ' 12:00:00');
    if ($ts === false) {
        return false;
    }
    $d = (int) date('j', $ts);
    $last = (int) date('t', $ts);
    if ($templateDom >= $last) {
        return $d === $last;
    }

    return $d === $templateDom;
}

// ============================================================
//  ACTION: 日次テンプレート一覧
// ============================================================
function action_category_templates_list(): void {
    $pdo = get_pdo();
    $rows = $pdo->query(
        "SELECT t.id, t.category_id, t.day_of_month, t.amount, t.memo,
                c.name AS category_name, c.type AS category_type, c.cf_section
         FROM category_daily_templates t
         JOIN categories c ON c.id = t.category_id
         ORDER BY t.day_of_month ASC, c.type DESC, c.sort_order ASC, c.id ASC, t.id ASC"
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['amount'] = (int) $r['amount'];
    }
    unset($r);
    json_ok(['templates' => $rows]);
}

// ============================================================
//  ACTION: 日次テンプレート 保存
// ============================================================
function action_category_template_save(array $b): void {
    $pdo  = get_pdo();
    $id   = intval($b['id'] ?? 0);
    $cid  = intval($b['category_id'] ?? 0);
    $dom  = intval($b['day_of_month'] ?? 0);
    $amt  = intval($b['amount'] ?? 0);
    $memo = substr(trim($b['memo'] ?? ''), 0, 255);
    if (!$cid || $dom < 1 || $dom > 31) {
        json_error('科目・日（1〜31）を確認してください');
    }
    if ($amt < 0) {
        json_error('金額は0以上です');
    }
    if ($id) {
        $pdo->prepare(
            'UPDATE category_daily_templates SET category_id=?, day_of_month=?, amount=?, memo=? WHERE id=?'
        )->execute([$cid, $dom, $amt, $memo !== '' ? $memo : null, $id]);
        json_ok(['id' => $id]);

        return;
    }
    $pdo->prepare(
        'INSERT INTO category_daily_templates (category_id, day_of_month, amount, memo) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE amount=VALUES(amount), memo=VALUES(memo)'
    )->execute([$cid, $dom, $amt, $memo !== '' ? $memo : null]);
    $nid = (int) $pdo->lastInsertId();
    if ($nid === 0) {
        $st = $pdo->prepare('SELECT id FROM category_daily_templates WHERE category_id=? AND day_of_month=?');
        $st->execute([$cid, $dom]);
        $nid = (int) $st->fetchColumn();
    }
    json_ok(['id' => $nid]);
}

// ============================================================
//  ACTION: 日次テンプレート 削除
// ============================================================
function action_category_template_delete(array $b): void {
    $pdo = get_pdo();
    $id  = intval($b['id'] ?? 0);
    if (!$id) {
        json_error('ID不正');
    }
    $pdo->prepare('DELETE FROM category_daily_templates WHERE id=?')->execute([$id]);
    json_ok();
}

// ============================================================
//  ACTION: 口座マスタ一覧
// ============================================================
function action_accounts_list(): void {
    $pdo = get_pdo();
    json_ok(['accounts' => $pdo->query(
        "SELECT * FROM bank_accounts ORDER BY sort_order ASC, id ASC"
    )->fetchAll()]);
}

// ============================================================
//  ACTION: 口座 保存
// ============================================================
function action_account_save(array $b): void {
    $pdo   = get_pdo();
    $id    = intval($b['id'] ?? 0);
    $name  = substr(trim($b['name'] ?? ''), 0, 100);
    $bank  = substr(trim($b['bank_name'] ?? ''), 0, 100);
    $sort  = intval($b['sort_order'] ?? 0);
    $act   = intval($b['is_active'] ?? 1);
    if (!$name) json_error('名称は必須です');

    if ($id) {
        $pdo->prepare("UPDATE bank_accounts SET name=?,bank_name=?,sort_order=?,is_active=? WHERE id=?")
            ->execute([$name,$bank,$sort,$act,$id]);
    } else {
        $pdo->prepare("INSERT INTO bank_accounts (name,bank_name,sort_order,is_active) VALUES (?,?,?,?)")
            ->execute([$name,$bank,$sort,$act]);
        $id = (int)$pdo->lastInsertId();
    }
    json_ok(['id' => $id]);
}

// ============================================================
//  ACTION: 口座 並び替え（一覧順で1段上/下）
// ============================================================
function action_account_move(array $b): void {
    $pdo = get_pdo();
    $id  = intval($b['id'] ?? 0);
    $dir = ($b['dir'] ?? '') === 'down' ? 'down' : 'up';
    if (!$id) {
        json_error('ID不正');
    }

    $ids = $pdo->query('SELECT id FROM bank_accounts ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_COLUMN);
    $ids = array_map('intval', $ids);
    $i   = array_search($id, $ids, true);
    if ($i === false) {
        json_error('口座がありません');
    }
    $j = $dir === 'up' ? $i - 1 : $i + 1;
    if ($j < 0 || $j >= count($ids)) {
        json_ok();
    }

    $tmp     = $ids[$i];
    $ids[$i] = $ids[$j];
    $ids[$j] = $tmp;

    $upd = $pdo->prepare('UPDATE bank_accounts SET sort_order=? WHERE id=?');
    foreach ($ids as $ord => $cid) {
        $upd->execute([$ord, $cid]);
    }
    json_ok();
}

// ============================================================
//  ACTION: 口座 削除
// ============================================================
function action_account_delete(array $b): void {
    $pdo = get_pdo();
    $id  = intval($b['id'] ?? 0);
    if (!$id) json_error('ID不正');
    $pdo->prepare("DELETE FROM bank_accounts WHERE id=?")->execute([$id]);
    json_ok();
}

// ============================================================
//  ACTION: アラート一覧
// ============================================================
function action_alerts_list(): void {
    $pdo = get_pdo();
    json_ok(['alerts' => $pdo->query("SELECT * FROM alert_settings ORDER BY id")->fetchAll()]);
}

// ============================================================
//  ACTION: アラート保存
// ============================================================
function action_alert_save(array $b): void {
    $pdo       = get_pdo();
    $id        = intval($b['id'] ?? 0);
    $threshold = intval($b['threshold'] ?? 0);
    $label     = substr(trim($b['label'] ?? ''), 0, 100);
    $active    = intval($b['is_active'] ?? 1);
    if (!$id) json_error('ID不正');

    $pdo->prepare("UPDATE alert_settings SET threshold=?,label=?,is_active=? WHERE id=?")
        ->execute([$threshold, $label, $active, $id]);
    json_ok();
}

// ============================================================
//  ACTION: 日次セル一括保存（グリッドから1セル分をまとめて管理）
//  指定 category_id × entry_date × entry_type の既存データを全削除し、
//  amount > 0 なら新規1件挿入する（スプレッドシート的な1セル上書き）
// ============================================================
function action_daily_cell_save(array $b): void {
    $pdo        = get_pdo();
    $cat_id     = intval($b['category_id'] ?? 0);
    $date       = preg_replace('/[^0-9\-]/', '', $b['entry_date'] ?? '');
    $entry_type = ($b['entry_type'] ?? '') === 'actual' ? 'actual' : 'plan';
    $amount     = intval($b['amount'] ?? 0);
    $memo       = substr($b['memo'] ?? '', 0, 255);

    if (!$cat_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_error('パラメータ不正');
    }

    $pdo->prepare(
        "DELETE FROM daily_cashflow_entries WHERE category_id=? AND entry_date=? AND entry_type=?"
    )->execute([$cat_id, $date, $entry_type]);

    $id = null;
    if ($amount > 0) {
        $pdo->prepare(
            "INSERT INTO daily_cashflow_entries (category_id, entry_date, entry_type, amount, memo)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$cat_id, $date, $entry_type, $amount, $memo]);
        $id = (int)$pdo->lastInsertId();
    }
    json_ok(['id' => $id]);
}

// ============================================================
//  ACTION: 日次テンプレートを日次CF（予定）へ反映
//  開始月〜終了月（同一可）の各月について、from_date 以降かつ当月末までの日を走査。
//  テンプレの「日」と一致する日に amount を書き込み。
//  only_empty=true のとき、既に予定合計>0のセルはスキップ。
//  to_year/to_month 省略時は year/month のみ（当月のみ・後方互換）。
// ============================================================
function action_daily_apply_templates(array $b): void {
    $pdo   = get_pdo();
    $y1    = intval($b['year'] ?? 0);
    $m1    = intval($b['month'] ?? 0);
    $y2    = intval($b['to_year'] ?? $y1);
    $m2    = intval($b['to_month'] ?? $m1);
    $oe    = $b['only_empty'] ?? true;
    $onlyEmpty = $oe === true || $oe === 1 || $oe === '1' || $oe === 'true';
    $fromStr = preg_replace('/[^0-9\-]/', '', $b['from_date'] ?? '');
    if (
        !$y1 || $m1 < 1 || $m1 > 12
        || !$y2 || $m2 < 1 || $m2 > 12
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromStr)
    ) {
        json_error('年月・基準日が不正です');
    }
    if ($y1 * 12 + $m1 > $y2 * 12 + $m2) {
        json_error('開始月は終了月以前にしてください');
    }

    $templates = $pdo->query(
        'SELECT t.category_id, t.day_of_month, t.amount, t.memo
         FROM category_daily_templates t
         INNER JOIN categories c ON c.id = t.category_id'
    )->fetchAll();
    if (!$templates) {
        json_ok(['applied' => 0, 'skipped' => 0]);
    }
    $chk = $pdo->prepare(
        'SELECT COALESCE(SUM(amount),0) FROM daily_cashflow_entries WHERE category_id=? AND entry_date=? AND entry_type=\'plan\''
    );
    $del = $pdo->prepare(
        'DELETE FROM daily_cashflow_entries WHERE category_id=? AND entry_date=? AND entry_type=\'plan\''
    );
    $ins = $pdo->prepare(
        'INSERT INTO daily_cashflow_entries (category_id, entry_date, entry_type, amount, memo) VALUES (?,?,?,?,?)'
    );
    $applied = 0;
    $skipped = 0;

    $pdo->beginTransaction();
    try {
        $y = $y1;
        $m = $m1;
        while (true) {
            $monthStart = sprintf('%04d-%02d-01', $y, $m);
            $monthEnd   = date('Y-m-t', strtotime($monthStart));
            $curFrom    = $fromStr;
            if ($curFrom < $monthStart) {
                $curFrom = $monthStart;
            }
            if ($curFrom <= $monthEnd) {
                $cur = $curFrom;
                while ($cur <= $monthEnd) {
                    foreach ($templates as $t) {
                        $dom   = (int) $t['day_of_month'];
                        $catId = (int) $t['category_id'];
                        $amt   = (int) $t['amount'];
                        if ($amt <= 0) {
                            continue;
                        }
                        if (!daily_template_matches_date($cur, $dom)) {
                            continue;
                        }
                        $chk->execute([$catId, $cur]);
                        $sum = (int) $chk->fetchColumn();
                        if ($onlyEmpty && $sum > 0) {
                            ++$skipped;
                            continue;
                        }
                        $memo = trim((string) ($t['memo'] ?? ''));
                        if ($memo === '') {
                            $memo = 'テンプレート反映';
                        }
                        $del->execute([$catId, $cur]);
                        $ins->execute([$catId, $cur, 'plan', $amt, $memo]);
                        ++$applied;
                    }
                    $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
                }
            }
            if ($y === $y2 && $m === $m2) {
                break;
            }
            ++$m;
            if ($m > 12) {
                $m = 1;
                ++$y;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('反映に失敗しました');
    }
    json_ok(['applied' => $applied, 'skipped' => $skipped]);
}

// ============================================================
//  ACTION: 日次CFグリッド取得
//  指定年月の全日付 × 全科目の明細を返す
//  戻り値:
//    categories: [{id,type,name,sort_order}, ...]
//    entries:    [{id,category_id,entry_date,entry_type,amount,memo}, ...]
//    balances:   [{account_id,account_name,balance_date,balance}, ...]  繰越用に過去分を含む
//    days:       ["YYYY-MM-DD", ...]  当月の全日付
// ============================================================
function action_daily_grid(array $b): void {
    $pdo   = get_pdo();
    $year  = intval($b['year']  ?? date('Y'));
    $month = intval($b['month'] ?? date('n'));

    $from = sprintf('%04d-%02d-01', $year, $month);
    $to   = date('Y-m-t', strtotime($from)); // 月末日

    // 全科目（口座情報含む）
    $cats = $pdo->query(
        "SELECT c.id, c.type, c.cf_section, c.name, c.sort_order, c.account_id,
                a.name AS account_name
         FROM categories c
         LEFT JOIN bank_accounts a ON a.id = c.account_id
         ORDER BY c.type DESC, c.sort_order ASC, c.id ASC"
    )->fetchAll();

    // 当月の日次明細
    $st = $pdo->prepare(
        "SELECT id, category_id, entry_date, entry_type, amount, memo
         FROM daily_cashflow_entries
         WHERE entry_date BETWEEN ? AND ?
         ORDER BY entry_date, category_id, id"
    );
    $st->execute([$from, $to]);
    $entries = $st->fetchAll();

    // 預金残高: 表示月より前の入力も含めて返す（日次の実残高を口座ごとに繰り越すため）
    $bSt = $pdo->prepare("
        SELECT bb.id, bb.account_id, a.name AS account_name,
               bb.balance_date, bb.balance, bb.memo
        FROM bank_balances bb
        JOIN bank_accounts a ON a.id = bb.account_id
        WHERE bb.balance_date <= ?
          AND bb.balance_date >= DATE_SUB(?, INTERVAL 10 YEAR)
          AND a.is_active = 1
        ORDER BY bb.balance_date, a.sort_order ASC, a.id ASC
    ");
    $bSt->execute([$to, $from]);
    $balances = $bSt->fetchAll();

    // 当月の全日付リスト
    $days = [];
    $cur = $from;
    while ($cur <= $to) {
        $days[] = $cur;
        $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
    }

    json_ok([
        'categories' => $cats,
        'entries'    => $entries,
        'balances'   => $balances,
        'days'       => $days,
        'year'       => $year,
        'month'      => $month,
    ]);
}

// ============================================================
//  ACTION: 日次明細 保存（INSERT）
// ============================================================
function action_daily_entry_save(array $b): void {
    $pdo        = get_pdo();
    $cat_id     = intval($b['category_id'] ?? 0);
    $date       = preg_replace('/[^0-9\-]/', '', $b['entry_date'] ?? '');
    $entry_type = ($b['entry_type'] ?? '') === 'actual' ? 'actual' : 'plan';
    $amount     = intval($b['amount'] ?? 0);
    $memo       = substr($b['memo'] ?? '', 0, 255);

    if (!$cat_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_error('パラメータ不正');
    }

    $pdo->prepare(
        "INSERT INTO daily_cashflow_entries (category_id, entry_date, entry_type, amount, memo)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$cat_id, $date, $entry_type, $amount, $memo]);

    json_ok(['id' => (int)$pdo->lastInsertId()]);
}

// ============================================================
//  ACTION: 日次明細 更新
// ============================================================
function action_daily_entry_update(array $b): void {
    $pdo    = get_pdo();
    $id     = intval($b['id'] ?? 0);
    $amount = intval($b['amount'] ?? 0);
    $memo   = substr($b['memo'] ?? '', 0, 255);

    if (!$id) json_error('ID不正');

    $pdo->prepare("UPDATE daily_cashflow_entries SET amount=?, memo=? WHERE id=?")
        ->execute([$amount, $memo, $id]);
    json_ok(['affected' => $pdo->query("SELECT ROW_COUNT()")->fetchColumn()]);
}

// ============================================================
//  ACTION: 日次明細 削除
// ============================================================
function action_daily_entry_delete(array $b): void {
    $pdo = get_pdo();
    $id  = intval($b['id'] ?? 0);
    if (!$id) json_error('ID不正');
    $pdo->prepare("DELETE FROM daily_cashflow_entries WHERE id=?")->execute([$id]);
    json_ok();
}
