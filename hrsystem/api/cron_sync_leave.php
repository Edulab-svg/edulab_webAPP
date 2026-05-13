<?php
/**
 * 有給残日数 kintone 一括同期バッチ
 *
 * 全在籍社員の有給残日数を calcAvail（JS と同一ロジック）で計算し、
 * kintone【マスタ】社員の「有給残日数」フィールドを一括更新する。
 *
 * ── 実行方法 ────────────────────────────────────────────────────────
 * [CLI / cron]
 *   php /home/xs047468/xs047468.xsrv.jp/public_html/hrsystem/api/cron_sync_leave.php
 *
 * xServer の cron 設定例（毎日 03:00 実行）:
 *   0 3 * * * /usr/local/bin/php /home/xs047468/xs047468.xsrv.jp/public_html/hrsystem/api/cron_sync_leave.php >> /home/xs047468/cron_leave.log 2>&1
 *
 * [Web 経由 / 手動テスト]
 *   URL に ?token=<CRON_SECRET_TOKEN> を付けてアクセス
 *   例: https://xs047468.xsrv.jp/hrsystem/api/cron_sync_leave.php?token=xxxx
 * ──────────────────────────────────────────────────────────────────────
 */

// ── Web アクセス時のシークレットトークン（変更してください） ──────────────
define('CRON_SECRET_TOKEN', 'gy67%rfhnnjKK');

