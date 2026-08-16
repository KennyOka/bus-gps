<?php
/*
 * m_busstop 読み取り専用 JSON API 【テンプレート】
 * しまバス乗務記録簿DB(Lolipop)のバス停座標を、GPS検証PWAへ配信する。
 *
 * 【使い方】
 *   1. このファイルを busstops.php にコピー
 *   2. $DB_USER / $DB_PASS を実際の値に書き換える
 *   3. busstops.php を Lolipop のウェブスペースにアップロード
 *      （例: /bus-api/busstops.php → https://<あなたのドメイン>/bus-api/busstops.php）
 *   ※ busstops.php(パスワード入り)は .gitignore 済み。公開リポジトリには入りません。
 *
 * 【安全】m_busstop の is_active=1・座標ありの行のみを返す読み取り専用。書き込み不可。
 *        DBパスワードはサーバ側のPHP内だけに存在し、ブラウザには送られません。
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');      // バス停座標は公開情報。読み取り専用のため * で許可
header('Cache-Control: public, max-age=3600');  // 1時間キャッシュ可

// ==== DB接続情報(★ユーザー名とパスワードをご自身で入力してください) ====
$DB_HOST = 'mysql402.phy.lolipop.lan';
$DB_NAME = 'LA11294846-busapp';
$DB_USER = 'YOUR_DB_USER';   // ← 要入力
$DB_PASS = 'YOUR_DB_PASS';   // ← 要入力(このファイルはサーバ内のみ。外部には出ません)

$mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset('utf8mb4');

$sql = "SELECT busstop_id, name, direction, latitude, longitude, kana, src_busstop_id
        FROM m_busstop
        WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL
        ORDER BY busstop_id";

$res = $mysqli->query($sql);
if ($res === false) {
    http_response_code(500);
    echo json_encode(['error' => 'クエリに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stops = [];
while ($row = $res->fetch_assoc()) {
    $stops[] = [
        'busstop_id'     => (int)$row['busstop_id'],
        'name'           => $row['name'],
        'direction'      => (int)$row['direction'],
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
