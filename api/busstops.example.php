<?php
/*
 * m_busstop 読み取り専用 JSON API 【テンプレート】
 * しまバス乗務記録DB のバス停座標を、GPS検証PWA(運行支援アプリ)へ配信する。
 * 仕様書「運行支援アプリ向けDB参照仕様」クエリA 相当。
 *
 * 【使い方】
 *   1. このファイルを busstops.php にリネーム
 *   2. 乗務記録アプリの api/ ディレクトリ(= config.php と同じ場所)に配置
 *      → 接続情報は config.php を再利用するので、このファイルには書きません(安全)
 *   3. URL 例: https://<あなたのドメイン>/<アプリパス>/api/busstops.php
 *   4. GPS検証アプリ(gps_check.html)の「🌐同期」にこのURLを貼る
 *
 * 【安全】m_busstop の is_active=1・座標ありの行のみ返す読み取り専用(SELECT)。書き込み不可。
 * 【前提】config.php の db セクション = ['host'=>, 'name'=>, 'user'=>, 'pass'=>, 'charset'=>]
 *        (実DBに準拠。DB名のキーは 'name')。config.phpが配列をreturnする形/変数定義する形の
 *        どちらでも動くようにしてある。
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');      // バス停座標は公開情報。読み取り専用のため * で許可
header('Cache-Control: public, max-age=3600');  // 1時間キャッシュ可

// ==== config.php を再利用してDB接続(db セクション: host/name/user/pass/charset) ====
$__cfg = require __DIR__ . '/config.php';
if (is_array($__cfg) && isset($__cfg['db'])) { $config = $__cfg; }  // config.phpが配列をreturnする形
// それ以外は config.php が $config を定義済み(変数定義する形)
$db = isset($config['db']) ? $config['db'] : null;
if (!is_array($db) || !isset($db['host'])) {
    http_response_code(500);
    echo json_encode(['error' => 'config.php の db 設定を読めませんでした'], JSON_UNESCAPED_UNICODE);
    exit;
}
$dbname = $db['name'] ?? ($db['dbname'] ?? null);   // ★DB名のキーは 'name'
$mysqli = @new mysqli($db['host'], $db['user'], $db['pass'], $dbname);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続に失敗しました: ' . $mysqli->connect_error], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset($db['charset'] ?? 'utf8mb4');

$sql = "SELECT busstop_id, name, direction, latitude, longitude, kana, src_busstop_id
        FROM m_busstop
        WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL
        ORDER BY busstop_id";

$res = $mysqli->query($sql);
if ($res === false) {
    http_response_code(500);
    echo json_encode(['error' => 'クエリに失敗しました: ' . $mysqli->error], JSON_UNESCAPED_UNICODE);
    exit;
}

$stops = [];
while ($row = $res->fetch_assoc()) {
    $stops[] = [
        'busstop_id'     => (int)$row['busstop_id'],
        'name'           => $row['name'],
        'direction'      => (int)$row['direction'],   // 1=上り 2=下り 9=その他
        'lat'            => (float)$row['latitude'],
        'lng'            => (float)$row['longitude'],
        'kana'           => $row['kana'],
        'src_busstop_id' => $row['src_busstop_id'],
    ];
}
$mysqli->close();

echo json_encode(
    ['stops' => $stops, 'count' => count($stops), 'generated_at' => date('c')],
    JSON_UNESCAPED_UNICODE
);
