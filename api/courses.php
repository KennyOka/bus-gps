<?php
/*
 * courses.php — 指定ダイヤ(dia_no × day_type)のコース一覧を返す。
 * 仕様書「運行支援アプリ向けDB参照仕様」クエリB 相当。読み取り専用(SELECT)。
 *
 * 【リクエスト】 GET courses.php?dia_no=31&day_type=1
 *   dia_no   : ダイヤ番号(数字。4桁へ自動ゼロ埋め。例 31→0031)
 *   day_type : 1=平日 / 2=土日祝
 * 【配置】乗務記録アプリの api/ ディレクトリ(config.php と同じ場所)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ==== config.php を再利用してDB接続(db: host/name/user/pass/charset) ====
$__cfg = require __DIR__ . '/config.php';
if (is_array($__cfg) && isset($__cfg['db'])) { $config = $__cfg; }
$db = isset($config['db']) ? $config['db'] : null;
if (!is_array($db) || !isset($db['host'])) {
    http_response_code(500); echo json_encode(['error' => 'config.php の db 設定を読めませんでした'], JSON_UNESCAPED_UNICODE); exit;
}
$mysqli = @new mysqli($db['host'], $db['user'], $db['pass'], $db['name'] ?? ($db['dbname'] ?? null));
if ($mysqli->connect_errno) {
    http_response_code(500); echo json_encode(['error' => 'DB接続に失敗しました: ' . $mysqli->connect_error], JSON_UNESCAPED_UNICODE); exit;
}
$mysqli->set_charset($db['charset'] ?? 'utf8mb4');

// ==== パラメータ ====
$dia_no   = isset($_GET['dia_no']) ? preg_replace('/[^0-9]/', '', $_GET['dia_no']) : '';
$dia_no   = str_pad($dia_no, 4, '0', STR_PAD_LEFT);   // CHAR(4) 0001-9999
$day_type = isset($_GET['day_type']) ? (int)$_GET['day_type'] : 0;
if ($dia_no === '0000' || ($day_type !== 1 && $day_type !== 2)) {
    http_response_code(400);
    echo json_encode(['error' => 'dia_no と day_type(1=平日/2=土日祝) を指定してください'], JSON_UNESCAPED_UNICODE); exit;
}

// ==== クエリB(プレースホルダで安全に) ====
$sql = "SELECT c.dia_course_id, c.seq, c.category, c.digital_no, c.touch_no,
               COALESCE(c.start_name, s.name) AS start_place,
               COALESCE(c.end_name,   e.name) AS end_place,
               TIME_FORMAT(c.departure_time, '%H:%i') AS departure_time,
               (SELECT TIME_FORMAT(MAX(cs.scheduled_time), '%H:%i')
                  FROM m_dia_course_stop cs WHERE cs.dia_course_id = c.dia_course_id) AS arrival_time,
               c.settlement_flag, c.note
        FROM m_dia d
        JOIN m_dia_course c ON c.dia_id = d.dia_id
        LEFT JOIN m_busstop s ON s.busstop_id = c.start_busstop_id
        LEFT JOIN m_busstop e ON e.busstop_id = c.end_busstop_id
        WHERE d.dia_no = ? AND d.day_type = ? AND d.is_active = 1
        ORDER BY c.seq";
$stmt = $mysqli->prepare($sql);
if (!$stmt) { http_response_code(500); echo json_encode(['error' => 'prepare失敗: ' . $mysqli->error], JSON_UNESCAPED_UNICODE); exit; }
$stmt->bind_param('si', $dia_no, $day_type);
$stmt->execute();
$res = $stmt->get_result();

$courses = [];
while ($row = $res->fetch_assoc()) {
    $courses[] = [
        'dia_course_id'   => (int)$row['dia_course_id'],
        'seq'             => (int)$row['seq'],
        'category'        => (int)$row['category'],       // 1点検 2回送 3実車 4未使用 5清掃点検
        'digital_no'      => $row['digital_no'],
        'touch_no'        => $row['touch_no'],            // 系統番号
        'start_place'     => $row['start_place'],
        'end_place'       => $row['end_place'],
        'departure_time'  => $row['departure_time'],      // HH:MM
        'arrival_time'    => $row['arrival_time'],         // HH:MM(最終停の定刻)
        'settlement_flag' => (int)$row['settlement_flag'],
        'note'            => $row['note'],
    ];
}
$stmt->close();
$mysqli->close();

echo json_encode(
    ['dia_no' => $dia_no, 'day_type' => $day_type, 'count' => count($courses), 'courses' => $courses],
    JSON_UNESCAPED_UNICODE
);
