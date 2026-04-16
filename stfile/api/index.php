<?php
// ============================================================
// まんてん個別プラス 学習進捗管理システム - API
// GET  ?action=XXX  / POST JSON body {action:"XXX", ...}
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/config.php';

function respond(mixed $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function err(string $msg, int $status = 400): void {
    respond(['error' => $msg], $status);
}

// ---------- NULL変換ヘルパー ----------
function nullIfEmpty(mixed $v): ?string {
    if ($v === null || $v === '' || $v === 0) return null;
    return (string)$v;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
        handleGet($action);
    } elseif ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? '';
        handlePost($action, $body);
    } else {
        err('Method not allowed', 405);
    }
} catch (Throwable $e) {
    err($e->getMessage().' [line '.$e->getLine().'] '.$e->getCode(), 500);
}

// ============================================================
// GET ハンドラ
// ============================================================
function autoMigrate($db): void {
    // SHOW COLUMNS で安全に確認してから ALTER TABLE
    // school_exams に nendo/target_grade カラムを追加
    try {
        $examCols = $db->query('SHOW COLUMNS FROM school_exams')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('nendo', $examCols)) {
            $db->exec("ALTER TABLE school_exams ADD COLUMN nendo VARCHAR(10) DEFAULT NULL");
        }
        if (!in_array('target_grade', $examCols)) {
            $db->exec("ALTER TABLE school_exams ADD COLUMN target_grade VARCHAR(20) DEFAULT NULL");
        }
        if (!in_array('tbd', $examCols)) {
            $db->exec("ALTER TABLE school_exams ADD COLUMN tbd TINYINT(1) DEFAULT 0");
        }
    } catch (Throwable $ignored) {}

    try {
        $cols = $db->query('SHOW COLUMNS FROM students')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('subjects', $cols)) {
            $db->exec("ALTER TABLE students ADD COLUMN subjects TEXT DEFAULT '[]'");
        }
        if (!in_array('math_level', $cols)) {
            $db->exec('ALTER TABLE students ADD COLUMN math_level TINYINT DEFAULT 3');
        }
        if (!in_array('subject_levels', $cols)) {
            $db->exec("ALTER TABLE students ADD COLUMN subject_levels TEXT DEFAULT '{}'");
        }
    } catch (Throwable $ignored) {}

    // classroomsテーブル（教室専用）を新設
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS classrooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $ignored) {}

    // studentsにclassroom_idカラムを追加（教室ID専用）
    try {
        $stCols = $db->query('SHOW COLUMNS FROM students')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('classroom_id', $stCols)) {
            $db->exec('ALTER TABLE students ADD COLUMN classroom_id INT DEFAULT NULL');
        }
    } catch (Throwable $ignored) {}

    // excluded_periods カラム（学校ごとの除外期間）
    try {
        $schCols = $db->query('SHOW COLUMNS FROM schools')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('excluded_periods', $schCols)) {
            $db->exec("ALTER TABLE schools ADD COLUMN excluded_periods TEXT DEFAULT '[]'");
        }
    } catch (Throwable $ignored) {}

    // exam_results カラム
    try {
        $stColsE = $db->query('SHOW COLUMNS FROM students')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('exam_results', $stColsE)) {
            $db->exec("ALTER TABLE students ADD COLUMN exam_results TEXT DEFAULT '[]'");
        }
    } catch (Throwable $ignored) {}

    // furigana カラム
    try {
        $stCols3 = $db->query('SHOW COLUMNS FROM students')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('furigana', $stCols3)) {
            $db->exec("ALTER TABLE students ADD COLUMN furigana VARCHAR(255) DEFAULT ''");
        }
    } catch (Throwable $ignored) {}

    // grade_completed カラム（当学年終了フラグ）
    try {
        $stCols2 = $db->query('SHOW COLUMNS FROM students')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('grade_completed', $stCols2)) {
            $db->exec("ALTER TABLE students ADD COLUMN grade_completed TEXT DEFAULT '{}'");
        }
    } catch (Throwable $ignored) {}

    // manager_snapshots テーブル
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS manager_snapshots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            exam_id VARCHAR(128) NOT NULL,
            exam_name VARCHAR(255) NOT NULL,
            exam_end DATE NOT NULL,
            subject_id VARCHAR(64) NOT NULL,
            sched_status VARCHAR(32) DEFAULT 'na',
            sched_created_days INT DEFAULT NULL,
            daily_status VARCHAR(32) DEFAULT 'na',
            test_status VARCHAR(32) DEFAULT 'na',
            test_done INT DEFAULT 0,
            test_total INT DEFAULT 0,
            scheduled_count INT DEFAULT 0,
            studied_count INT DEFAULT 0,
            overdue_count INT DEFAULT 0,
            saved_at DATETIME NOT NULL,
            UNIQUE KEY uniq_snap (student_id, exam_id, subject_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $ignored) {}
}