// ── アクセス制御 ───────────────────────────────────────────────────
$is_cli = (PHP_SAPI === 'cli');
if (!$is_cli) {
    $token = $_GET['token'] ?? '';
    if (!hash_equals(CRON_SECRET_TOKEN, $token)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/config.php';

// ── ログ出力ヘルパー ───────────────────────────────────────────────
function log_line(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
}

// ══════════════════════════════════════════════════════════════════════
// 有給残日数計算ロジック（index.html の JS と同一仕様）
// ══════════════════════════════════════════════════════════════════════

const LEAVE_TABLE = [
    'full' => [
        ['m' =>  6, 'd' => 10],
        ['m' => 18, 'd' => 11],
        ['m' => 30, 'd' => 12],
        ['m' => 42, 'd' => 14],
        ['m' => 54, 'd' => 16],
        ['m' => 66, 'd' => 18],
        ['m' => 78, 'd' => 20],
    ],
    'part' => [
        4 => [['m' =>  6, 'd' => 7],['m' => 18, 'd' => 8],['m' => 30, 'd' => 9],['m' => 42, 'd' => 10],['m' => 54, 'd' => 12],['m' => 66, 'd' => 13],['m' => 78, 'd' => 15]],
        3 => [['m' =>  6, 'd' => 5],['m' => 18, 'd' => 6],['m' => 30, 'd' => 6],['m' => 42, 'd' =>  8],['m' => 54, 'd' =>  9],['m' => 66, 'd' => 10],['m' => 78, 'd' => 11]],
        2 => [['m' =>  6, 'd' => 3],['m' => 18, 'd' => 4],['m' => 30, 'd' => 4],['m' => 42, 'd' =>  5],['m' => 54, 'd' =>  6],['m' => 66, 'd' =>  6],['m' => 78, 'd' =>  7]],
        1 => [['m' =>  6, 'd' => 1],['m' => 18, 'd' => 2],['m' => 30, 'd' => 2],['m' => 42, 'd' =>  2],['m' => 54, 'd' =>  3],['m' => 66, 'd' =>  3],['m' => 78, 'd' =>  3]],
    ],
];

/**
 * 勤続月数を返す（JS の diffMonths と同一）
 */
function diff_months(string $hire_date): int
{
    $h = new DateTime($hire_date);
    $n = new DateTime('today');
    $months = ($n->format('Y') - $h->format('Y')) * 12
            + ($n->format('n') - $h->format('n'));
    if ((int)$n->format('j') < (int)$h->format('j')) {
        $months--;
    }
    return $months;
}

/**
 * 週労働日数に対応する付与テーブルを返す
 */
function get_table(int $wdpw): array
{
    return $wdpw >= 5
        ? LEAVE_TABLE['full']
        : (LEAVE_TABLE['part'][$wdpw] ?? LEAVE_TABLE['part'][1]);
}

/**
 * 付与バッチ一覧を返す（JS の calcGrants と同一）
 * 各バッチ: ['days' => int, 'grant_date' => DateTime, 'expire_date' => DateTime]
 */
function calc_grants(string $hire_date, int $wdpw): array
{
    $dm  = diff_months($hire_date);
    $tbl = get_table($wdpw);

    $batches = [];
    foreach ($tbl as $row) {
        if ($dm < $row['m']) {
            continue;
        }
        $gd = new DateTime($hire_date);
        $gd->modify("+{$row['m']} month");

        $ed = clone $gd;
        $ed->modify('+2 year');

        $batches[] = [
            'days'        => $row['d'],
            'grant_date'  => $gd,
            'expire_date' => $ed,
        ];
    }
    return $batches;
}

/**
 * 有給残日数を返す（JS の calcAvail と同一）
 *
 * @param string $hire_date  'YYYY-MM-DD'
 * @param int    $wdpw       週労働日数
 * @param array  $leaves     DB の leave_records 行の配列（start_date, days）
 */
function calc_avail(string $hire_date, int $wdpw, array $leaves): float
{
    $now = new DateTime('today');

    // 付与バッチ（各バッチの残日数は mutable）
    $raw_batches = calc_grants($hire_date, $wdpw);
    $batches = array_map(fn($g) => [
        'remaining'   => (float)$g['days'],
        'grant_date'  => $g['grant_date'],
        'expire_date' => $g['expire_date'],
    ], $raw_batches);

    // 取得記録を昇順ソート
    usort($leaves, fn($a, $b) => strcmp($a['start_date'], $b['start_date']));

    foreach ($leaves as $leaf) {
        $to_consume = (float)$leaf['days'];
        $leaf_date  = new DateTime($leaf['start_date']);

        // まず付与日〜有効期限内のバッチから消費（古い順）
        foreach ($batches as &$b) {
            if ($to_consume <= 0) break;
            if ($b['grant_date'] <= $leaf_date
                && $b['expire_date'] > $leaf_date
                && $b['remaining'] > 0
            ) {
                $consume       = min($b['remaining'], $to_consume);
                $b['remaining'] -= $consume;
                $to_consume    -= $consume;
            }
        }
        unset($b);

        // 期間外消費分はまだ有効な任意バッチから充当
        if ($to_consume > 0) {
            foreach ($batches as &$b) {
                if ($to_consume <= 0) break;
                if ($b['expire_date'] > $now && $b['remaining'] > 0) {
                    $consume       = min($b['remaining'], $to_consume);
                    $b['remaining'] -= $consume;
                    $to_consume    -= $consume;
                }
            }
            unset($b);
        }
    }

    $total = 0.0;
    foreach ($batches as $b) {
        if ($b['expire_date'] > $now) {
            $total += $b['remaining'];
        }
    }
    return max(0.0, $total);
}

// ══════════════════════════════════════════════════════════════════════
// メイン処理
// ══════════════════════════════════════════════════════════════════════

log_line('=== 有給残日数 kintone 同期バッチ 開始 ===');

try {
    $pdo = get_pdo();

    // ── 1. 全在籍社員を取得 ─────────────────────────────────────
    $emps = $pdo->query(
        'SELECT id, name, hire_date, work_days_per_week
           FROM employees
          WHERE retired = 0
          ORDER BY id ASC'
    )->fetchAll();

    if (empty($emps)) {
        log_line('在籍社員が0件のため終了');
        exit(0);
    }
    log_line('在籍社員: ' . count($emps) . ' 名');

    // ── 2. 全社員の取得記録をまとめて取得 ──────────────────────
    $ids = array_column($emps, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT employee_id, start_date, days
           FROM leave_records
          WHERE employee_id IN ($ph)
          ORDER BY start_date ASC"
    );
    $stmt->execute($ids);
    $leaves_map = [];
    foreach ($stmt->fetchAll() as $row) {
        $leaves_map[$row['employee_id']][] = $row;
    }

    // ── 3. kintone から全レコードを取得（氏名 → レコードID のマップ）
    log_line('kintone レコード一覧取得中...');
    $kintone_map = [];  // name => record_id
    $offset = 0;
    while (true) {
        $res = kintone_request('GET', 'records.json', [
            'app'    => KINTONE_APP_ID,
            'fields' => [KINTONE_NAME_FIELD, '$id'],
            'query'  => 'limit 500 offset ' . $offset,
        ]);
        if (isset($res['_error'])) {
            log_line('ERROR: kintone レコード取得失敗 - ' . $res['_error']);
            exit(1);
        }
        $records = $res['records'] ?? [];
        foreach ($records as $rec) {
            $name      = trim($rec[KINTONE_NAME_FIELD]['value'] ?? '');
            $record_id = (int)($rec['$id']['value'] ?? 0);
            if ($name !== '' && $record_id > 0) {
                $kintone_map[$name] = $record_id;
            }
        }
        if (count($records) < 500) break;
        $offset += 500;
    }
    log_line('kintone レコード: ' . count($kintone_map) . ' 件取得');

    // ── 4. 各社員の残日数を計算して kintone を更新 ─────────────
    $ok_count  = 0;
    $err_count = 0;
    $skip_count = 0;

    foreach ($emps as $emp) {
        $emp_id  = (int)$emp['id'];
        $name    = $emp['name'];
        $leaves  = $leaves_map[$emp_id] ?? [];

        $remaining = calc_avail($emp['hire_date'], (int)$emp['work_days_per_week'], $leaves);

        if (!isset($kintone_map[$name])) {
            log_line("  SKIP [{$name}] kintone にレコードなし");
            $skip_count++;
            continue;
        }

        $record_id = $kintone_map[$name];
        $res = kintone_request('PUT', 'record.json', [], [
            'app'    => KINTONE_APP_ID,
            'id'     => $record_id,
            'record' => [
                KINTONE_LEAVE_FIELD => ['value' => $remaining],
            ],
        ]);

        if (isset($res['_error']) || isset($res['message'])) {
            $err_msg = $res['_error'] ?? $res['message'] ?? 'unknown';
            log_line("  ERROR [{$name}] {$err_msg}");
            $err_count++;
        } else {
            log_line("  OK    [{$name}] 残 {$remaining} 日 → kintone record #{$record_id}");
            $ok_count++;
        }
    }

    log_line('=== 完了 ===');
    log_line("  更新成功: {$ok_count} 件 / エラー: {$err_count} 件 / スキップ: {$skip_count} 件");

} catch (Throwable $e) {
    log_line('FATAL: ' . $e->getMessage());
    exit(1);
}
