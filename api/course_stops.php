<?php
/*
 * course_stops.php — 指定コース(dia_course_id)の停留所別 通過定刻＋座標を返す。
 * 仕様書「運行支援アプリ向けDB参照仕様」クエリC 相当。読み取り専用(SELECT)。
 * 早発モニターの中核データ(seq順の scheduled_time と座標)。
 *
 * 【リクエスト】 GET course_stops.php?dia_course_id=123
 * 【配置】乗務記録アプリの api/ ディレクトリ(config.php と同じ場所)
 * 【注意】m_dia_course_stop は実車コースのみ・整備依存。空なら src_trip_stop 補完(クエリD)が別途必要。
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ==== config.php を再利用してDB接続 ====
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
$dia_course_id = isset($_GET['dia_course_id']) ? (int)$_GET['dia_course_id'] : 0;
if ($dia_course_id <= 0) {
    http_response_code(400); echo json_encode(['error' => 'dia_course_id を指定してください'], JSON_UNESCAPED_UNICODE); exit;
}

// ==== クエリC(プレースホルダで安全に) ====
$sql = "SELECT cs.seq, b.busstop_id, b.name, b.direction, b.latitude, b.longitude,
               TIME_FORMAT(cs.scheduled_time, '%H:%i:%s') AS scheduled_time
        FROM m_dia_course_stop cs
        JOIN m_busstop b ON b.busstop_id = cs.busstop_id
        WHERE cs.dia_course_id = ?
        ORDER BY cs.seq";
$stmt = $mysqli->prepare($sql);
if (!$stmt) { http_response_code(500); echo json_encode(['error' => 'prepare失敗: ' . $mysqli->error], JSON_UNESCAPED_UNICODE); exit; }
$stmt->bind_param('i', $dia_course_id);
$stmt->execute();
$res = $stmt->get_result();

$stops = [];
while ($row = $res->fetch_assoc()) {
    $stops[] = [
        'seq'            => (int)$row['seq'],
        'busstop_id'     => (int)$row['busstop_id'],
        'name'           => $row['name'],
        'direction'      => (int)$row['direction'],
        'lat'            => isset($row['latitude'])  ? (float)$row['latitude']  : null,   // 座標NULLあり
        'lng'            => isset($row['longitude']) ? (float)$row['longitude'] : null,
        'scheduled_time' => $row['scheduled_time'],   // HH:MM:SS(絶対時刻)
    ];
}
$stmt->close();
$mysqli->close();

echo json_encode(
    ['dia_course_id' => $dia_course_id, 'count' => count($stops), 'stops' => $stops],
    JSON_UNESCAPED_UNICODE
);