function handleGet(string $action): void {
    $db = getDB();
    autoMigrate($db);

    switch ($action) {
        case 'debug_delete_exam':
            $eid = (int)($_GET['exam_id'] ?? 0);
            if (!$eid) err('exam_id required');
            $db->prepare('DELETE FROM school_exams WHERE id=?')->execute([$eid]);
            respond(['ok'=>true, 'deleted_id'=>$eid]);

        case 'debug_exams':
            $sc_list = $db->query('SELECT id, name FROM schools ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $dbg_out = [];
            foreach ($sc_list as $scl) {
                $st3 = $db->prepare('SELECT id,name,exam_end,nendo,target_grade,tbd FROM school_exams WHERE school_id=? ORDER BY exam_end');
                $st3->execute([$scl['id']]);
                $dbg_out[] = ['id'=>(int)$scl['id'],'name'=>$scl['name'],'exams'=>$st3->fetchAll(PDO::FETCH_ASSOC)];
            }
            respond(['schools'=>$dbg_out]);

        case 'schools':
            $schools = $db->query('SELECT * FROM schools ORDER BY id')->fetchAll();
            foreach ($schools as &$s) {
                $s['id']        = (int)$s['id'];
                $s['textbooks'] = json_decode($s['textbooks'] ?? '{}', true) ?: [];
                // subject_config カラムはマイグレーション後に追加（存在しない場合は空配列）
                $s['subject_config'] = isset($s['subject_config'])
                    ? (json_decode($s['subject_config'], true) ?: [])
                    : [];
                $stmt = $db->prepare('SELECT * FROM school_exams WHERE school_id = ? ORDER BY sort_order, id');
                $stmt->execute([$s['id']]);
                $exams = $stmt->fetchAll();
                foreach ($exams as &$e) {
                    $e['id']        = (int)$e['id'];
                    $e['school_id'] = (int)$e['school_id'];
                    $e['exam_end']  = $e['exam_end'] ?? '';
                    $e['nendo']     = $e['nendo'] ?? '';
                    $e['target_grade'] = $e['target_grade'] ?? '';
                    $e['tbd']       = !empty($e['tbd']);
                }
                $s['exams'] = $exams;
                $s['excluded_periods'] = isset($s['excluded_periods'])
                    ? (json_decode($s['excluded_periods'], true) ?: [])
                    : [];
            }
            respond($schools);

        // ---------- 生徒一覧 ----------
        case 'students':
            $rows = $db->query('SELECT * FROM students ORDER BY id')->fetchAll();
            foreach ($rows as &$r) {
                $r['id']           = (int)$r['id'];
                $r['school_id']    = $r['school_id'] !== null ? (int)$r['school_id'] : null;
                $r['weekly_slots'] = (int)$r['weekly_slots'];
                $r['lesson_days']  = json_decode($r['lesson_days'] ?? '[]', true) ?: [];
                $r['subjects']     = isset($r['subjects'])
                    ? (json_decode($r['subjects'], true) ?: [])
                    : [];
                $r['math_level']     = isset($r['math_level']) ? (int)$r['math_level'] : 3;
                $r['subject_levels'] = isset($r['subject_levels'])
                    ? (json_decode($r['subject_levels'], true) ?: [])
                    : [];
                $r['classroom_id']   = isset($r['classroom_id']) && $r['classroom_id'] !== null ? (int)$r['classroom_id'] : null;
                $r['furigana']        = $r['furigana'] ?? '';
                $r['exam_results']    = isset($r['exam_results'])
                    ? (json_decode($r['exam_results'], true) ?: [])
                    : [];
                $gcRaw = isset($r['grade_completed']) && $r['grade_completed'] !== null
                    ? (json_decode($r['grade_completed'], true) ?: [])
                    : [];
                $oldSubjKeys = ['英語','英単語','数学','理科','国語','社会','地理','歴史','公民','数学①計算/関数','数学②図形/データ'];
                $gcClean = array_filter($gcRaw, fn($k) => !in_array($k, $oldSubjKeys), ARRAY_FILTER_USE_KEY);
                if (count($gcRaw) !== count($gcClean)) {
                    try {
                        $db->prepare('UPDATE students SET grade_completed=? WHERE id=?')
                           ->execute([json_encode($gcClean ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT), $r['id']]);
                    } catch (Throwable $ignored) {}
                }
                $r['grade_completed'] = $gcClean ?: new stdClass();
            }
            respond($rows);

        // ---------- 進捗（生徒1人分・全科目）----------
        case 'progress':
            $sid = (int)($_GET['student_id'] ?? 0);
            if (!$sid) err('student_id required');
            $stmt = $db->prepare(
                'SELECT subject_id, unit_id, test_range, school_progress, scheduled_date, study_date, test_done
                 FROM progress WHERE student_id = ?'
            );
            $stmt->execute([$sid]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) {
                $r['test_done'] = (int)$r['test_done'];
            }
            respond($rows);

        // ---------- スナップショット取得 ----------
        case 'get_snapshots':
            $studentId = (int)($_GET['student_id'] ?? 0);
            $examId    = $_GET['exam_id'] ?? '';
            $where = 'WHERE 1=1';
            $params = [];
            if ($studentId) { $where .= ' AND student_id = ?'; $params[] = $studentId; }
            if ($examId)    { $where .= ' AND exam_id = ?';    $params[] = $examId; }
            $stmt = $db->prepare("SELECT * FROM manager_snapshots $where ORDER BY exam_end DESC, student_id, subject_id");
            $stmt->execute($params);
            respond($stmt->fetchAll(PDO::FETCH_ASSOC));

        // ---------- スナップショット保存 ----------
        case 'save_snapshot':
            $studentId = (int)($body['student_id'] ?? 0);
            $examId    = trim($body['exam_id']   ?? '');
            $examName  = trim($body['exam_name'] ?? '');
            $examEnd   = trim($body['exam_end']  ?? '');
            $subjects  = $body['subjects'] ?? [];
            if (!$studentId || !$examId || !$examEnd || !$subjects) err('Invalid parameters');
            $now = date('Y-m-d H:i:s');
            $saved = 0;
            foreach ($subjects as $subj) {
                $subjectId = trim($subj['subject_id'] ?? '');
                if (!$subjectId) continue;
                $db->prepare("INSERT INTO manager_snapshots
                    (student_id, exam_id, exam_name, exam_end, subject_id,
                     sched_status, sched_created_days, daily_status, test_status,
                     test_done, test_total, scheduled_count, studied_count, overdue_count, saved_at)
                    VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                     exam_name=VALUES(exam_name),
                     sched_status=VALUES(sched_status), sched_created_days=VALUES(sched_created_days),
                     daily_status=VALUES(daily_status), test_status=VALUES(test_status),
                     test_done=VALUES(test_done), test_total=VALUES(test_total),
                     scheduled_count=VALUES(scheduled_count), studied_count=VALUES(studied_count),
                     overdue_count=VALUES(overdue_count), saved_at=VALUES(saved_at)")
                ->execute([
                    $studentId, $examId, $examName, $examEnd, $subjectId,
                    $subj['sched_status']      ?? 'na',
                    isset($subj['sched_created_days']) && $subj['sched_created_days'] !== null
                        ? (int)$subj['sched_created_days'] : null,
                    $subj['daily_status']      ?? 'na',
                    $subj['test_status']       ?? 'na',
                    (int)($subj['test_done']   ?? 0),
                    (int)($subj['test_total']  ?? 0),
                    (int)($subj['scheduled_count'] ?? 0),
                    (int)($subj['studied_count']   ?? 0),
                    (int)($subj['overdue_count']   ?? 0),
                    $now
                ]);
                $saved++;
            }
            respond(['ok' => true, 'saved' => $saved]);

        // ---------- 教室一覧（classroomsテーブル）----------
        case 'classrooms':
            $rows = $db->query('SELECT id, name FROM classrooms ORDER BY id')->fetchAll();
            foreach ($rows as &$r) { $r['id'] = (int)$r['id']; }
            respond($rows);

        // ---------- 教室 削除 ----------
        case 'delete_classroom':
            $id = (int)($body['id'] ?? 0);
            if (!$id) err('id required');
            $stmt = $db->prepare('SELECT id FROM classrooms WHERE id=?');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) err('教室が見つかりません');
            $db->prepare('DELETE FROM classrooms WHERE id=?')->execute([$id]);
            respond(['ok' => true]);

        // ---------- grade_completed リセット ----------
        case 'reset_grade_completed':
            $id = (int)($body['id'] ?? 0);
            if (!$id) err('id required');
            $db->prepare('UPDATE students SET grade_completed=? WHERE id=?')
               ->execute(['{}', $id]);
            respond(['ok' => true]);

        default:
            err("Unknown action: $action");
    }
}

// ============================================================
// POST ハンドラ
// ============================================================
function handlePost(string $action, array $body): void {
    $db = getDB();
    autoMigrate($db);

    switch ($action) {

        // ---------- 学校 追加 ----------
        case 'add_school':
            $name = trim($body['name'] ?? '');
            if (!$name) err('name required');
            $isClassroom = !empty($body['is_classroom']) ? 1 : 0;
            if ($isClassroom) {
                // 教室追加: classroomsテーブルへ
                $db->prepare('INSERT INTO classrooms (name) VALUES (?)')->execute([$name]);
                respond(['id' => (int)$db->lastInsertId(), 'name' => $name, 'is_classroom' => true, 'textbooks' => [], 'subject_config' => [], 'exams' => []]);
            } else {
                // 在籍中学校追加: schoolsテーブルへ
                $tb = json_encode(['英語'=>'','数学'=>'','国語'=>'','理科'=>'','社会'=>''], JSON_UNESCAPED_UNICODE);
                $db->prepare('INSERT INTO schools (name, textbooks) VALUES (?, ?)')->execute([$name, $tb]);
                respond(['id' => (int)$db->lastInsertId(), 'name' => $name, 'is_classroom' => false, 'textbooks' => [], 'subject_config' => [], 'exams' => []]);
            }

        // ---------- 学校 保存（名前＋教科書＋試験まとめて）----------
        case 'save_school':
            $id       = (int)($body['id'] ?? 0);
            $name     = trim($body['name'] ?? '');
            $textbooks = $body['textbooks'] ?? [];
            $subject_config = $body['subject_config'] ?? [];
            $exams    = $body['exams'] ?? [];
            if (!$id || !$name) err('id and name required');

            // 学校基本情報を更新
            $excluded_periods = $body['excluded_periods'] ?? [];
            $db->prepare('UPDATE schools SET name=?, textbooks=?, subject_config=?, excluded_periods=? WHERE id=?')
               ->execute([$name, json_encode($textbooks, JSON_UNESCAPED_UNICODE), json_encode($subject_config, JSON_UNESCAPED_UNICODE), json_encode($excluded_periods, JSON_UNESCAPED_UNICODE), $id]);

            // 試験スケジュールを更新（IDが既存のものはUPDATE、新規はINSERT、削除はDELETE）
            $incomingIds = [];
            $order = 1;
            foreach ($exams as $e) {
                $eName  = trim($e['name'] ?? '');
                $eEnd   = nullIfEmpty($e['exam_end'] ?? '');
                $eNext  = nullIfEmpty($e['next_exam_start'] ?? '');
                if (!$eName) continue;
                $eNendo  = trim($e['nendo'] ?? '');
                $eGrade  = trim($e['target_grade'] ?? '');
                $eTbd = !empty($e['tbd']) ? 1 : 0;
                $eId = isset($e['id']) && is_numeric($e['id']) && (int)$e['id'] > 0 ? (int)$e['id'] : 0;
                // tmpで始まるIDは新規
                $isTmp = isset($e['id']) && strpos((string)$e['id'], 'tmp_') === 0;
                if ($eId > 0 && !$isTmp) {
                    // 既存IDはUPDATE（IDを変えない → test_rangeの参照が壊れない）
                    $db->prepare('UPDATE school_exams SET name=?, exam_end=?, next_exam_start=?, sort_order=?, nendo=?, target_grade=?, tbd=? WHERE id=? AND school_id=?')
                      ->execute([$eName, $eEnd, $eNext, $order, $eNendo ?: null, $eGrade ?: null, $eTbd, $eId, $id]);
                    $incomingIds[] = $eId;
                } else {
                    // 新規はINSERT
                    $stmt = $db->prepare('INSERT INTO school_exams (school_id, name, exam_end, next_exam_start, sort_order, nendo, target_grade, tbd) VALUES (?,?,?,?,?,?,?,?)');
                    $stmt->execute([$id, $eName, $eEnd, $eNext, $order, $eNendo ?: null, $eGrade ?: null, $eTbd]);
                    $incomingIds[] = (int)$db->lastInsertId();
                }
                $order++;
            }
            // 送られてこなかった既存IDを削除
            if (!empty($incomingIds)) {
                $placeholders = implode(',', array_fill(0, count($incomingIds), '?'));
                $params = array_merge([$id], $incomingIds);
                $db->prepare("DELETE FROM school_exams WHERE school_id=? AND id NOT IN ($placeholders)")->execute($params);
            } else {
                $db->prepare('DELETE FROM school_exams WHERE school_id=?')->execute([$id]);
            }

            // 保存後の試験一覧を返す
            $stmt = $db->prepare('SELECT * FROM school_exams WHERE school_id=? ORDER BY sort_order,id');
            $stmt->execute([$id]);
            $savedExams = $stmt->fetchAll();
            foreach ($savedExams as &$e) { $e['id'] = (int)$e['id']; $e['school_id'] = (int)$e['school_id']; }
            respond(['ok' => true, 'exams' => $savedExams]);

        // ---------- 学校 削除 ----------
        case 'delete_school':
            $id = (int)($body['id'] ?? 0);
            if (!$id) err('id required');
            $db->prepare('DELETE FROM school_exams WHERE school_id=?')->execute([$id]);
            $db->prepare('DELETE FROM schools WHERE id=?')->execute([$id]);
            respond(['ok' => true]);

        // ---------- 生徒 追加 ----------
        case 'add_student':
            $name = trim($body['name'] ?? '');
            if (!$name) err('name required');
            $grade       = $body['grade']    ?? '中1';
            $schoolId    = nullIfEmpty($body['school_id'] ?? null);
            $color       = $body['color']    ?? '#4f7fe8';
            $wSlots      = (int)($body['weekly_slots'] ?? 2);
            $lessonDays  = json_encode($body['lesson_days'] ?? [], JSON_UNESCAPED_UNICODE);
            $enrollDate  = nullIfEmpty($body['enroll_date'] ?? '');
            // 基本フィールドでINSERT
            $classroomId = isset($body['classroom_id']) && $body['classroom_id'] !== null && $body['classroom_id'] !== ''
                ? (int)$body['classroom_id'] : null;
            $furigana = trim($body['furigana'] ?? '');
            $db->prepare(
                'INSERT INTO students (name, furigana, grade, school_id, classroom_id, color, weekly_slots, lesson_days, enroll_date)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([$name, $furigana, $grade, $schoolId, $classroomId, $color, $wSlots, $lessonDays, $enrollDate]);
            $newId = (int)$db->lastInsertId();
            // subjects カラム（マイグレーション済みの場合のみ更新）
            try {
                $db->prepare('UPDATE students SET subjects=? WHERE id=?')
                   ->execute([json_encode($body['subjects'] ?? [], JSON_UNESCAPED_UNICODE), $newId]);
            } catch (Throwable $e) { /* subjects カラム未追加の場合はスキップ */ }
            try {
                $db->prepare('UPDATE students SET subject_levels=? WHERE id=?')
                   ->execute([json_encode($body['subject_levels'] ?? [], JSON_UNESCAPED_UNICODE), $newId]);
            } catch (Throwable $e) {}
            respond([
                'id'=>$newId,'name'=>$name,'furigana'=>$furigana,'grade'=>$grade,'school_id'=>$schoolId?(int)$schoolId:null,
                'color'=>$color,'weekly_slots'=>$wSlots,'lesson_days'=>$body['lesson_days']??[],
                'subjects'=>$body['subjects']??[],'math_level'=>3,'subject_levels'=>$body['subject_levels']??[],
                'classroom_id'=>$classroomId,'enroll_date'=>$enrollDate,'memo'=>''
            ]);

        // ---------- 生徒 保存 ----------
        case 'save_student':
            $id = (int)($body['id'] ?? 0);
            if (!$id) err('id required');
            $sid_val   = isset($body['school_id']) && $body['school_id'] !== '' && $body['school_id'] !== null
                         ? (int)$body['school_id'] : null;
            $edate_val = (isset($body['enroll_date']) && $body['enroll_date'] !== '')
                         ? $body['enroll_date'] : null;
            $classroomIdSave = isset($body['classroom_id']) && $body['classroom_id'] !== null && $body['classroom_id'] !== ''
                ? (int)$body['classroom_id'] : null;
            $db->prepare(
                'UPDATE students SET name=?,furigana=?,grade=?,school_id=?,classroom_id=?,color=?,weekly_slots=?,lesson_days=?,enroll_date=?,memo=? WHERE id=?'
            )->execute([
                (string)($body['name'] ?? ''),
                (string)($body['furigana'] ?? ''),
                (string)($body['grade'] ?? '中1'),
                $sid_val,
                $classroomIdSave,
                (string)($body['color'] ?? '#4f7fe8'),
                (int)($body['weekly_slots'] ?? 2),
                json_encode(isset($body['lesson_days']) ? (array)$body['lesson_days'] : [], JSON_UNESCAPED_UNICODE),
                $edate_val,
                (string)($body['memo'] ?? ''),
                $id,
            ]);
            // subjects/math_level カラム（ALTER TABLE実行後のみ有効）
            try {
                $db->prepare('UPDATE students SET subjects=? WHERE id=?')
                   ->execute([json_encode(isset($body['subjects']) ? (array)$body['subjects'] : [], JSON_UNESCAPED_UNICODE), $id]);
            } catch (Throwable $ignored) {}
            try {
                $db->prepare('UPDATE students SET math_level=? WHERE id=?')
                   ->execute([(int)($body['math_level'] ?? 3), $id]);
            } catch (Throwable $ignored) {}
            try {
                $db->prepare('UPDATE students SET subject_levels=? WHERE id=?')
                   ->execute([json_encode(isset($body['subject_levels']) ? (array)$body['subject_levels'] : [], JSON_UNESCAPED_UNICODE), $id]);
            } catch (Throwable $ignored) {}
            try {
                $gcVal = $body['grade_completed'] ?? null;
                if (is_array($gcVal)) $gcVal = json_encode($gcVal, JSON_UNESCAPED_UNICODE);
                elseif (!is_string($gcVal)) $gcVal = '{}';
                $db->prepare('UPDATE students SET grade_completed=? WHERE id=?')
                   ->execute([$gcVal, $id]);
            } catch (Throwable $ignored) {}

            // ---------- school_id が設定されていて、この生徒にまだ test_range がない場合にコピー ----------
            if ($sid_val) {
                try {
                    $grade_val = (string)($body['grade'] ?? '中1');
                    $stmtCheck = $db->prepare(
                        'SELECT COUNT(*) FROM progress WHERE student_id=? AND test_range IS NOT NULL'
                    );
                    $stmtCheck->execute([$id]);
                    $hasRange = (int)$stmtCheck->fetchColumn();
                    if ($hasRange === 0) {
                        $stmtRef = $db->prepare(
                            'SELECT DISTINCT p.subject_id, p.unit_id, p.test_range
                             FROM progress p
                             INNER JOIN students s ON s.id = p.student_id
                             WHERE s.school_id = ? AND s.grade = ? AND p.test_range IS NOT NULL AND s.id != ?'
                        );
                        $stmtRef->execute([$sid_val, $grade_val, $id]);
                        $refRows = $stmtRef->fetchAll();
                        foreach ($refRows as $row) {
                            $db->prepare('INSERT IGNORE INTO progress (student_id, subject_id, unit_id) VALUES (?,?,?)')
                               ->execute([$id, $row['subject_id'], $row['unit_id']]);
                            $db->prepare('UPDATE progress SET test_range=?, updated_at=NOW() WHERE student_id=? AND subject_id=? AND unit_id=?')
                               ->execute([$row['test_range'], $id, $row['subject_id'], $row['unit_id']]);
                        }
                    }
                } catch (Throwable $ignored) {}
            }
            // ---------- ここまで ----------

            respond(['ok' => true]);

        // ---------- 生徒 削除 ----------
        case 'delete_student':
            $id = (int)($body['id'] ?? 0);
            if (!$id) err('id required');
            $db->prepare('DELETE FROM progress WHERE student_id=?')->execute([$id]);
            $db->prepare('DELETE FROM students WHERE id=?')->execute([$id]);
            respond(['ok' => true]);

        // ---------- 進捗 フィールド1件保存 ----------
        case 'save_progress':
            $sid      = (int)($body['student_id'] ?? 0);
            $subjId   = $body['subject_id']  ?? '';
            $unitId   = $body['unit_id']     ?? '';
            $field    = $body['field']       ?? '';
            $value    = $body['value']       ?? '';

            // フィールド名ホワイトリスト
            $allowed = ['test_range','school_progress','scheduled_date','study_date','test_done'];
            if (!$sid || !$subjId || !$unitId || !in_array($field, $allowed, true)) {
                err('Invalid parameters');
            }

            // 行が存在しなければINSERT
            $db->prepare('INSERT IGNORE INTO progress (student_id,subject_id,unit_id) VALUES (?,?,?)')
               ->execute([$sid, $subjId, $unitId]);

            // 値変換
            if ($field === 'test_done') {
                $dbValue = $value ? 1 : 0;
            } else {
                $dbValue = ($value === '' || $value === null) ? null : $value;
            }

            // フィールド名はホワイトリスト済みなので動的利用OK
            $db->prepare("UPDATE progress SET $field=?, updated_at=NOW() WHERE student_id=? AND subject_id=? AND unit_id=?")
               ->execute([$dbValue, $sid, $subjId, $unitId]);

            respond(['ok' => true]);

        // ---------- テスト範囲 同学校・同学年の生徒に一括同期 ----------
        case 'sync_test_range':
            $unitId   = $body['unit_id']    ?? '';
            $subjId   = $body['subject_id'] ?? '';
            $value    = $body['value']      ?? '';
            $schoolId = (int)($body['school_id'] ?? 0);
            $grade    = $body['grade']      ?? '';

            if (!$unitId || !$subjId || !$schoolId || !$grade) err('Invalid parameters');

            // 同学校・同学年の全生徒IDを取得
            $stmt = $db->prepare('SELECT id FROM students WHERE school_id=? AND grade=?');
            $stmt->execute([$schoolId, $grade]);
            $sids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($sids as $s) {
                $db->prepare('INSERT IGNORE INTO progress (student_id,subject_id,unit_id) VALUES (?,?,?)')
                   ->execute([$s, $subjId, $unitId]);
                $db->prepare("UPDATE progress SET test_range=?, updated_at=NOW() WHERE student_id=? AND subject_id=? AND unit_id=?")
                   ->execute([$value ?: null, $s, $subjId, $unitId]);
            }
            respond(['ok' => true, 'synced' => count($sids)]);

        // ---------- 試験結果CSV一括取り込み ----------
        case 'import_exam_results':
            $csvB64 = $body['csv_b64'] ?? '';
            if (!$csvB64) err('csv_b64 required');
            $csvText = base64_decode($csvB64);
            if ($csvText === false || $csvText === '') err('base64デコード失敗');
            $csvText = preg_replace('/^\xEF\xBB\xBF/', '', $csvText);
            $csvText = str_replace("\r\n", "\n", str_replace("\r", "\n", $csvText));
            $lines = explode("\n", trim($csvText));
            if (count($lines) < 2) err('データが不足しています');
            $header = str_getcsv($lines[0]);
            $header = array_map(fn($h) => trim($h, ' "'), $header);
            $colMap = array_flip($header);
            $updated = []; $skipped = 0;
            for ($li = 1; $li < count($lines); $li++) {
                $row = str_getcsv($lines[$li]);
                if (count($row) < 3) continue;
                $name  = isset($colMap['生徒']) ? trim($row[$colMap['生徒']] ?? '') : '';
                $grade = isset($colMap['学年']) ? trim($row[$colMap['学年']] ?? '') : '';
                if (!$name || !$grade) continue;
                // 名前のみで照合（学年は無視）
                $stmt = $db->prepare('SELECT id, exam_results FROM students WHERE name=?');
                $stmt->execute([$name]);
                $stu = $stmt->fetch();
                if (!$stu) continue;
                $getCol = fn($key) => isset($colMap[$key]) && isset($row[$colMap[$key]]) ? trim($row[$colMap[$key]]) : '';
                $record = [
                    'year'      => $getCol('年度'),
                    'term'      => $getCol('学期'),
                    'test_name' => $getCol('テスト名'),
                    '英語'  => $getCol('英語(点数)'),
                    '数学'  => $getCol('数学(点数)'),
                    '理科'  => $getCol('理科(点数)'),
                    '社会'  => $getCol('社会(点数)'),
                    '国語'  => $getCol('国語(点数)'),
                    '音楽'  => $getCol('音楽(点数)'),
                    '美術'  => $getCol('美術(点数)'),
                    '保体'  => $getCol('保健体育(点数)'),
                    '技家'  => $getCol('技術家庭科(点数)'),
                    'total'     => $getCol('点数'),
                    'date'      => $getCol('実施日'),
                    'memo'      => $getCol('メモ'),
                ];
                $existing = json_decode($stu['exam_results'] ?? '[]', true) ?: [];
                $isDup = false;
                foreach ($existing as $ex) {
                    if ($ex['year']===$record['year'] && $ex['term']===$record['term'] && $ex['test_name']===$record['test_name']) {
                        $isDup = true; break;
                    }
                }
                if ($isDup) { $skipped++; continue; }
                $existing[] = $record;
                $db->prepare('UPDATE students SET exam_results=? WHERE id=?')
                   ->execute([json_encode($existing, JSON_UNESCAPED_UNICODE), $stu['id']]);
                $updated[] = ['id'=>(int)$stu['id'], 'exam_results'=>$existing];
            }
            respond(['ok'=>true, 'updated'=>$updated, 'skipped'=>$skipped]);

        default:
            err("Unknown action: $action");
    }
}
