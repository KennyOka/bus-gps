<?php
/*
 * busstops_admin.php — バス停エディタ用の読み取りAPI。
 * m_busstop の is_active=1 を「座標が未登録(NULL)の停も含めて」全件返す。
 * busstops.php と違い、座標NULLも返し・キャッシュしない(常に最新)。読み取り専用(SELECT)。
 *
 * 【リクエスト】 GET busstops_admin.php
 * 【配置】乗務記録アプリの api/ ディレクトリ(config.php と同じ場所)。
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

// ==== config.php を再利用してDB接続 ====
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

$sql = "SELECT busstop_id, name, direction, latitude, longitude, kana, src_busstop_id
        FROM m_busstop
        WHERE is_active = 1
        ORDER BY kana, name";
$res = $mysqli->query($sql);
if ($res === false) {
    http_response_code(500); echo json_encode(['error'=>'クエリに失敗しました: ' . $mysqli->error], JSON_UNESCAPED_UNICODE); exit;
}

$stops = [];
while ($row = $res->fetch_assoc()) {
    $hasCoord = ($row['latitude'] !== null && $row['longitude'] !== null);
    $stops[] = [
        'busstop_id'     => (int)$row['busstop_id'],
        'name'           => $row['name'],
        'kana'           => $row['kana'],
        'direction'      => (int)$row['direction'],
        'lat'            => $hasCoord ? (float)$row['latitude']  : null,
        'lng'            => $hasCoord ? (float)$row['longitude'] : null,
        'has_coord'      => $hasCoord,
        'src_busstop_id' => $row['src_busstop_id'],
    ];
}
$mysqli->close();

echo json_encode(['stops'=>$stops, 'count'=>count($stops), 'generated_at'=>date('c')], JSON_UNESCAPED_UNICODE);
