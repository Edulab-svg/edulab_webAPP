<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

function json_ok($data = null): void {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function post_str(string $key, string $default = ''): string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}
function post_int(string $key, int $default = 0): int {
    return isset($_POST[$key]) ? (int)$_POST[$key] : $default;
}


/** salary_reviews に業務手当関連カラムが無ければ追加（案A・昇給審査） */
function ensure_salary_review_extended_columns(PDO $pdo): void {
    $need = ['raise_business_amount', 'business_before', 'business_after'];
    foreach ($need as $col) {
        $qcol = $pdo->quote($col);
        $n = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salary_reviews'
               AND COLUMN_NAME = $qcol"
        )->fetchColumn();
        if ($n > 0) {
            continue;
        }
        if ($col === 'raise_business_amount') {
            $pdo->exec('ALTER TABLE salary_reviews ADD COLUMN raise_business_amount INT NOT NULL DEFAULT 0');
        } elseif ($col === 'business_before') {
            $pdo->exec('ALTER TABLE salary_reviews ADD COLUMN business_before INT NULL DEFAULT NULL');
        } else {
            $pdo->exec('ALTER TABLE salary_reviews ADD COLUMN business_after INT NULL DEFAULT NULL');
        }
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = ($method === 'POST') ? post_str('action') : ($_GET['action'] ?? '');

try {
    $pdo = get_pdo();

    // ══ GET ══════════════════════════════════════════════
    if ($method === 'GET') {

        // 在籍社員一覧（retired=0）— 役職・給与情報を JOIN
        if ($action === 'get_employees') {
            // commute_cycle / commute_months は migration 済みかどうか動的に判定
            $hasCommuteCols = (int)$pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees'
                   AND COLUMN_NAME='commute_cycle'"
            )->fetchColumn() > 0;
            $commuteSelect = $hasCommuteCols
                ? "COALESCE(e.commute_cycle, 'monthly') AS commute_cycle, e.commute_months AS commute_months,"
                : "'monthly' AS commute_cycle, NULL AS commute_months,";

            $emps = $pdo->query(
                "SELECT e.id, e.name, e.hire_date, e.work_days_per_week, e.created_at,
                        COALESCE(e.role_id, 0)             AS role_id,
                        COALESCE(e.base_salary, 0)         AS base_salary,
                        COALESCE(e.business_allowance, 0)  AS business_allowance,
                        COALESCE(e.duty_allowance, 0)      AS duty_allowance,
                        COALESCE(e.commute_allowance, 0)   AS commute_allowance,
                        COALESCE(e.housing_allowance, 0)   AS housing_allowance,
                        COALESCE(e.special_allowance, 0)   AS special_allowance,
                        r.name                             AS role_name,
                        COALESCE(r.role_allowance, 0)      AS role_allowance,
                        COALESCE(r.raise_min, 0)           AS raise_min,
                        COALESCE(r.raise_max, 0)           AS raise_max,
                        COALESCE(r.salary_cap, 0)          AS salary_cap,
                        COALESCE(r.bonus_months, 2)        AS bonus_months,
                        e.salary_note                      AS salary_note,
                        $commuteSelect
                        NULL AS _dummy
                 FROM employees e
                 LEFT JOIN roles r ON e.role_id = r.id
                 WHERE e.retired = 0
                 ORDER BY e.hire_date ASC"
            )->fetchAll();

            $leaves = [];
            if ($ids = array_column($emps, 'id')) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare(
                    "SELECT id, employee_id, start_date, end_date, days, type, memo, registered_at
                     FROM leave_records WHERE employee_id IN ($ph) ORDER BY start_date DESC"
                );
                $stmt->execute($ids);
                foreach ($stmt->fetchAll() as $row) {
                    $leaves[$row['employee_id']][] = $row;
                }

                // 最新の昇給審査を取得
                $stmt = $pdo->prepare(
                    "SELECT sr.employee_id, sr.review_period, sr.approved
                     FROM salary_reviews sr
                     INNER JOIN (
                         SELECT employee_id, MAX(review_period) AS max_period
                         FROM salary_reviews GROUP BY employee_id
                     ) latest ON sr.employee_id = latest.employee_id
                               AND sr.review_period = latest.max_period
                     WHERE sr.employee_id IN ($ph)"
                );
                $stmt->execute($ids);
                $lastReviews = [];
                foreach ($stmt->fetchAll() as $row) {
                    $lastReviews[$row['employee_id']] = $row;
                }
            }

            $result = [];
            foreach ($emps as $e) {
                $e['leaves']               = $leaves[$e['id']] ?? [];
                $lr                        = $lastReviews[$e['id']] ?? null;
                $e['last_review_period']   = $lr ? $lr['review_period'] : null;
                $e['last_review_approved'] = $lr ? (bool)$lr['approved'] : null;
                // 数値キャスト
                $e['role_id']           = (int)$e['role_id'];
                $e['base_salary']       = (int)$e['base_salary'];
                $e['business_allowance'] = (int)$e['business_allowance'];
                $e['duty_allowance']     = (int)$e['duty_allowance'];
                $e['commute_allowance']  = (int)$e['commute_allowance'];
                $e['housing_allowance']  = (int)$e['housing_allowance'];
                $e['special_allowance']  = (int)$e['special_allowance'];
                $e['role_allowance']    = (int)$e['role_allowance'];
                $e['raise_min']         = (int)$e['raise_min'];
                $e['raise_max']         = (int)$e['raise_max'];
                $e['salary_cap']        = (int)$e['salary_cap'];
                $e['bonus_months']      = (int)$e['bonus_months'];
                $e['salary_note']    = $e['salary_note'] ?? null;
                $e['commute_cycle']  = $e['commute_cycle']  ?? 'monthly';
                $e['commute_months'] = $e['commute_months'] ?? null;
                $result[] = $e;
            }
            json_ok($result);
        }

        // 退職者一覧（retired=1）
        if ($action === 'get_retired_employees') {
            $emps = $pdo->query(
                'SELECT id, name, hire_date, work_days_per_week, retire_date, retire_remaining, created_at
                 FROM employees WHERE retired=1 ORDER BY retire_date DESC'
            )->fetchAll();

            $leaves = [];
            if ($ids = array_column($emps, 'id')) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare(
                    "SELECT id, employee_id, start_date, end_date, days, type, memo, registered_at
                     FROM leave_records WHERE employee_id IN ($ph) ORDER BY start_date DESC"
                );
                $stmt->execute($ids);
                foreach ($stmt->fetchAll() as $row) {
                    $leaves[$row['employee_id']][] = $row;
                }
            }
            $result = [];
            foreach ($emps as $e) {
                $e['leaves'] = $leaves[$e['id']] ?? [];
                $result[] = $e;
            }
            json_ok($result);
        }

        // 役職マスタ一覧
        if ($action === 'get_roles') {
            $roles = $pdo->query(
                'SELECT id, name, role_allowance, raise_min, raise_max, salary_cap, bonus_months, sort_order
                 FROM roles ORDER BY sort_order ASC, id ASC'
            )->fetchAll();
            foreach ($roles as &$r) {
                $r['id']             = (int)$r['id'];
                $r['role_allowance'] = (int)$r['role_allowance'];
                $r['raise_min']      = (int)$r['raise_min'];
                $r['raise_max']      = (int)$r['raise_max'];
                $r['salary_cap']     = (int)$r['salary_cap'];
                $r['bonus_months']   = (int)$r['bonus_months'];
                $r['sort_order']     = (int)$r['sort_order'];
            }
            unset($r);
            json_ok($roles);
        }

        // 月次給与台帳（年月別）
        if ($action === 'get_monthly_salaries') {
            $ym = $_GET['year_month'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) json_err('年月が不正です');
            $stmt = $pdo->prepare(
                "SELECT e.id, e.name,
                        COALESCE(e.role_id, 0)           AS role_id,
                        COALESCE(e.base_salary, 0)       AS base_salary,
                        COALESCE(e.business_allowance, 0) AS business_allowance,
                        COALESCE(e.duty_allowance, 0)    AS duty_allowance,
                        COALESCE(e.commute_allowance, 0) AS commute_allowance,
                        COALESCE(e.housing_allowance, 0) AS housing_allowance,
                        COALESCE(e.special_allowance, 0) AS special_allowance,
                        e.salary_note,
                        r.name                           AS role_name,
                        COALESCE(r.role_allowance, 0)    AS role_allowance,
                        COALESCE(e.commute_cycle, 'monthly') AS commute_cycle,
                        e.commute_months                 AS commute_months,
                        ms.id                            AS ms_id,
                        ms.commute_allowance             AS ms_commute,
                        ms.special_allowance             AS ms_special,
                        ms.total                         AS ms_total,
                        ms.note                          AS ms_note,
                        COALESCE(ms.excluded, 0)         AS ms_excluded
                 FROM employees e
                 LEFT JOIN roles r ON e.role_id = r.id
                 LEFT JOIN monthly_salaries ms ON ms.employee_id = e.id AND ms.`year_month` = ?
                 WHERE e.retired = 0
                 ORDER BY e.hire_date ASC"
            );
            $stmt->execute([$ym]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$row) {
                $row['id']                = (int)$row['id'];
                $row['base_salary']       = (int)$row['base_salary'];
                $row['business_allowance']= (int)$row['business_allowance'];
                $row['duty_allowance']    = (int)$row['duty_allowance'];
                $row['role_allowance']    = (int)$row['role_allowance'];
                $row['commute_allowance'] = (int)$row['commute_allowance'];
                $row['housing_allowance'] = (int)$row['housing_allowance'];
                $row['special_allowance'] = (int)$row['special_allowance'];
                $row['ms_id']             = $row['ms_id'] ? (int)$row['ms_id'] : null;
                $row['ms_commute']        = $row['ms_commute'] !== null ? (int)$row['ms_commute'] : null;
                $row['ms_special']        = $row['ms_special'] !== null ? (int)$row['ms_special'] : null;
                $row['ms_total']          = $row['ms_total'] !== null ? (int)$row['ms_total'] : null;
                $row['ms_excluded']       = (int)$row['ms_excluded'];
            }
            unset($row);
            json_ok($rows);
        }

        // 昇給審査履歴（社員別）
        if ($action === 'get_salary_reviews') {
            $emp_id = (int)($_GET['employee_id'] ?? 0);
            if ($emp_id <= 0) json_err('社員IDが不正です');
            ensure_salary_review_extended_columns($pdo);
            $stmt = $pdo->prepare(
                'SELECT id, employee_id, review_period, approved, raise_amount, raise_business_amount,
                        salary_before, salary_after, business_before, business_after, note, created_at
                 FROM salary_reviews WHERE employee_id=? ORDER BY review_period DESC'
            );
            $stmt->execute([$emp_id]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$rv) {
                $rv['id']                    = (int)$rv['id'];
                $rv['approved']             = (bool)$rv['approved'];
                $rv['raise_amount']         = (int)$rv['raise_amount'];
                $rv['raise_business_amount']= isset($rv['raise_business_amount']) ? (int)$rv['raise_business_amount'] : 0;
                $rv['salary_before']        = (int)$rv['salary_before'];
                $rv['salary_after']         = (int)$rv['salary_after'];
                $rv['business_before']      = $rv['business_before'] !== null ? (int)$rv['business_before'] : null;
                $rv['business_after']       = $rv['business_after'] !== null ? (int)$rv['business_after'] : null;
            }
            unset($rv);
            json_ok($rows);
        }

        // 賞与台帳（年月別）
        if ($action === 'get_bonus_salaries') {
            $ym = $_GET['year_month'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) json_err('年月が不正です');
            $stmt = $pdo->prepare(
                "SELECT e.id, e.name,
                        COALESCE(e.base_salary, 0)         AS base_salary,
                        COALESCE(e.business_allowance, 0)  AS business_allowance,
                        COALESCE(e.duty_allowance, 0)      AS duty_allowance,
                        r.name                             AS role_name,
                        COALESCE(r.role_allowance, 0)      AS role_allowance,
                        COALESCE(r.bonus_months, 2)        AS bonus_months,
                        bs.id                              AS bs_id,
                        bs.base_amount                     AS bs_base,
                        COALESCE(bs.bonus_rate, 1.0000)    AS bs_rate,
                        bs.adjustment                      AS bs_adj,
                        bs.total                           AS bs_total,
                        bs.note                            AS bs_note,
                        COALESCE(bs.excluded, 0)           AS bs_excluded
                 FROM employees e
                 LEFT JOIN roles r ON e.role_id = r.id
                 LEFT JOIN bonus_salaries bs ON bs.employee_id = e.id AND bs.`year_month` = ?
                 WHERE e.retired = 0
                 ORDER BY e.hire_date ASC"
            );
            $stmt->execute([$ym]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$row) {
                $row['id']           = (int)$row['id'];
                $row['base_salary']  = (int)$row['base_salary'];
                $row['business_allowance'] = (int)$row['business_allowance'];
                $row['duty_allowance']     = (int)$row['duty_allowance'];
                $row['role_allowance']     = (int)$row['role_allowance'];
                $row['bonus_months'] = (int)$row['bonus_months'];
                $row['bs_id']        = $row['bs_id']    ? (int)$row['bs_id']    : null;
                $row['bs_base']      = $row['bs_base']  !== null ? (int)$row['bs_base']  : null;
                $row['bs_rate']      = $row['bs_rate']  !== null ? (float)$row['bs_rate'] : null;
                $row['bs_adj']       = $row['bs_adj']   !== null ? (int)$row['bs_adj']   : null;
                $row['bs_total']     = $row['bs_total'] !== null ? (int)$row['bs_total'] : null;
                $row['bs_excluded']  = (int)$row['bs_excluded'];
            }
            unset($row);
            json_ok($rows);
        }

        json_err('Unknown action', 404);
    }

    // ══ POST ═════════════════════════════════════════════
    if ($method === 'POST') {

        // ── 社員登録 ──────────────────────────────────
        if ($action === 'add_employee') {
            $name = post_str('name');
            $hire = post_str('hire_date');
            $wdpw = post_int('work_days_per_week', 5);

            if ($name === '') json_err('名前は必須です');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire)) json_err('入社日が不正です');
            if ($wdpw < 1 || $wdpw > 5) json_err('労働日数は1〜5で指定してください');

            $stmt = $pdo->prepare(
                'INSERT INTO employees (name, hire_date, work_days_per_week) VALUES (?, ?, ?)'
            );
            $stmt->execute([$name, $hire, $wdpw]);
            json_ok(['id' => (int)$pdo->lastInsertId()]);
        }

        // ── 社員更新 ──────────────────────────────────
        if ($action === 'update_employee') {
            $id   = post_int('id');
            $name = post_str('name');
            $hire = post_str('hire_date');
            $wdpw = post_int('work_days_per_week', 5);

            if ($id <= 0)    json_err('IDが不正です');
            if ($name === '') json_err('名前は必須です');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire)) json_err('入社日が不正です');
            if ($wdpw < 1 || $wdpw > 5) json_err('労働日数は1〜5で指定してください');

            $stmt = $pdo->prepare(
                'UPDATE employees SET name=?, hire_date=?, work_days_per_week=? WHERE id=?'
            );
            $stmt->execute([$name, $hire, $wdpw, $id]);
            json_ok();
        }

        // ── 社員削除 ──────────────────────────────────
        if ($action === 'delete_employee') {
            $id = post_int('id');
            if ($id <= 0) json_err('IDが不正です');
            $stmt = $pdo->prepare('DELETE FROM employees WHERE id=?');
            $stmt->execute([$id]);
            json_ok();
        }

        // ── 退職処理 ──────────────────────────────────
        if ($action === 'retire_employee') {
            $id        = post_int('id');
            $retire_date = post_str('retire_date');
            $retire_remaining = (float)post_str('retire_remaining', '0');

            if ($id <= 0) json_err('IDが不正です');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $retire_date)) json_err('退職日が不正です');

            $stmt = $pdo->prepare(
                'UPDATE employees SET retired=1, retire_date=?, retire_remaining=? WHERE id=?'
            );
            $stmt->execute([$retire_date, $retire_remaining, $id]);
            json_ok();
        }

        // ── 退職取り消し（在籍に戻す）─────────────────
        if ($action === 'unretire_employee') {
            $id = post_int('id');
            if ($id <= 0) json_err('IDが不正です');
            $stmt = $pdo->prepare(
                'UPDATE employees SET retired=0, retire_date=NULL, retire_remaining=NULL WHERE id=?'
            );
            $stmt->execute([$id]);
            json_ok();
        }

        // ── 取得記録追加 ──────────────────────────────
        if ($action === 'add_leave') {
            $emp_id = post_int('employee_id');
            $start  = post_str('start_date');
            $end    = post_str('end_date');
            $days   = (float)post_str('days', '0');
            $type   = post_str('type', 'full');
            $memo   = post_str('memo', '');
            $reg    = date('Y-m-d');

            if ($emp_id <= 0) json_err('社員IDが不正です');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) json_err('開始日が不正です');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   json_err('終了日が不正です');
            if ($days <= 0)  json_err('日数が不正です');
            if (!in_array($type, ['full','half'])) json_err('種別が不正です');

            $stmt = $pdo->prepare(
                'INSERT INTO leave_records
                 (employee_id, start_date, end_date, days, type, memo, registered_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$emp_id, $start, $end, $days, $type, $memo, $reg]);
            json_ok(['id' => (int)$pdo->lastInsertId()]);
        }

        // ── 取得記録削除 ──────────────────────────────
        if ($action === 'delete_leave') {
            $id     = post_int('id');
            $emp_id = post_int('employee_id');
            if ($id <= 0 || $emp_id <= 0) json_err('IDが不正です');
            $stmt = $pdo->prepare(
                'DELETE FROM leave_records WHERE id=? AND employee_id=?'
            );
            $stmt->execute([$id, $emp_id]);
            json_ok();
        }

        // ── kintone 有給残日数同期 ─────────────────────
        if ($action === 'sync_kintone_leave') {
            $emp_id        = post_int('employee_id');
            $remaining     = (float)post_str('remaining_days', '0');

            if ($emp_id <= 0) json_err('社員IDが不正です');

            // DB から社員名を取得
            $stmt = $pdo->prepare('SELECT name FROM employees WHERE id=? AND retired=0');
            $stmt->execute([$emp_id]);
            $emp = $stmt->fetch();
            if (!$emp) json_err('社員が見つかりません');

            $name = $emp['name'];

            // kintone で氏名が一致するレコードを検索
            $query = KINTONE_NAME_FIELD . ' = "' . str_replace('"', '\\"', $name) . '"';
            $res = kintone_request('GET', 'records.json', [
                'app'   => KINTONE_APP_ID,
                'query' => $query,
            ]);

            if (isset($res['_error'])) {
                json_err('kintone検索エラー: ' . $res['_error'], 502);
            }
            if (isset($res['code'])) {
                json_err('kintone APIエラー: ' . ($res['message'] ?? $res['code']), 502);
            }
            if (empty($res['records'])) {
                json_err("kintoneに「{$name}」のレコードが見つかりません", 404);
            }
            if (count($res['records']) > 1) {
                json_err("kintoneに「{$name}」のレコードが複数存在します", 409);
            }

            $record_id = (int)$res['records'][0]['$id']['value'];

            // 有給残日数を更新
            $update = kintone_request('PUT', 'record.json', [], [
                'app'    => KINTONE_APP_ID,
                'id'     => $record_id,
                'record' => [
                    KINTONE_LEAVE_FIELD => ['value' => $remaining],
                ],
            ]);

            if (isset($update['_error'])) {
                json_err('kintone更新エラー: ' . $update['_error'], 502);
            }
            if (isset($update['message'])) {
                json_err('kintoneエラー: ' . $update['message'], 502);
            }

            json_ok(['record_id' => $record_id, 'remaining' => $remaining]);
        }

        // ══════════════════════════════════════════════
        // 給与管理 API
        // ══════════════════════════════════════════════

        // ── 役職追加 ──────────────────────────────────
        if ($action === 'add_role') {
            $name           = post_str('name');
            $role_allowance = post_int('role_allowance');
            $raise_min      = post_int('raise_min');
            $raise_max      = post_int('raise_max');
            $salary_cap     = post_int('salary_cap');
            $bonus_months   = post_int('bonus_months', 2);
            $sort_order     = post_int('sort_order');

            if ($name === '') json_err('役職名は必須です');
            if ($raise_min > $raise_max && $raise_max > 0) json_err('昇給幅の最小が最大を超えています');

            $stmt = $pdo->prepare(
                'INSERT INTO roles (name, role_allowance, raise_min, raise_max, salary_cap, bonus_months, sort_order)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $stmt->execute([$name, $role_allowance, $raise_min, $raise_max, $salary_cap, $bonus_months, $sort_order]);
            json_ok(['id' => (int)$pdo->lastInsertId()]);
        }

        // ── 役職更新 ──────────────────────────────────
        if ($action === 'update_role') {
            $id             = post_int('id');
            $name           = post_str('name');
            $role_allowance = post_int('role_allowance');
            $raise_min      = post_int('raise_min');
            $raise_max      = post_int('raise_max');
            $salary_cap     = post_int('salary_cap');
            $bonus_months   = post_int('bonus_months', 2);
            $sort_order     = post_int('sort_order');

            if ($id <= 0)    json_err('IDが不正です');
            if ($name === '') json_err('役職名は必須です');
            if ($raise_min > $raise_max && $raise_max > 0) json_err('昇給幅の最小が最大を超えています');

            $stmt = $pdo->prepare(
                'UPDATE roles SET name=?, role_allowance=?, raise_min=?, raise_max=?, salary_cap=?, bonus_months=?, sort_order=?
                 WHERE id=?'
            );
            $stmt->execute([$name, $role_allowance, $raise_min, $raise_max, $salary_cap, $bonus_months, $sort_order, $id]);
            json_ok();
        }

        // ── 役職削除 ──────────────────────────────────
        if ($action === 'delete_role') {
            $id = post_int('id');
            if ($id <= 0) json_err('IDが不正です');

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE role_id=?');
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() > 0) {
                json_err('この役職は社員に割り当てられているため削除できません');
            }

            $stmt = $pdo->prepare('DELETE FROM roles WHERE id=?');
            $stmt->execute([$id]);
            json_ok();
        }

        // ── 社員の給与設定を更新 ──────────────────────
        if ($action === 'update_employee_salary') {
            $id               = post_int('id');
            $role_id_raw      = post_str('role_id', '');
            $role_id          = ($role_id_raw !== '' && (int)$role_id_raw > 0) ? (int)$role_id_raw : null;
            $base_salary        = post_int('base_salary');
            $business_allowance = post_int('business_allowance');
            $duty_allowance     = post_int('duty_allowance');
            $commute_allowance  = post_int('commute_allowance');
            $housing_allowance  = post_int('housing_allowance');
            $special_allowance  = post_int('special_allowance');

            $salary_note        = post_str('salary_note');
            $commute_cycle      = post_str('commute_cycle', 'monthly');
            if (!in_array($commute_cycle, ['monthly', 'semi_annual'])) $commute_cycle = 'monthly';
            $commute_months_raw = post_str('commute_months', '');
            // 数字のカンマ区切りのみ許可（例: "4,10"）
            $commute_months     = preg_replace('/[^0-9,]/', '', $commute_months_raw) ?: null;

            if ($id <= 0) json_err('IDが不正です');

            $stmt = $pdo->prepare(
                'UPDATE employees SET role_id=?, base_salary=?, business_allowance=?,
                 duty_allowance=?, commute_allowance=?, housing_allowance=?, special_allowance=?,
                 salary_note=?, commute_cycle=?, commute_months=? WHERE id=?'
            );
            $stmt->execute([$role_id, $base_salary, $business_allowance,
                            $duty_allowance, $commute_allowance, $housing_allowance, $special_allowance,
                            $salary_note ?: null, $commute_cycle, $commute_months, $id]);
            json_ok();
        }

        // ── 月次給与を一括生成（INSERT IGNORE で既存レコードは保持）──
        if ($action === 'generate_monthly') {
            $ym = post_str('year_month');
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) json_err('年月が不正です');

            // 必要なカラムの存在チェック
            $required = ['base_salary','business_allowance','duty_allowance',
                         'commute_allowance','housing_allowance','special_allowance'];
            $chk = $pdo->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME IN ("
                . implode(',', array_fill(0, count($required), '?')) . ")"
            );
            $chk->execute($required);
            $found = $chk->fetchAll(PDO::FETCH_COLUMN);
            $missing = array_diff($required, $found);
            if ($missing) {
                json_err('給与カラムが未追加です: ' . implode(', ', $missing)
                    . ' — salary_migration.sql を再実行してください。');
            }

            // monthly_salaries テーブルの存在チェック
            $tblChk = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monthly_salaries'"
            )->fetchColumn();
            if (!$tblChk) {
                json_err('monthly_salaries テーブルが存在しません — salary_migration.sql を再実行してください。');
            }

            $emps = $pdo->query(
                "SELECT e.id, e.base_salary, e.business_allowance, e.duty_allowance,
                        e.commute_allowance, e.housing_allowance, e.special_allowance,
                        COALESCE(e.commute_cycle, 'monthly')  AS commute_cycle,
                        e.commute_months,
                        COALESCE(r.role_allowance, 0) AS role_allowance
                 FROM employees e
                 LEFT JOIN roles r ON e.role_id = r.id
                 WHERE e.retired = 0"
            )->fetchAll();

            // 対象月の「月」番号（半年払い判定用）
            $targetMonth = (int)substr($ym, 5, 2);

            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO monthly_salaries
                 (employee_id, `year_month`, base_salary, business_allowance, duty_allowance,
                  role_allowance, commute_allowance, housing_allowance, special_allowance, total)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $count = 0;
            foreach ($emps as $e) {
                // 半年払いかつ非支給月は交通費 0
                $commute = (int)$e['commute_allowance'];
                if ($e['commute_cycle'] === 'semi_annual' && $e['commute_months']) {
                    $payMonths = array_map('intval', explode(',', $e['commute_months']));
                    if (!in_array($targetMonth, $payMonths)) $commute = 0;
                }
                $total = $e['base_salary'] + $e['business_allowance'] + $e['duty_allowance']
                       + $e['role_allowance'] + $commute + $e['housing_allowance']
                       + $e['special_allowance'];
                $stmt->execute([
                    $e['id'], $ym, $e['base_salary'], $e['business_allowance'], $e['duty_allowance'],
                    $e['role_allowance'], $commute, $e['housing_allowance'],
                    $e['special_allowance'], $total
                ]);
                if ($stmt->rowCount() > 0) $count++;
            }
            json_ok(['generated' => $count, 'total' => count($emps)]);
        }

        // ── 月次台帳を一括削除 ────────────────────────
        if ($action === 'delete_monthly_all') {
            $ym = post_str('year_month');
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) json_err('年月が不正です');
            $stmt = $pdo->prepare('DELETE FROM monthly_salaries WHERE `year_month`=?');
            $stmt->execute([$ym]);
            json_ok(['deleted' => $stmt->rowCount()]);
        }

        // ── 社員を月次台帳から除外 / 復元 ────────────────
        if ($action === 'set_monthly_excluded') {
            $employee_id = post_int('employee_id');
            $ym          = post_str('year_month');
            $excluded    = post_int('excluded'); // 0=復元 1=除外

            if ($employee_id <= 0) json_err('社員IDが不正です');
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) json_err('年月が不正です');
            if (!in_array($excluded, [0, 1])) json_err('除外フラグが不正です');

            $check = $pdo->prepare(
                'SELECT id FROM monthly_salaries WHERE employee_id=? AND `year_month`=?'
            );
            $check->execute([$employee_id, $ym]);
            $existing = $check->fetch();

            if ($existing) {
                $pdo->prepare('UPDATE monthly_salaries SET excluded=? WHERE employee_id=? AND `year_month`=?')
                    ->execute([$excluded, $employee_id, $ym]);
            } else {
                // 未生成の場合はベース給与からレコードを作成して除外
                $emp = $pdo->prepare(
                    "SELECT e.base_salary, e.business_allowance, e.duty_allowance,
                            e.commute_allowance, e.housing_allowance, e.special_allowance,
                            COALESCE(r.role_allowance,0) AS role_allowance
                     FROM employees e LEFT JOIN roles r ON e.role_id=r.id WHERE e.id=?"
                );
                $emp->execute([$employee_id]);
                $e = $emp->fetch();
                if (!$e) json_err('社員が見つかりません');
                $total = $e['base_salary'] + $e['business_allowance'] + $e['duty_allowance']
                       + $e['role_allowance'] + $e['commute_allowance'] + $e['housing_allowance']
                       + $e['special_allowance'];
                $pdo->prepare(
                    'INSERT INTO monthly_salaries
                     (employee_id, `year_month`, base_salary, business_allowance, duty_allowance,
                      role_allowance, commute_allowance, housing_allowance, special_allowance, total, excluded)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $employee_id, $ym, $e['base_salary'], $e['business_allowance'], $e['duty_allowance'],
                    $e['role_allowance'], $e['commute_allowance'], $e['housing_allowance'],
                    $e['special_allowance'], $total, $excluded
                ]);
            }
            json_ok(['excluded' => $excluded]);
        }

        // ── 月次給与を保存（特別手当・備考のみ編集可）──
        if ($action === 'save_monthly_salary') {
            $employee_id       = post_int('employee_id');
            $ym                = post_str('year_month');
            $special_allowance = post_int('special_allowance');
            $note              = post_str('note');

            if ($employee_id <= 0) json_err('社員IDが不正です');
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) json_err('年月が不正です');

            $check = $pdo->prepare(
                'SELECT id, base_salary, business_allowance, duty_allowance,
                        role_allowance, commute_allowance, housing_allowance
                 FROM monthly_salaries WHERE employee_id=? AND `year_month`=?'
            );
            $check->execute([$employee_id, $ym]);
            $existing = $check->fetch();

            if ($existing) {
                $total = $existing['base_salary'] + $existing['business_allowance']
                       + $existing['duty_allowance'] + $existing['role_allowance']
                       + $existing['commute_allowance'] + $existing['housing_allowance']
                       + $special_allowance;
                $upd = $pdo->prepare(
                    'UPDATE monthly_salaries SET special_allowance=?, total=?, note=?
                     WHERE employee_id=? AND `year_month`=?'
                );
                $upd->execute([$special_allowance, $total, $note ?: null, $employee_id, $ym]);
            } else {
                $emp = $pdo->prepare(
                    "SELECT e.base_salary, e.business_allowance, e.duty_allowance,
                            e.commute_allowance, e.housing_allowance,
                            COALESCE(r.role_allowance,0) AS role_allowance
                     FROM employees e LEFT JOIN roles r ON e.role_id=r.id WHERE e.id=?"
                );
                $emp->execute([$employee_id]);
                $e = $emp->fetch();
                if (!$e) json_err('社員が見つかりません');
                $total = $e['base_salary'] + $e['business_allowance'] + $e['duty_allowance']
                       + $e['role_allowance'] + $e['commute_allowance'] + $e['housing_allowance']
                       + $special_allowance;
                $ins = $pdo->prepare(
                    'INSERT INTO monthly_salaries
                     (employee_id, `year_month`, base_salary, business_allowance, duty_allowance,
                      role_allowance, commute_allowance, housing_allowance, special_allowance, total, note)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
                $ins->execute([
                    $employee_id, $ym, $e['base_salary'], $e['business_allowance'], $e['duty_allowance'],
                    $e['role_allowance'], $e['commute_allowance'], $e['housing_allowance'],
                    $special_allowance, $total, $note ?: null
                ]);
            }
            json_ok();
        }

        // ── 月次台帳の固定部分を給与テーブルから再取得して上書き ──
        if ($action === 'refresh_monthly_base') {
            $ym = post_str('year_month');
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) json_err('年月が不正です');

            // 対象月の既存レコードを現在の給与テーブルで上書き（special_allowance・note は保持）
            $targetMonth = (int)substr($ym, 5, 2);
            $rows = $pdo->prepare(
                "SELECT ms.id, ms.special_allowance,
                        e.base_salary, e.business_allowance, e.duty_allowance,
                        e.commute_allowance, e.housing_allowance,
                        COALESCE(e.commute_cycle, 'monthly') AS commute_cycle,
                        e.commute_months,
                        COALESCE(r.role_allowance, 0) AS role_allowance
                 FROM monthly_salaries ms
                 JOIN employees e ON ms.employee_id = e.id
                 LEFT JOIN roles r ON e.role_id = r.id
                 WHERE ms.`year_month` = ?"
            );
            $rows->execute([$ym]);
            $records = $rows->fetchAll();

            $upd = $pdo->prepare(
                "UPDATE monthly_salaries
                 SET base_salary=?, business_allowance=?, duty_allowance=?,
                     role_allowance=?, commute_allowance=?, housing_allowance=?, total=?
                 WHERE id=?"
            );
            foreach ($records as $rec) {
                $commute = (int)$rec['commute_allowance'];
                if ($rec['commute_cycle'] === 'semi_annual' && $rec['commute_months']) {
                    $payMonths = array_map('intval', explode(',', $rec['commute_months']));
                    if (!in_array($targetMonth, $payMonths)) $commute = 0;
                }
                $total = $rec['base_salary'] + $rec['business_allowance'] + $rec['duty_allowance']
                       + $rec['role_allowance'] + $commute + $rec['housing_allowance']
                       + $rec['special_allowance'];
                $upd->execute([
                    $rec['base_salary'], $rec['business_allowance'], $rec['duty_allowance'],
                    $rec['role_allowance'], $commute, $rec['housing_allowance'],
                    $total, $rec['id']
                ]);
            }
            json_ok(['updated' => count($records)]);
        }

        // ── 昇給審査を記録 ────────────────────────────
        if ($action === 'add_salary_review') {
            ensure_salary_review_extended_columns($pdo);

            $employee_id           = post_int('employee_id');
            $review_period         = post_str('review_period');
            $approved              = post_int('approved');
            $raise_amount          = post_int('raise_amount');
            $raise_business_amount = post_int('raise_business_amount');
            $salary_before         = post_int('salary_before');
            $salary_after          = post_int('salary_after');
            $business_before       = post_int('business_before');
            $business_after        = post_int('business_after');
            $note                  = post_str('note');

            if ($employee_id <= 0) json_err('社員IDが不正です');
            if (!preg_match('/^\d{4}-\d{2}$/', $review_period)) json_err('審査期間が不正です（YYYY-MM形式）');
            if ($approved && $salary_cap_check = $pdo->prepare(
                'SELECT COALESCE(r.salary_cap,0) FROM employees e LEFT JOIN roles r ON e.role_id=r.id WHERE e.id=?'
            )) {
                $salary_cap_check->execute([$employee_id]);
                $cap = (int)$salary_cap_check->fetchColumn();
                if ($cap > 0 && $salary_after > $cap) json_err("基本給が上限（{$cap}円）を超えています");
            }

            // 同一期間の重複チェック
            $stmt = $pdo->prepare(
                'SELECT id FROM salary_reviews WHERE employee_id=? AND review_period=?'
            );
            $stmt->execute([$employee_id, $review_period]);
            if ($stmt->fetch()) json_err('この審査期間はすでに記録されています');

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO salary_reviews
                     (employee_id, review_period, approved, raise_amount, raise_business_amount,
                      salary_before, salary_after, business_before, business_after, note)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $employee_id, $review_period, $approved,
                    $raise_amount, $raise_business_amount,
                    $salary_before, $salary_after, $business_before, $business_after, $note
                ]);

                if ($approved && ($raise_amount > 0 || $raise_business_amount > 0)) {
                    $stmt = $pdo->prepare(
                        'UPDATE employees SET base_salary=?, business_allowance=? WHERE id=?'
                    );
                    $stmt->execute([$salary_after, $business_after, $employee_id]);
                }

                $pdo->commit();
                json_ok(['id' => (int)$pdo->lastInsertId()]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // ── 昇給審査の修正 ────────────────────────────
        if ($action === 'update_salary_review') {
            ensure_salary_review_extended_columns($pdo);

            $id                    = post_int('id');
            $employee_id_post      = post_int('employee_id');
            $approved              = post_int('approved');
            $raise_amount          = post_int('raise_amount');
            $raise_business_amount = post_int('raise_business_amount');
            $note                  = post_str('note');

            if ($id <= 0) {
                json_err('記録IDが不正です');
            }
            if ($employee_id_post <= 0) {
                json_err('社員IDが不正です');
            }

            $stmt = $pdo->prepare(
                'SELECT id, employee_id, review_period, salary_before, business_before
                 FROM salary_reviews WHERE id=?'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                json_err('記録が見つかりません');
            }
            $employee_id = (int)$row['employee_id'];
            if ($employee_id !== $employee_id_post) {
                json_err('社員が記録と一致しません');
            }

            $salary_before   = (int)$row['salary_before'];
            $business_before = $row['business_before'] !== null ? (int)$row['business_before'] : 0;

            if (!$approved) {
                $raise_amount          = 0;
                $raise_business_amount = 0;
                $salary_after          = $salary_before;
                $business_after        = $business_before;
            } else {
                $salary_after   = $salary_before + $raise_amount;
                $business_after = $business_before + $raise_business_amount;
            }

            if ($approved && $salary_cap_check = $pdo->prepare(
                'SELECT COALESCE(r.salary_cap,0) FROM employees e LEFT JOIN roles r ON e.role_id=r.id WHERE e.id=?'
            )) {
                $salary_cap_check->execute([$employee_id]);
                $cap = (int)$salary_cap_check->fetchColumn();
                if ($cap > 0 && $salary_after > $cap) {
                    json_err("基本給が上限（{$cap}円）を超えています");
                }
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'UPDATE salary_reviews SET approved=?, raise_amount=?, raise_business_amount=?,
                     salary_after=?, business_after=?, note=? WHERE id=?'
                );
                $stmt->execute([
                    $approved, $raise_amount, $raise_business_amount,
                    $salary_after, $business_after, $note ?: null, $id,
                ]);

                $maxStmt = $pdo->prepare(
                    'SELECT MAX(review_period) FROM salary_reviews WHERE employee_id=?'
                );
                $maxStmt->execute([$employee_id]);
                $maxPeriod = $maxStmt->fetchColumn();
                if ($maxPeriod !== null && (string)$row['review_period'] === (string)$maxPeriod) {
                    $upd = $pdo->prepare(
                        'UPDATE employees SET base_salary=?, business_allowance=? WHERE id=?'
                    );
                    $upd->execute([$salary_after, $business_after, $employee_id]);
                }

                $pdo->commit();
                json_ok(['updated' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // ════════════════════════════════════════════════
        // 賞与台帳
        // ════════════════════════════════════════════════

        // ── 賞与一括生成 ──────────────────────────────────
        if ($action === 'generate_bonus') {
            $ym           = post_str('year_month');
            $mult_raw     = post_str('multiplier', '');
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) json_err('年月が不正です');

            $emps = $pdo->query(
                "SELECT e.id,
                        COALESCE(e.base_salary, 0)        AS base_salary,
                        COALESCE(e.business_allowance, 0) AS business_allowance,
                        COALESCE(e.duty_allowance, 0)     AS duty_allowance,
                        COALESCE(e.commute_allowance, 0)  AS commute_allowance,
                        COALESCE(e.housing_allowance, 0)  AS housing_allowance,
                        COALESCE(e.special_allowance, 0)  AS special_allowance,
                        COALESCE(r.role_allowance, 0)     AS role_allowance,
                        COALESCE(r.bonus_months, 2)       AS bonus_months
                 FROM employees e
                 LEFT JOIN roles r ON e.role_id = r.id
                 WHERE e.retired = 0"
            )->fetchAll();

            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO bonus_salaries
                 (employee_id, `year_month`, base_amount, bonus_rate, adjustment, total)
                 VALUES (?,?,?,1.0000,0,?)'
            );
            $count = 0;
            foreach ($emps as $e) {
                // 役職ごとの賞与月数（指定があれば上書き）
                $mult = ($mult_raw !== '' && is_numeric($mult_raw))
                    ? (float)$mult_raw
                    : $e['bonus_months'] / 2;
                $basis = $e['base_salary'] + $e['business_allowance'] + $e['duty_allowance']
                       + $e['role_allowance'];
                $base = (int)round($basis * $mult);
                $stmt->execute([$e['id'], $ym, $base, $base]);
                if ($stmt->rowCount() > 0) $count++;
            }
            json_ok(['generated' => $count, 'total' => count($emps)]);
        }

        // ── 賞与個別保存 ──────────────────────────────────
        if ($action === 'save_bonus_salary') {
            $employee_id = post_int('employee_id');
            $ym          = post_str('year_month');
            $bonus_rate  = isset($_POST['bonus_rate']) ? (float)$_POST['bonus_rate'] : 1.0;
            $adjustment  = post_int('adjustment');
            $note        = post_str('note');

            if ($employee_id <= 0) json_err('社員IDが不正です');
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) json_err('年月が不正です');
            if ($bonus_rate < 0) json_err('掛け率は0以上で入力してください');

            $check = $pdo->prepare(
                'SELECT id, base_amount FROM bonus_salaries WHERE employee_id=? AND `year_month`=?'
            );
            $check->execute([$employee_id, $ym]);
            $existing = $check->fetch();

            if ($existing) {
                $total = (int)round($existing['base_amount'] * $bonus_rate) + $adjustment;
                $pdo->prepare(
                    'UPDATE bonus_salaries SET bonus_rate=?, adjustment=?, total=?, note=? WHERE id=?'
                )->execute([$bonus_rate, $adjustment, $total, $note ?: null, $existing['id']]);
            } else {
                json_err('先に一括生成を実行してください');
            }
            json_ok();
        }

        // ── 賞与除外 / 復元 ──────────────────────────────
        if ($action === 'set_bonus_excluded') {
            $employee_id = post_int('employee_id');
            $ym          = post_str('year_month');
            $excluded    = post_int('excluded');

            if ($employee_id <= 0) json_err('社員IDが不正です');
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) json_err('年月が不正です');
            if (!in_array($excluded, [0, 1])) json_err('除外フラグが不正です');

            $check = $pdo->prepare(
                'SELECT id FROM bonus_salaries WHERE employee_id=? AND `year_month`=?'
            );
            $check->execute([$employee_id, $ym]);
            $existing = $check->fetch();
            if (!$existing) json_err('先に一括生成を実行してください');

            $pdo->prepare('UPDATE bonus_salaries SET excluded=? WHERE id=?')
                ->execute([$excluded, $existing['id']]);
            json_ok(['excluded' => $excluded]);
        }

        // ── 賞与一括削除 ─────────────────────────────────
        if ($action === 'delete_bonus_all') {
            $ym = post_str('year_month');
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) json_err('年月が不正です');
            $stmt = $pdo->prepare('DELETE FROM bonus_salaries WHERE `year_month`=?');
            $stmt->execute([$ym]);
            json_ok(['deleted' => $stmt->rowCount()]);
        }

        json_err('Unknown action', 404);
    }

    json_err('Method not allowed', 405);

} catch (PDOException $e) {
    json_err('DB Error: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    json_err($e->getMessage(), 500);
}
