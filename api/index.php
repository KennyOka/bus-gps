<?php
/**
 * フロントコントローラ。
 *   POST /bus/api/  (JSONボディ {"action":"...", ...})
 * GAS版の google.script.run.xxx() を action 名に対応させて移行する。
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/import.php';
require __DIR__ . '/shimabus_import.php';

/* ---- CORS（別オリジン時のみ） ---- */
$allowOrigin = $GLOBALS['CONFIG']['cors']['allow_origin'] ?? '';
if ($allowOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $allowOrigin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

$action = (string) param('action', '');

try {
    switch ($action) {
        // ---- 認証 ----
        case 'auth.login':       handle_login();            break;
        case 'auth.logout':      handle_logout();           break;
        case 'auth.me':          handle_me();               break;

        // ---- 社員情報（getDriverInfo / saveDriverInfo 相当） ----
        case 'employee.get':     handle_employee_get();     break;
        case 'employee.save':    handle_employee_save();    break;
        case 'employee.resetPassword': handle_employee_reset_password(); break; // 管理者がパスワード再設定
        case 'employee.create':  handle_employee_create();  break; // 管理者が新規社員登録

        // ---- ダイヤ / マスタ ----
        case 'dia.courses':      handle_dia_courses();      break; // ダイヤマスタからコース雛形
        // ---- ダイヤ手動管理（管理者 role=9） ----
        case 'dia.listMaster':   handle_dia_list_master();   break;
        case 'dia.getMaster':    handle_dia_get_master();    break;
        case 'dia.saveMaster':   handle_dia_save_master();   break;
        case 'dia.deleteMaster': handle_dia_delete_master(); break;
        case 'record.checkDia':  handle_record_check_dia(); break; // 過去記録の有無判定
        case 'busstop.list':     handle_busstop_list();     break;

        // ---- 業務記録 ----
        case 'record.list':      handle_record_list();      break;
        case 'summary.get':      handle_summary();          break;
        case 'summary.year':     handle_summary_year();     break; // 年次サマリー(グラフ用)
        case 'summary.yearMemo': handle_summary_year_memo(); break; // 年間メモGemini要約
        case 'record.export':    handle_record_export();    break; // 月次CSV用の明細行
        case 'record.get':       handle_record_get();       break;
        case 'record.save':      handle_record_save();      break; // 下書き保存(upsert)
        case 'record.register':  handle_record_register();  break; // 入力完了(走行キロ計算+登録済)
        case 'record.delete':    handle_record_delete();    break;

        // ---- PDF ----
        case 'pdf.generate':     handle_pdf_generate();     break;

        // ---- マスタCSVインポート（管理者 role=9） ----
        case 'import.courses':   handle_import_courses();   break;
        case 'import.busstops':  handle_import_busstops();  break;
        case 'import.stops':     handle_import_stops();     break;

        // ---- しまバスHPからの取り込み（管理者 role=9） ----
        case 'shimabus.importRoute': handle_shimabus_import_route(); break;
        case 'shimabus.importFares': handle_shimabus_import_fares(); break;   // 区間運賃(三角表)を分割取込

        default:
            json_err('unknown_action', "未定義のaction: {$action}", 404);
    }
} catch (PDOException $e) {
    $msg = ($GLOBALS['CONFIG']['debug'] ?? false)
        ? 'DBエラー: ' . $e->getMessage()
        : 'データベースエラーが発生しました。';
    json_err('db_error', $msg, 500);
} catch (Throwable $e) {
    $msg = ($GLOBALS['CONFIG']['debug'] ?? false)
        ? $e->getMessage()
        : 'サーバーエラーが発生しました。';
    json_err('server_error', $msg, 500);
}

/* ================================================================== */
/*  認証                                                              */
/* ================================================================== */
function handle_login(): void
{
    $no = (string) require_param('employee_no');
    $pw = (string) require_param('password');

    $st = pdo()->prepare(
        'SELECT employee_no, name, role, password_hash, is_active FROM m_employee WHERE employee_no = ?'
    );
    $st->execute([$no]);
    $emp = $st->fetch();

    if (!$emp || (int) $emp['is_active'] !== 1 || !password_verify($pw, $emp['password_hash'])) {
        json_err('login_failed', '社員番号またはパスワードが違います。', 401);
    }
    issue_token($emp['employee_no']);
    unset($emp['password_hash'], $emp['is_active']);
    json_ok(['employee' => $emp]);
}

function handle_logout(): void
{
    revoke_token();
    json_ok(['loggedOut' => true]);
}

function handle_me(): void
{
    json_ok(['employee' => require_auth()]);
}

/* ================================================================== */
/*  社員情報                                                          */
/* ================================================================== */
function handle_employee_get(): void
{
    $u  = require_auth();
    $st = pdo()->prepare('SELECT employee_no, name, role FROM m_employee WHERE employee_no = ?');
    $st->execute([$u['employee_no']]);
    json_ok(['employee' => $st->fetch()]);
}

function handle_employee_save(): void
{
    $u    = require_auth();
    $name = (string) require_param('name');
    pdo()->prepare('UPDATE m_employee SET name = ? WHERE employee_no = ?')
         ->execute([$name, $u['employee_no']]);
    // TODO: 標準出発車庫などの初期値項目を追加するならここで更新
    json_ok(['employee_no' => $u['employee_no']]);
}

/** パスワード再設定（管理者=role9 専用）。忘れた運転者の代わりに管理者が新PWを設定する。 */
function handle_employee_reset_password(): void
{
    require_admin();
    $no = (string) require_param('employee_no');
    $pw = (string) require_param('new_password');
    if (mb_strlen($pw) < 6) {
        json_err('weak_password', 'パスワードは6文字以上にしてください。', 422);
    }
    $st = pdo()->prepare('SELECT employee_no FROM m_employee WHERE employee_no = ?');
    $st->execute([$no]);
    if (!$st->fetch()) {
        json_err('not_found', '該当の社員番号が見つかりません。', 404);
    }
    $hash = password_hash($pw, PASSWORD_DEFAULT);
    pdo()->prepare('UPDATE m_employee SET password_hash = ? WHERE employee_no = ?')->execute([$hash, $no]);
    pdo()->prepare('DELETE FROM t_auth_token WHERE employee_no = ?')->execute([$no]); // 既存ログインを無効化
    json_ok(['employee_no' => $no]);
}

/** 新規社員登録（管理者=role9 専用）。仮パスワードを設定して発行し、本人が後で変更する運用。 */
function handle_employee_create(): void
{
    require_admin();
    $no   = (string) require_param('employee_no');
    $name = (string) require_param('name');
    $pw   = (string) require_param('password');
    $role = (int) param('role', 1);
    if (!preg_match('/^\d{1,4}$/', $no)) {
        json_err('bad_no', '社員番号は数字1〜4桁で指定してください。', 422);
    }
    $no = str_pad($no, 4, '0', STR_PAD_LEFT);
    if ($name === '')          json_err('bad_name', '氏名は必須です。', 422);
    if (mb_strlen($pw) < 6)    json_err('weak_password', 'パスワードは6文字以上にしてください。', 422);
    if (!in_array($role, [1, 9], true)) $role = 1;

    $chk = pdo()->prepare('SELECT employee_no FROM m_employee WHERE employee_no = ?');
    $chk->execute([$no]);
    if ($chk->fetch()) {
        json_err('exists', 'その社員番号は既に存在します（パスワードは「社員パスワード再設定」で変更できます）。', 409);
    }
    $hash = password_hash($pw, PASSWORD_DEFAULT);
    pdo()->prepare('INSERT INTO m_employee (employee_no, name, password_hash, role, is_active) VALUES (?,?,?,?,1)')
         ->execute([$no, $name, $hash, $role]);
    json_ok(['employee_no' => $no, 'name' => $name, 'role' => $role]);
}

/* ================================================================== */
/*  ダイヤ / マスタ                                                    */
/* ================================================================== */
/** ダイヤマスタ＋コース（始発地/到着地はバス停名を解決して返す）＝コース雛形の自動作成 */
function handle_dia_courses(): void
{
    $u       = require_auth();
    $diaNo   = (string) require_param('dia_no');
    $dayType = (int) require_param('day_type'); // 1=平日 2=土日祝

    $st = pdo()->prepare('SELECT dia_id, dia_no, day_type, name FROM m_dia WHERE dia_no = ? AND day_type = ?');
    $st->execute([$diaNo, $dayType]);
    $dia = $st->fetch();
    if (!$dia) {
        json_err('dia_not_found', 'ダイヤマスタが見つかりません。', 404);
    }

    $cs = pdo()->prepare(
        'SELECT c.seq, c.category,
                COALESCE(c.start_name, bs_s.name) AS start_place,
                COALESCE(c.end_name, bs_e.name)   AS end_place,
                c.departure_time,
                -- 到着時刻: 照合するしまバス便(系統=touch_no ＋ 発時刻一致)の終着停の時刻。
                --   ①割当済のsrc_trip_id ②keito+発時刻でオンザフライ照合 ③既存m_dia_course_stop の順でフォールバック。
                COALESCE(
                  (SELECT COALESCE(st.arrival_time, st.departure_time) FROM src_trip_stop st
                     WHERE st.src_trip_id = c.src_trip_id ORDER BY st.seq DESC LIMIT 1),
                  (SELECT COALESCE(st.arrival_time, st.departure_time) FROM src_trip_stop st
                     WHERE st.src_trip_id = (SELECT t.src_trip_id FROM src_trip t
                        WHERE t.keito_no = c.touch_no AND t.first_departure = c.departure_time
                        ORDER BY t.target_date DESC LIMIT 1)
                     ORDER BY st.seq DESC LIMIT 1),
                  (SELECT s.scheduled_time FROM m_dia_course_stop s
                     WHERE s.dia_course_id = c.dia_course_id ORDER BY s.seq DESC LIMIT 1)
                ) AS arrival_time,
                c.note
           FROM m_dia_course c
           LEFT JOIN m_busstop bs_s ON bs_s.busstop_id = c.start_busstop_id
           LEFT JOIN m_busstop bs_e ON bs_e.busstop_id = c.end_busstop_id
          WHERE c.dia_id = ?
          ORDER BY c.seq'
    );
    $cs->execute([$dia['dia_id']]);

    // この運転者の回送パターン(学習済ルール)を同梱。フロントが (from_place→to_place) で照合し回送初期値に反映。
    // 新テーブル未適用でも本体を落とさないよう try で保護。
    $rules = [];
    try {
        $rs = pdo()->prepare(
            'SELECT r.from_place, r.to_place, l.seq, l.waypoint, l.travel_min
               FROM t_deadhead_rule r JOIN t_deadhead_rule_leg l ON l.rule_id = r.rule_id
              WHERE r.employee_no = ? ORDER BY r.rule_id, l.seq'
        );
        $rs->execute([$u['employee_no']]);
        foreach ($rs->fetchAll() as $x) {
            $key = $x['from_place'] . "\t" . $x['to_place'];
            if (!isset($rules[$key])) $rules[$key] = ['from_place' => $x['from_place'], 'to_place' => $x['to_place'], 'legs' => []];
            $rules[$key]['legs'][] = ['waypoint' => $x['waypoint'], 'travel_min' => (int) $x['travel_min']];
        }
    } catch (Throwable $e) { $rules = []; }

    json_ok(['dia' => $dia, 'courses' => $cs->fetchAll(), 'deadhead_rules' => array_values($rules)]);
}

/* ================================================================== */
/*  ダイヤ手動管理（管理者=role9）: 一覧/取得/保存/削除                  */
/* ================================================================== */
function handle_dia_list_master(): void
{
    require_admin();
    $st = pdo()->query(
        'SELECT d.dia_id, d.dia_no, d.day_type, d.name, d.valid_from,
                (SELECT COUNT(*) FROM m_dia_course c WHERE c.dia_id = d.dia_id) AS courses
           FROM m_dia d ORDER BY d.dia_no, d.day_type'
    );
    json_ok(['dias' => $st->fetchAll()]);
}

function handle_dia_get_master(): void
{
    require_admin();
    $id = (int) require_param('dia_id');
    $st = pdo()->prepare('SELECT dia_id, dia_no, day_type, name, valid_from FROM m_dia WHERE dia_id = ?');
    $st->execute([$id]);
    $dia = $st->fetch();
    if (!$dia) json_err('not_found', 'ダイヤが見つかりません。', 404);
    $cs = pdo()->prepare(
        'SELECT seq, digital_no, touch_no, category, start_name, end_name, departure_time, note, settlement_flag, connection
           FROM m_dia_course WHERE dia_id = ? ORDER BY seq'
    );
    $cs->execute([$id]);
    $dia['courses'] = $cs->fetchAll();
    json_ok(['dia' => $dia]);
}

/** ダイヤ＋コースを保存（新規/更新）。コースは置換。管理者専用。 */
function handle_dia_save_master(): void
{
    require_admin();
    $b = request_body();
    $diaNo   = str_pad((string) ($b['dia_no'] ?? ''), 4, '0', STR_PAD_LEFT);
    $dayType = (int) ($b['day_type'] ?? 0);
    if (!preg_match('/^\d{4}$/', $diaNo))       json_err('bad_no', 'ダイヤ番号は数字4桁で指定してください。', 422);
    if (!in_array($dayType, [1, 2], true))      json_err('bad_daytype', '種別は1(平日)/2(土日祝)で指定してください。', 422);
    $courses = is_array($b['courses'] ?? null) ? $b['courses'] : [];

    $db = pdo();
    $db->beginTransaction();
    try {
        $diaId = (int) ($b['dia_id'] ?? 0);
        if ($diaId > 0) {
            $db->prepare('UPDATE m_dia SET dia_no = ?, day_type = ?, name = ?, valid_from = ? WHERE dia_id = ?')
               ->execute([$diaNo, $dayType, ($b['name'] ?: null), ($b['valid_from'] ?: null), $diaId]);
        } else {
            $db->prepare('INSERT INTO m_dia (dia_no, day_type, name, valid_from) VALUES (?,?,?,?)
                          ON DUPLICATE KEY UPDATE name = VALUES(name), valid_from = VALUES(valid_from)')
               ->execute([$diaNo, $dayType, ($b['name'] ?: null), ($b['valid_from'] ?: null)]);
            $s = $db->prepare('SELECT dia_id FROM m_dia WHERE dia_no = ? AND day_type = ?');
            $s->execute([$diaNo, $dayType]);
            $diaId = (int) $s->fetchColumn();
        }
        $db->prepare('DELETE FROM m_dia_course WHERE dia_id = ?')->execute([$diaId]);
        $ins = $db->prepare(
            'INSERT INTO m_dia_course (dia_id, seq, digital_no, touch_no, category, start_name, end_name, departure_time, note, settlement_flag, connection)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $seq = 1;
        foreach ($courses as $c) {
            $ins->execute([
                $diaId, (int) ($c['seq'] ?? $seq),
                ($c['digital_no'] ?? '') ?: null,
                ($c['touch_no'] ?? '') ?: null,
                (int) ($c['category'] ?? 3),
                ($c['start_name'] ?? '') ?: null,
                ($c['end_name'] ?? '') ?: null,
                ($c['departure_time'] ?? '') ?: null,
                ($c['note'] ?? '') ?: null,
                (int) ($c['settlement_flag'] ?? 0),
                ($c['connection'] ?? '') ?: null,
            ]);
            $seq++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    json_ok(['dia_id' => $diaId, 'courses' => count($courses)]);
}

function handle_dia_delete_master(): void
{
    require_admin();
    $id = (int) require_param('dia_id');
    // 業務記録で使用中は削除不可（FKはRESTRICT）
    $chk = pdo()->prepare('SELECT COUNT(*) FROM t_work_record WHERE dia_id = ?');
    $chk->execute([$id]);
    if ((int) $chk->fetchColumn() > 0) {
        json_err('in_use', 'このダイヤは業務記録で使用中のため削除できません。', 409);
    }
    $st = pdo()->prepare('DELETE FROM m_dia WHERE dia_id = ?');
    $st->execute([$id]);
    if ($st->rowCount() === 0) json_err('not_found', 'ダイヤが見つかりません。', 404);
    json_ok(['deleted' => $id]); // m_dia_course は CASCADE
}

/** ダイヤ番号から、この社員の過去記録の有無を判定（直近1件） */
function handle_record_check_dia(): void
{
    $u     = require_auth();
    $diaNo = (string) require_param('dia_no');

    $st = pdo()->prepare(
        'SELECT work_record_id, work_date FROM t_work_record
          WHERE employee_no = ? AND dia_no = ?
          ORDER BY work_date DESC, work_record_id DESC LIMIT 1'
    );
    $st->execute([$u['employee_no'], $diaNo]);
    $latest = $st->fetch();

    json_ok([
        'hasPastRecord'      => (bool) $latest,
        'latestWorkRecordId' => $latest ? (int) $latest['work_record_id'] : null,
        'latestWorkDate'     => $latest['work_date'] ?? null,
        // 過去記録あり→フロントは record.get(latestWorkRecordId) で転記
        // 過去記録なし→フロントは dia.courses でマスタから雛形作成
    ]);
}

function handle_busstop_list(): void
{
    require_auth();
    $st = pdo()->query(
        'SELECT busstop_id, name, direction, latitude, longitude
           FROM m_busstop WHERE is_active = 1 ORDER BY name, direction'
    );
    json_ok(['busstops' => $st->fetchAll()]);
}

/* ================================================================== */
/*  業務記録                                                          */
/* ================================================================== */
function handle_record_list(): void
{
    $u     = require_auth();
    $year  = (int) require_param('year');
    $month = (int) require_param('month');

    $from = sprintf('%04d-%02d-01', $year, $month);
    $to   = (new DateTime($from))->modify('first day of next month')->format('Y-m-d');

    // ドリルダウン用の任意フィルタ（集計行のタップから: 車両 / 運行種別）
    $b        = request_body();
    $fVehicle = isset($b['vehicle'])   ? trim((string) $b['vehicle'])   : '';
    $fType    = isset($b['work_type']) ? trim((string) $b['work_type']) : '';
    $where  = 'employee_no = ? AND work_date >= ? AND work_date < ?';
    $params = [$u['employee_no'], $from, $to];
    if ($fVehicle !== '') {
        $where .= ' AND (vehicle_no1 = ? OR vehicle_no2 = ? OR vehicle_no3 = ? OR vehicle_no4 = ?)';
        array_push($params, $fVehicle, $fVehicle, $fVehicle, $fVehicle);
    }
    if ($fType !== '') {
        $where .= ' AND ' . work_type_expr() . ' = ?';
        $params[] = $fType;
    }

    $st = pdo()->prepare(
        'SELECT work_record_id, work_date, dia_no, status, dest, memo,
                charter_group, work_type, is_charter, pdf_file,
                (COALESCE(distance1,0)+COALESCE(distance2,0)+COALESCE(distance3,0)+COALESCE(distance4,0)) AS distance_total
           FROM t_work_record
          WHERE ' . $where . '
          ORDER BY work_date, dia_no'
    );
    $st->execute($params);
    $records = $st->fetchAll();
    // PDF閲覧は認証付き api/pdf.php 経由（本人のみ）。フロントは pdf_file の有無でボタン表示し、
    // URLは API 基点で組み立てる（直リンクの out_url は使わない）。
    json_ok(['records' => $records]);
}

/** 月次集計：運行日数 / 月間走行距離 / 累計走行距離（登録済のみ集計） */
function handle_summary(): void
{
    $u     = require_auth();
    $year  = (int) require_param('year');
    $month = (int) require_param('month');

    $from = sprintf('%04d-%02d-01', $year, $month);
    $to   = (new DateTime($from))->modify('first day of next month')->format('Y-m-d');
    $distExpr = '(COALESCE(distance1,0)+COALESCE(distance2,0)+COALESCE(distance3,0)+COALESCE(distance4,0))';

    // 当月：運行日数(distinct 日付) と 月間走行距離
    $st = pdo()->prepare(
        "SELECT COUNT(DISTINCT work_date) AS days,
                COALESCE(SUM($distExpr),0) AS month_dist
           FROM t_work_record
          WHERE employee_no = ? AND work_date >= ? AND work_date < ? AND status = 2"
    );
    $st->execute([$u['employee_no'], $from, $to]);
    $m = $st->fetch();

    // 累計走行距離（この社員の全期間）
    $st2 = pdo()->prepare(
        "SELECT COALESCE(SUM($distExpr),0) AS total_dist FROM t_work_record WHERE employee_no = ? AND status = 2"
    );
    $st2->execute([$u['employee_no']]);
    $t = $st2->fetch();

    // 車両別（当月）: vehicle_no1..4 / distance1..4 を縦持ちに展開して集計
    $vsql = '';
    $vparams = [];
    for ($i = 1; $i <= 4; $i++) {
        if ($vsql !== '') { $vsql .= ' UNION ALL '; }
        $vsql .= "SELECT work_date, vehicle_no$i AS vno, COALESCE(distance$i,0) AS dist
                    FROM t_work_record WHERE employee_no = ? AND work_date >= ? AND work_date < ? AND status = 2";
        array_push($vparams, $u['employee_no'], $from, $to);
    }
    $st3 = pdo()->prepare(
        "SELECT vno AS vehicle_no, COUNT(DISTINCT work_date) AS days, COALESCE(SUM(dist),0) AS dist
           FROM ($vsql) x
          WHERE vno IS NOT NULL AND vno <> ''
          GROUP BY vno ORDER BY dist DESC, vno"
    );
    $st3->execute($vparams);
    $byVehicle = [];
    foreach ($st3->fetchAll() as $r) {
        $byVehicle[] = ['vehicle_no' => $r['vehicle_no'], 'days' => (int) $r['days'], 'dist' => (int) $r['dist']];
    }

    // 貸切団体別（当月）
    $st4 = pdo()->prepare(
        "SELECT charter_group, COUNT(*) AS cnt, COALESCE(SUM($distExpr),0) AS dist
           FROM t_work_record
          WHERE employee_no = ? AND work_date >= ? AND work_date < ? AND status = 2
            AND charter_group IS NOT NULL AND charter_group <> ''
          GROUP BY charter_group ORDER BY cnt DESC, charter_group"
    );
    $st4->execute([$u['employee_no'], $from, $to]);
    $byGroup = [];
    foreach ($st4->fetchAll() as $r) {
        $byGroup[] = ['charter_group' => $r['charter_group'], 'count' => (int) $r['cnt'], 'dist' => (int) $r['dist']];
    }

    // 運行種別別（当月）: work_type 未設定(旧データ)は is_charter から補完
    $typeExpr = work_type_expr();
    $st5 = pdo()->prepare(
        "SELECT $typeExpr AS work_type, COUNT(*) AS cnt, COALESCE(SUM($distExpr),0) AS dist
           FROM t_work_record
          WHERE employee_no = ? AND work_date >= ? AND work_date < ? AND status = 2
          GROUP BY $typeExpr ORDER BY cnt DESC"
    );
    $st5->execute([$u['employee_no'], $from, $to]);
    $byType = [];
    foreach ($st5->fetchAll() as $r) {
        $byType[] = ['work_type' => $r['work_type'], 'count' => (int) $r['cnt'], 'dist' => (int) $r['dist']];
    }

    json_ok([
        'days'       => (int) $m['days'],
        'month_dist' => (int) $m['month_dist'],
        'total_dist' => (int) $t['total_dist'],
        'by_type'    => $byType,
        'by_vehicle' => $byVehicle,
        'by_group'   => $byGroup,
    ]);
}

/** 運行種別のSQL式（work_type 未設定の旧データは is_charter から補完） */
function work_type_expr(): string
{
    return "COALESCE(NULLIF(work_type,''), CASE WHEN is_charter = 1 THEN '貸切' ELSE '路線' END)";
}

/** 年次集計：月別走行距離(12) / 運行種別・車両・方面(年間) */
function handle_summary_year(): void
{
    $u    = require_auth();
    $year = (int) require_param('year');
    $from = sprintf('%04d-01-01', $year);
    $to   = sprintf('%04d-01-01', $year + 1);
    $distExpr = '(COALESCE(distance1,0)+COALESCE(distance2,0)+COALESCE(distance3,0)+COALESCE(distance4,0))';
    $typeExpr = work_type_expr();
    $args = [$u['employee_no'], $from, $to];

    // 月別走行距離（1..12、無い月は0）
    $st = pdo()->prepare(
        "SELECT MONTH(work_date) AS m, COALESCE(SUM($distExpr),0) AS dist
           FROM t_work_record
          WHERE employee_no = ? AND work_date >= ? AND work_date < ? AND status = 2
          GROUP BY MONTH(work_date)"
    );
    $st->execute($args);
    $monthly = array_fill(1, 12, 0);
    foreach ($st->fetchAll() as $r) { $monthly[(int) $r['m']] = (int) $r['dist']; }
    $monthDist = [];
    for ($i = 1; $i <= 12; $i++) { $monthDist[] = $monthly[$i]; }

    // 運行種別（年間）
    $st2 = pdo()->prepare(
        "SELECT $typeExpr AS k, COUNT(*) AS cnt, COALESCE(SUM($distExpr),0) AS dist
           FROM t_work_record WHERE employee_no = ? AND work_date >= ? AND work_date < ? AND status = 2
          GROUP BY $typeExpr ORDER BY cnt DESC"
    );
    $st2->execute($args);
    $byType = [];
    foreach ($st2->fetchAll() as $r) { $byType[] = ['label' => $r['k'], 'count' => (int) $r['cnt'], 'dist' => (int) $r['dist']]; }

    // 車両別（年間、縦持ち展開）
    $vsql = '';
    $vparams = [];
    for ($i = 1; $i <= 4; $i++) {
        if ($vsql !== '') { $vsql .= ' UNION ALL '; }
        $vsql .= "SELECT vehicle_no$i AS vno, COALESCE(distance$i,0) AS dist
                    FROM t_work_record WHERE employee_no = ? AND work_date >= ? AND work_date < ? AND status = 2";
        array_push($vparams, $u['employee_no'], $from, $to);
    }
    $st3 = pdo()->prepare(
        "SELECT vno AS k, COUNT(*) AS cnt, COALESCE(SUM(dist),0) AS dist FROM ($vsql) x
          WHERE vno IS NOT NULL AND vno <> '' GROUP BY vno ORDER BY dist DESC, vno"
    );
    $st3->execute($vparams);
    $byVehicle = [];
    foreach ($st3->fetchAll() as $r) { $byVehicle[] = ['label' => $r['k'], 'count' => (int) $r['cnt'], 'dist' => (int) $r['dist']]; }

    // 方面別（年間）
    $st4 = pdo()->prepare(
        "SELECT dest AS k, COUNT(*) AS cnt, COALESCE(SUM($distExpr),0) AS dist
           FROM t_work_record WHERE employee_no = ? AND work_date >= ? AND work_date < ? AND status = 2
            AND dest IS NOT NULL AND dest <> ''
          GROUP BY dest ORDER BY cnt DESC"
    );
    $st4->execute($args);
    $byDest = [];
    foreach ($st4->fetchAll() as $r) { $byDest[] = ['label' => $r['k'], 'count' => (int) $r['cnt'], 'dist' => (int) $r['dist']]; }

    json_ok([
        'year'        => $year,
        'month_dist'  => $monthDist,
        'total_dist'  => array_sum($monthDist),
        'by_type'     => $byType,
        'by_vehicle'  => $byVehicle,
        'by_dest'     => $byDest,
    ]);
}

/** 年間メモをGeminiで要約 */
function handle_summary_year_memo(): void
{
    $u    = require_auth();
    $year = (int) require_param('year');
    $from = sprintf('%04d-01-01', $year);
    $to   = sprintf('%04d-01-01', $year + 1);

    $st = pdo()->prepare(
        "SELECT work_date, memo FROM t_work_record
          WHERE employee_no = ? AND work_date >= ? AND work_date < ? AND status = 2
            AND memo IS NOT NULL AND memo <> '' ORDER BY work_date"
    );
    $st->execute([$u['employee_no'], $from, $to]);
    $notes = [];
    foreach ($st->fetchAll() as $r) { $notes[] = $r['work_date'] . '：' . $r['memo']; }
    if (!$notes) { json_ok(['summary' => 'この年のメモはありません。', 'count' => 0]); }

    $cfg = $GLOBALS['CONFIG']['gemini'] ?? [];
    $key = $cfg['api_key'] ?? '';
    if ($key === '' || $key === 'YOUR_GEMINI_API_KEY') {
        json_err('gemini_unconfigured', 'GeminiのAPIキーが未設定です（config.php の gemini.api_key）。', 501);
    }
    $model  = $cfg['model'] ?? 'gemini-flash-latest';
    $prompt = "あなたはバス会社の管理者アシスタントです。\n"
            . "以下は{$year}年のバス乗務員の業務メモ（日付：内容）です。\n"
            . "研修の進捗、事故・注意事項、車両の不具合、特記事項などを、日本語で簡潔に箇条書き中心にまとめてください。\n\n"
            . implode("\n", $notes);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $payload = json_encode(['contents' => [['parts' => [['text' => $prompt]]]]], JSON_UNESCAPED_UNICODE);

    // 一時的な混雑(429/500/503)は自動リトライ（指数バックオフ）
    $attempts = 3;
    $data = null;
    $lastErr = '';
    for ($i = 0; $i < $attempts; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 45,
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) { $lastErr = '通信エラー ' . $errno; if ($i < $attempts - 1) { sleep(1 + $i); continue; } break; }
        $data  = json_decode((string) $resp, true);
        $ecode = (int) ($data['error']['code'] ?? ($http >= 400 ? $http : 0));
        if (in_array($ecode, [429, 500, 503], true)) {
            $lastErr = 'Gemini混雑中(' . $ecode . ')';
            if ($i < $attempts - 1) { sleep(1 + $i); continue; } // 1s, 2s
        }
        break;
    }

    if ($data === null) { json_err('gemini_failed', 'Gemini' . ($lastErr ?: '通信失敗') . '。少し待って再実行してください。', 502); }
    if (isset($data['error'])) {
        $ec = (int) ($data['error']['code'] ?? 0);
        $msg = in_array($ec, [429, 500, 503], true)
             ? 'Geminiが混雑中です（' . $ec . '）。少し待ってから再度お試しください。'
             : 'Gemini Error ' . $ec . ': ' . ($data['error']['message'] ?? '');
        json_err('gemini_failed', $msg, 502);
    }
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($text === null) { json_err('gemini_failed', 'Geminiの応答を解釈できませんでした。', 502); }
    json_ok(['summary' => $text, 'count' => count($notes)]);
}

/** 月次CSV用：当月の記録明細行を返す（CSVはフロントで生成） */
function handle_record_export(): void
{
    $u     = require_auth();
    $year  = (int) require_param('year');
    $month = (int) require_param('month');

    $from = sprintf('%04d-%02d-01', $year, $month);
    $to   = (new DateTime($from))->modify('first day of next month')->format('Y-m-d');
    $distExpr = '(COALESCE(distance1,0)+COALESCE(distance2,0)+COALESCE(distance3,0)+COALESCE(distance4,0))';

    $st = pdo()->prepare(
        "SELECT work_date, dia_no, is_charter, charter_group,
                vehicle_no1, distance1, vehicle_no2, distance2,
                vehicle_no3, distance3, vehicle_no4, distance4,
                $distExpr AS distance_total, start_call, end_call, status
           FROM t_work_record
          WHERE employee_no = ? AND work_date >= ? AND work_date < ?
          ORDER BY work_date, dia_no"
    );
    $st->execute([$u['employee_no'], $from, $to]);
    json_ok(['rows' => $st->fetchAll()]);
}

function handle_record_get(): void
{
    $u  = require_auth();
    $id = (int) require_param('id');

    $st = pdo()->prepare('SELECT * FROM t_work_record WHERE work_record_id = ? AND employee_no = ?');
    $st->execute([$id, $u['employee_no']]);
    $rec = $st->fetch();
    if (!$rec) {
        json_err('not_found', '記録が見つかりません。', 404);
    }
    $cs = pdo()->prepare('SELECT * FROM t_work_course WHERE work_record_id = ? ORDER BY seq');
    $cs->execute([$id]);
    $rec['courses'] = $cs->fetchAll();
    json_ok(['record' => $rec]);
}

/** 下書き保存（upsert）。ヘッダ＋コース明細を一括保存。登録前後どちらでも呼べる。 */
function handle_record_save(): void
{
    $u       = require_auth();
    $b       = request_body();
    $courses = $b['courses'] ?? [];
    if (!is_array($courses)) {
        json_err('bad_courses', 'courses は配列で指定してください。', 422);
    }

    $fields = record_writable_fields();
    $db     = pdo();
    $db->beginTransaction();
    try {
        $id = isset($b['work_record_id']) ? (int) $b['work_record_id'] : 0;

        if ($id > 0) {
            // 所有チェック
            $chk = $db->prepare('SELECT work_record_id FROM t_work_record WHERE work_record_id = ? AND employee_no = ?');
            $chk->execute([$id, $u['employee_no']]);
            if (!$chk->fetch()) {
                $db->rollBack();
                json_err('not_found', '記録が見つかりません。', 404);
            }
            $sets = [];
            $vals = [];
            foreach ($fields as $f) {
                if (array_key_exists($f, $b)) {
                    $sets[] = "$f = ?";
                    $vals[] = $b[$f];
                }
            }
            if ($sets) {
                $vals[] = $id;
                $db->prepare('UPDATE t_work_record SET ' . implode(', ', $sets) . ' WHERE work_record_id = ?')
                   ->execute($vals);
            }
        } else {
            // 新規：同一(社員×業務年月日×ダイヤ番号)の重複を事前チェック（貸切=dia_no無しは対象外）
            $wd = $b['work_date'] ?? null;
            $dn = $b['dia_no']    ?? null;
            if ($dn !== null && $dn !== '' && $wd) {
                $dup = $db->prepare('SELECT work_record_id FROM t_work_record WHERE employee_no = ? AND work_date = ? AND dia_no = ?');
                $dup->execute([$u['employee_no'], $wd, $dn]);
                $exist = $dup->fetchColumn();
                if ($exist) {
                    $db->rollBack();
                    json_err('duplicate_record',
                        'この業務年月日・ダイヤ番号の記録は既にあります。一覧から開いて編集してください。', 409);
                }
            }
            // employee_no を強制、status 未指定なら 1(下書き)
            $cols = ['employee_no'];
            $ph   = ['?'];
            $vals = [$u['employee_no']];
            foreach ($fields as $f) {
                if (array_key_exists($f, $b)) {
                    $cols[] = $f;
                    $ph[]   = '?';
                    $vals[] = $b[$f];
                }
            }
            if (!in_array('status', $cols, true)) {
                $cols[] = 'status';
                $ph[]   = '?';
                $vals[] = 1;
            }
            $db->prepare('INSERT INTO t_work_record (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')')
               ->execute($vals);
            $id = (int) $db->lastInsertId();
        }

        // コースは全消し→再挿入（順番=送信順で再採番）
        $db->prepare('DELETE FROM t_work_course WHERE work_record_id = ?')->execute([$id]);
        $ins = $db->prepare(
            'INSERT INTO t_work_course
                (work_record_id, seq, category, vehicle_no, start_place, end_place, departure_time, arrival_time, note)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $seq = 1;
        foreach ($courses as $c) {
            $ins->execute([
                $id,
                (int) ($c['seq'] ?? $seq),
                $c['category']       ?? null,
                $c['vehicle_no']     ?? null,
                $c['start_place']    ?? null,
                $c['end_place']      ?? null,
                $c['departure_time'] ?? null,
                $c['arrival_time']   ?? null,
                $c['note']           ?? null,
            ]);
            $seq++;
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    json_ok(['work_record_id' => $id]);
}

/** 入力完了：走行キロ(N)=入庫(N)-出庫(N) を計算保存し、status=2(登録済) にする。 */
function handle_record_register(): void
{
    $u  = require_auth();
    $id = (int) require_param('id');

    $st = pdo()->prepare(
        'SELECT dest, meter_out1, meter_in1, meter_out2, meter_in2,
                meter_out3, meter_in3, meter_out4, meter_in4
           FROM t_work_record WHERE work_record_id = ? AND employee_no = ?'
    );
    $st->execute([$id, $u['employee_no']]);
    $r = $st->fetch();
    if (!$r) {
        json_err('not_found', '記録が見つかりません。', 404);
    }
    $dist = static function ($out, $in) {
        return ($out !== null && $in !== null) ? ((int) $in - (int) $out) : null;
    };

    // 方面(dest)未入力なら、実車コース(category=3)の到着地を重複除去・順序保持で要約して補完
    $destVal = $r['dest'];
    if ($destVal === null || trim((string) $destVal) === '') {
        $cs = pdo()->prepare(
            "SELECT end_place FROM t_work_course
              WHERE work_record_id = ? AND category = '3' AND end_place IS NOT NULL AND end_place <> ''
              ORDER BY seq"
        );
        $cs->execute([$id]);
        $seen = [];
        foreach ($cs->fetchAll(PDO::FETCH_COLUMN) as $ep) {
            if (!in_array($ep, $seen, true)) { $seen[] = $ep; }
        }
        $destVal = $seen ? mb_substr(implode('・', $seen), 0, 200) : null;
    }

    pdo()->prepare(
        'UPDATE t_work_record
            SET distance1 = ?, distance2 = ?, distance3 = ?, distance4 = ?, dest = ?, status = 2
          WHERE work_record_id = ?'
    )->execute([
        $dist($r['meter_out1'], $r['meter_in1']),
        $dist($r['meter_out2'], $r['meter_in2']),
        $dist($r['meter_out3'], $r['meter_in3']),
        $dist($r['meter_out4'], $r['meter_in4']),
        $destVal,
        $id,
    ]);

    // 登録＝実績確定時に、この記録の回送パターンを運転者別ルールとして学習(上書き)
    try { learn_deadhead_rules(pdo(), (string) $u['employee_no'], $id); }
    catch (Throwable $e) { /* 学習失敗は登録本体を妨げない */ }

    json_ok(['work_record_id' => $id, 'status' => 2]);
}

/**
 * この業務記録の「実車〜実車の間の回送run」を運転者別ルールに学習(upsert・上書き)。
 *   キー = (社員, 前実車到着地=run先頭回送の始発地, 次実車始発地=run末尾回送の到着地)
 *   区間 = 各回送の {到着地, 移動分=着-発}。両時刻が揃い 0<分<=120 のrunのみ。
 */
function learn_deadhead_rules(PDO $db, string $employeeNo, int $recordId): void
{
    $cs = $db->prepare(
        'SELECT category, start_place, end_place, departure_time, arrival_time
           FROM t_work_course WHERE work_record_id = ? ORDER BY seq'
    );
    $cs->execute([$recordId]);
    $rows = $cs->fetchAll();
    $toMin = static function ($t) {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', (string) $t, $m)) return null;
        return (int) $m[1] * 60 + (int) $m[2];
    };

    // category==2(回送) の連続runを抽出
    $runs = [];
    $cur  = [];
    foreach ($rows as $r) {
        if ((int) $r['category'] === 2) { $cur[] = $r; }
        elseif ($cur) { $runs[] = $cur; $cur = []; }
    }
    if ($cur) $runs[] = $cur;

    $selRule = $db->prepare('SELECT rule_id FROM t_deadhead_rule WHERE employee_no = ? AND from_place = ? AND to_place = ?');
    $insRule = $db->prepare('INSERT INTO t_deadhead_rule (employee_no, from_place, to_place) VALUES (?,?,?)
                             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP');
    $delLeg  = $db->prepare('DELETE FROM t_deadhead_rule_leg WHERE rule_id = ?');
    $insLeg  = $db->prepare('INSERT INTO t_deadhead_rule_leg (rule_id, seq, waypoint, travel_min) VALUES (?,?,?,?)');

    foreach ($runs as $run) {
        $from = trim((string) ($run[0]['start_place'] ?? ''));
        $to   = trim((string) ($run[count($run) - 1]['end_place'] ?? ''));
        if ($from === '' || $to === '') continue;
        // 区間(移動分)を検証しつつ組み立て
        $legs = [];
        $ok = true;
        foreach ($run as $k) {
            $wp = trim((string) ($k['end_place'] ?? ''));
            $d = $toMin($k['departure_time']);
            $a = $toMin($k['arrival_time']);
            if ($wp === '' || $d === null || $a === null) { $ok = false; break; }
            $mins = $a - $d;
            if ($mins <= 0 || $mins > 120) { $ok = false; break; } // 待ち込み等の異常値は学習しない
            $legs[] = ['waypoint' => $wp, 'travel_min' => $mins];
        }
        if (!$ok || !$legs) continue;

        $db->beginTransaction();
        try {
            $insRule->execute([$employeeNo, $from, $to]);
            $selRule->execute([$employeeNo, $from, $to]);
            $ruleId = (int) $selRule->fetchColumn();
            $delLeg->execute([$ruleId]);
            $seq = 1;
            foreach ($legs as $lg) { $insLeg->execute([$ruleId, $seq++, $lg['waypoint'], $lg['travel_min']]); }
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); }
    }
}

function handle_record_delete(): void
{
    $u  = require_auth();
    $id = (int) require_param('id');
    $st = pdo()->prepare('DELETE FROM t_work_record WHERE work_record_id = ? AND employee_no = ?');
    $st->execute([$id, $u['employee_no']]);
    if ($st->rowCount() === 0) {
        json_err('not_found', '記録が見つからないか、権限がありません。', 404);
    }
    json_ok(['deleted' => $id]); // t_work_course は ON DELETE CASCADE で連動削除
}

/* ================================================================== */
/*  PDF（サーバーサイド生成）                                          */
/* ================================================================== */
function handle_pdf_generate(): void
{
    $u   = require_auth();
    $id  = (int) require_param('id');
    $cfg = $GLOBALS['CONFIG']['pdf'];

    // 記録ヘッダ＋運転者名を取得（所有チェック込み）
    $st = pdo()->prepare(
        'SELECT r.*, e.name AS driver_name
           FROM t_work_record r
           JOIN m_employee e ON e.employee_no = r.employee_no
          WHERE r.work_record_id = ? AND r.employee_no = ?'
    );
    $st->execute([$id, $u['employee_no']]);
    $rec = $st->fetch();
    if (!$rec) {
        json_err('not_found', '記録が見つかりません。', 404);
    }
    // コース明細
    $cs = pdo()->prepare(
        'SELECT seq, category, vehicle_no, start_place, end_place, departure_time, arrival_time, note
           FROM t_work_course WHERE work_record_id = ? ORDER BY seq'
    );
    $cs->execute([$id]);
    $rec['courses'] = $cs->fetchAll();

    // Node レンダラ(report/generate.js)へ渡すデータを一時JSONに書き出す
    $tmp = tempnam(sys_get_temp_dir(), 'sbrec_');
    file_put_contents($tmp, json_encode($rec, JSON_UNESCAPED_UNICODE));

    // 固定ファイル名で上書き（都度生成でファイルが増えるのを防ぐ）
    $outFile = 'record_' . $id . '.pdf';
    $outPath = rtrim($cfg['out_dir'], '/') . '/' . $outFile;

    // Node CLI: node generate.js --record <tmp.json> --out <out.pdf>
    // ※ロリポップでNode/shell_execが不可の場合は README の代替案を参照。probe.php で事前判定。
    if (!function_exists('shell_exec')) {
        @unlink($tmp);
        json_err('pdf_unavailable', 'この環境では shell_exec が無効です。Node連携方式を見直してください。', 501);
    }
    $cmd = escapeshellarg($cfg['node_bin'])
         . ' ' . escapeshellarg($cfg['script'])
         . ' --record ' . escapeshellarg($tmp)
         . ' --out ' . escapeshellarg($outPath)
         . ' 2>&1';
    $output = shell_exec($cmd);
    @unlink($tmp);

    if (!is_file($outPath)) {
        $detail = ($GLOBALS['CONFIG']['debug'] ?? false) ? (': ' . trim((string) $output)) : '';
        json_err('pdf_failed', 'PDF生成に失敗しました' . $detail, 500);
    }
    // 生成済みPDFファイル名を記録（一覧のPDF表示ボタン用）
    pdo()->prepare('UPDATE t_work_record SET pdf_file = ? WHERE work_record_id = ? AND employee_no = ?')
         ->execute([$outFile, $id, $u['employee_no']]);
    json_ok([
        'url'  => rtrim($cfg['out_url'], '/') . '/' . $outFile,
        'file' => $outFile,
    ]);
}

/* ================================================================== */
/*  ホワイトリスト                                                     */
/* ================================================================== */
/** t_work_record で保存を許可する列（PK/employee_no/timestamps を除く） */
function record_writable_fields(): array
{
    return [
        'dia_id', 'dia_no', 'is_charter', 'work_date',
        'vehicle_no1', 'meter_out1', 'meter_in1', 'distance1',
        'vehicle_no2', 'meter_out2', 'meter_in2', 'distance2',
        'vehicle_no3', 'meter_out3', 'meter_in3', 'distance3',
        'vehicle_no4', 'meter_out4', 'meter_in4', 'distance4',
        'start_place', 'end_place', 'end_garage_check',
        'start_call', 'end_call',
        'guide_name', 'charter_group',
        'fuel_place', 'fuel_time', 'fuel_amount', 'newspaper',
        'support_deadhead', 'wheelchair',
        'lost_item_flag', 'lost_item_detail',
        'contact', 'status',
        'dest', 'memo',            // 方面 / メモ（旧しまバス乗務履歴管理からの移行項目）
        'work_type',               // 運行種別: 路線/貸切/研修/出張/その他
    ];
}
