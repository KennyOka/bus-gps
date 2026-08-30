<?php
/*
 * next_buses.php — 指定バス停(busstop_id)を、指定時刻以降に通過する後続バスを返す。
 * 「このバス停をこの後通るバス」を 定刻の近い順に最大8件。読み取り専用(SELECT)。
 *
 * 【リクエスト】 GET next_buses.php?busstop_id=123&day_type=1&after=13:45
 *   busstop_id : バス停ID
 *   day_type   : 1=平日 / 2=土日祝
 *   after      : この時刻(HH:MM)より後の便。省略時は現在時刻
 * 【配置】乗務記録アプリの api/ ディレクトリ(config.php と同じ場所)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$busstop_id = isset($_GET['busstop_id']) ? (int)$_GET['busstop_id'] : 0;
$day_type   = isset($_GET['day_type']) ? (int)$_GET['day_type'] : 0;
$after      = isset($_GET['after']) ? preg_replace('/[^0-9:]/', '', $_GET['after']) : date('H:i');
if (strlen($after) === 5) { $after .= ':00'; }
if ($busstop_id <= 0 || ($day_type !== 1 && $day_type !== 2)) {
    http_response_code(400); echo json_encode(['error'=>'busstop_id と day_type(1/2) を指定してください'], JSON_UNESCAPED_UNICODE); exit;
}

$__cfg = require __DIR__ . '/config.php';
if (is_array($__cfg) && isset($__cfg['db'])) { $config = $__cfg; }
$db = isset($config['db']) ? $config['db'] : null;
if (!is_array($db) || !isset($db['host'])) {
    http_response_code(500); echo json_encode(['error'=>'config.php の db 設定を読めませんでした'], JSON_UNESCAPED_UNICODE); exit;
}
$mysqli = @new mysqli($db['host'], $db['user'], $db['pass'], $db['name'] ?? ($db['dbname'] ?? null));
if ($mysqli->connect_errno) {
    http_response_code(500); echo json_encode(['error'=>'DB接続に失敗しました: ' . $mysqli->connect_error], JSON_UNESCAPED_UNICODE); exit;
}
$mysqli->set_charset($db['charset'] ?? 'utf8mb4');

$sql = "SELECT d.dia_no,
               TIME_FORMAT(cs.scheduled_time, '%H:%i') AS t,
               COALESCE(c.end_name, e.name) AS end_place
        FROM m_dia_course_stop cs
        JOIN m_dia_course c ON c.dia_course_id = cs.dia_course_id
        JOIN m_dia d ON d.dia_id = c.dia_id
        LEFT JOIN m_busstop e ON e.busstop_id = c.end_busstop_id
        WHERE cs.busstop_id = ? AND d.day_type = ? AND d.is_active = 1 AND c.category = 3
          AND cs.scheduled_time > ?
        ORDER BY cs.scheduled_time
        LIMIT 8";
$stmt = $mysqli->prepare($sql);
if (!$stmt) { http_response_code(500); echo json_encode(['error'=>'prepare失敗: ' . $mysqli->error], JSON_UNESCAPED_UNICODE); exit; }
$stmt->bind_param('iis', $busstop_id, $day_type, $after);
$stmt->execute();
$res = $stmt->get_result();

$buses = [];
while ($row = $res->fetch_assoc()) {
    $buses[] = [
        'dia_no'    => $row['dia_no'],
        'end_place' => $row['end_place'],
        'time'      => $row['t'],
    ];
}
$stmt->close(); $mysqli->close();

echo json_encode(['busstop_id'=>$busstop_id, 'day_type'=>$day_type, 'after'=>$after, 'count'=>count($buses), 'buses'=>$buses], JSON_UNESCAPED_UNICODE);
