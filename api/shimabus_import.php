<?php
/**
 * しまバス「時刻表プラス」非公式JSON APIからの取り込み（管理者=role9 専用）。
 *
 *   action=shimabus.importRoute  … 指定バス停(=signage id=busstop_id, 数値)を通る便を
 *                                   丸ごと取り込む。signageは始発便に限らずその停を通る便を返す。
 *   params:
 *     origin(必須, 数値ID)      取り込みの起点/ハブ停。ここのsignageに載る便を取り込む。
 *     keito (任意, 系統番号)     指定時は該当系統のみ。
 *     target_date(YYYYMMDD)     既定=当日
 *     mode(preview|commit)      既定=preview
 *
 *   ※ 起点signageが空の系統(例: 山羊島ホテル前 i=330 の系統2801)は、経路途中のハブ停を
 *      origin に、keito で系統を指定して取り込む。例) origin=80(和光園前) keito=2801
 *   ※ busstop.cgi が座標を返さない停留所(i=330等)は tripdetail 情報で m_busstop を補完(座標NULL)。
 *
 * 取り込み先（db/migration_20260808_add_shimabus_source.sql）:
 *   m_busstop(標柱/緯度経度, src_stop_idで突合) / src_route / src_trip /
 *   src_trip_stop / src_fare / src_import_batch
 *
 * 方針:
 *   - preview: 取得と検証のみ、DB書込なし。commit: トランザクションで一括UPSERT。
 *   - 再取込は「置換」：同一(trip_key,target_date)の停留所時刻・運賃は消して入れ直す。
 *   - 非公式APIにつきリクエスト間隔を空ける（SLEEP_US）。低頻度実行前提。
 *   - スナップショット: target_date とセットで保存。
 *
 * ※ 詳細なAPI仕様はメモ shimabus-busplus-api を参照。
 */
declare(strict_types=1);

const SHIMABUS_SLEEP_US = 200000; // 0.2秒/リクエスト

/** しまバスAPIのベースURL（config で上書き可） */
function shimabus_base(): string
{
    return rtrim($GLOBALS['CONFIG']['shimabus']['base'] ?? 'https://shimabus.busplus.jp/api', '/');
}

/** POST して JSON を連想配列で返す。失敗時は例外。
 *  相手サーバーが一時的に 502/503 等を返すことがあるため、指数バックオフで数回リトライする。 */
function shimabus_post(string $name, array $params, int $retries = 3): array
{
    for ($attempt = 0; ; $attempt++) {
        try {
            return shimabus_post_once($name, $params);
        } catch (RuntimeException $e) {
            // 一時的なエラー(5xx/通信断)のみリトライ。それ以外は即座に投げ直す。
            $msg = $e->getMessage();
            $transient = (bool) preg_match('/HTTP 5\d\d|通信失敗/u', $msg);
            if (!$transient || $attempt >= $retries) throw $e;
            sleep(2 << $attempt);   // 2秒 → 4秒 → 8秒
        }
    }
}

/** 実際の1回ぶんのPOST。 */
function shimabus_post_once(string $name, array $params): array
{
    $url  = shimabus_base() . '/' . $name . '.cgi';
    $body = http_build_query($params);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_USERAGENT      => 'shimabus-rec-importer/1.0',
        ]);
        $res  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0)         throw new RuntimeException("通信失敗({$name}): {$err}");
        if ($code < 200 || $code >= 300) throw new RuntimeException("HTTP {$code} ({$name})");
    } else {
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 20,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) throw new RuntimeException("通信失敗({$name})");
    }

    $json = json_decode((string) $res, true);
    if (!is_array($json)) throw new RuntimeException("JSON解析失敗({$name})");
    return $json;
}

/** 標柱ID(例 "286_02")のサフィックスから方向を推定。_01→1(上) _02→2(下) 他→9。
 *  ※しまバスの _01/_02 は必ずしも上下と一致しない。真の方向は運用で補正前提。 */
function shimabus_dir_from_pole(string $poleId): int
{
    if (preg_match('/_0*(\d+)$/', $poleId, $m)) {
        $n = (int) $m[1];
        if ($n === 1) return 1;
        if ($n === 2) return 2;
    }
    return 9;
}

/** 書込フェーズ用に新しいDB接続を張る。
 *  取得フェーズが長く、pdo() の既存接続が wait_timeout で切れているため必要。 */
function shimabus_fresh_pdo(): PDO
{
    $db  = $GLOBALS['CONFIG']['db'];
    $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
    return new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/** "HH:MM" or "HH:MM:SS" を TIME 文字列へ。空は null。 */
function shimabus_time($v): ?string
{
    $v = trim((string) $v);
    return preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $v) ? $v : null;
}

/* ================================================================== */
/*  起点バス停を始発とする便を取り込む                                  */
/* ================================================================== */
function handle_shimabus_import_route(): void
{
    require_admin();

    $origin = (string) require_param('origin');
    if (!preg_match('/^\d+$/', $origin)) {
        json_err('bad_origin', 'origin は数値のバス停ID(=signage id)で指定してください。', 422);
    }
    $targetDate = (string) param('target_date', date('Ymd'));
    if (!preg_match('/^\d{8}$/', $targetDate)) {
        json_err('bad_date', 'target_date は YYYYMMDD で指定してください。', 422);
    }
    // 便数×外部API呼び出し＋ウェイトで数分かかるため、実行時間の上限を延ばす
    @set_time_limit(3600);
    @ini_set('max_execution_time', '3600');

    $mode  = (string) param('mode', 'preview');
    // 任意: 系統番号で絞り込み。起点signageが空の系統(例 山羊島 i=330 の系統2801)を、
    //       経路途中のハブ停(例 和光園前 80)を origin に指定して取り込むための絞り込み。
    $keito = (string) param('keito', '');
    if ($keito !== '' && !preg_match('/^\d+$/', $keito)) {
        json_err('bad_keito', 'keito は系統番号(数値)で指定してください。', 422);
    }

    // 任意: 便を分割して取り込む(1回のリクエストを短くしたい時)。
    //       例 trip_offset=0&trip_limit=30 → 先頭30便。次は trip_offset=30 …
    $tripOffset = max(0, (int) param('trip_offset', 0));
    $tripLimit  = max(0, (int) param('trip_limit', 0));   // 0=制限なし

    /* ---- 1) 取得（preview/commit 共通） ---- */
    $base = shimabus_post('signagebase', ['id' => $origin]);
    $originName = (string) ($base['stop_name'] ?? '');
    $service    = (string) ($base['service'] ?? '');
    $trips      = is_array($base['trips'] ?? null) ? $base['trips'] : [];
    $tripsTotal = count($trips);
    if ($tripOffset > 0 || $tripLimit > 0) {
        $trips = array_slice($trips, $tripOffset, $tripLimit > 0 ? $tripLimit : null);
    }
    usleep(SHIMABUS_SLEEP_US);

    $tripData = [];   // trip_key => ['route_key','route_name','headsign','stops'=>[...]]
    $tripsNoStops = 0;   // 指定日に運行しない(=停留所が返らない)便
    foreach ($trips as $t) {
        $tripKey = (string) ($t['trip_id'] ?? '');
        if ($tripKey === '') continue;
        // keito 指定時は該当系統のみ（系統2801 が 系統28011 等に部分一致しないよう後方境界を付ける）
        if ($keito !== '' && !preg_match('/系統' . $keito . '(?!\d)/u', $tripKey)) continue;
        $d = shimabus_post('tripdetail', ['trip_id' => $tripKey, 'target_date' => $targetDate]);
        usleep(SHIMABUS_SLEEP_US);
        $stops = [];
        foreach (($d['data'] ?? []) as $s) {
            $stops[] = [
                'seq'        => (int) ($s['stop_sequence'] ?? 0),
                'busstop_id' => (string) ($s['busstop_id'] ?? ''),
                'stop_id'    => (string) ($s['stop_id'] ?? ''),   // 標柱ID
                'stop_name'  => (string) ($s['stop_name'] ?? ''),
                'arr'        => shimabus_time($s['arrival_time'] ?? ''),
                'dep'        => shimabus_time($s['departure_time'] ?? ''),
            ];
        }
        // 指定日に運行しない便は tripdetail が空を返す。空の便を入れるとDBに中身の無い
        // 便が作られてしまうため取り込まない。
        if (!$stops) { $tripsNoStops++; continue; }
        $tripData[$tripKey] = [
            'route_key'  => (string) ($t['route_id'] ?? ''),
            'route_name' => (string) ($t['route'] ?? ''),
            'headsign'   => (string) ($t['headsign'] ?? ''),
            'stops'      => $stops,
        ];
    }

    // signagebase(バス停サイネージ)は当日のダイヤしか返さない。過去/未来日を指定しても
    // 便一覧は当日のものになるため、その便は指定日に運行せず全滅する。
    if (!$tripData) {
        json_err('date_mismatch',
            "指定日({$targetDate})に運行する便が取得できませんでした。便一覧は当日のダイヤ({$service})しか取得できないため、"
            . "取り込みたいダイヤ(平日/土日祝など)が実際に運行する日に実行してください。", 422);
    }

    // 出現する全バス停の緯度経度（標柱別）
    $busstopIds = [];
    foreach ($tripData as $td) {
        foreach ($td['stops'] as $s) {
            if ($s['busstop_id'] !== '') $busstopIds[$s['busstop_id']] = true;
        }
    }
    $busstopIds = array_keys($busstopIds);

    $poles = [];  // pole stop_id => ['name','kana','dir','lat','lon','busstop_id']
    foreach ($busstopIds as $bid) {
        $b = shimabus_post('busstop', ['id' => $bid]);
        usleep(SHIMABUS_SLEEP_US);
        foreach (($b['stops'] ?? []) as $poleId => $p) {
            $poles[(string) $poleId] = [
                'name'       => (string) ($p['stop_name'] ?? ($b['stop_name'] ?? '')),
                'kana'       => (string) ($p['hrkt'] ?? ($b['hrkt'] ?? '')),
                'dir'        => shimabus_dir_from_pole((string) $poleId),
                'lat'        => isset($p['stop_lat']) ? (float) $p['stop_lat'] : null,
                'lon'        => isset($p['stop_lon']) ? (float) $p['stop_lon'] : null,
                'busstop_id' => (string) $bid,
            ];
        }
    }

    // i= 停留所フォールバック: busstop.cgi が標柱(座標)を返さない停留所(例 山羊島ホテル前 i=330)は、
    // tripdetail の停留所情報から標柱を補う(座標はNULL)。これが無いと起点停等が欠落する。
    $poleFallback = 0;
    foreach ($tripData as $td) {
        foreach ($td['stops'] as $s) {
            $pid = $s['stop_id'];
            if ($pid !== '' && !isset($poles[$pid])) {
                $poles[$pid] = [
                    'name'       => $s['stop_name'],
                    'kana'       => '',
                    'dir'        => shimabus_dir_from_pole($pid),
                    'lat'        => null,
                    'lon'        => null,
                    'busstop_id' => $s['busstop_id'],
                ];
                $poleFallback++;
            }
        }
    }

    // 運賃
    // ※ fare.cgi は target_date が「YYYY-MM-DD」でないと内部エラー(ok:false)を返す。
    //   tripdetail 等の YYYYMMDD とは形式が異なるので必ず変換する。
    //   応答は fare が stop_sequence をキーにした連想配列: {"11":{stop_sequence,price,stop_id,...}, ...}
    //
    // fare_mode:
    //   origin(既定) … 始発(from_seq=1)のみ。取得が速く、1リクエストで完了する。
    //   matrix        … 全区間(乗車×降車)。呼び出しが多くWebサーバー側のタイムアウト(504)に
    //                    かかりやすいので、通常は action=shimabus.importFares で分割実行する。
    $fareMode = (string) param('fare_mode', 'origin');
    if (!in_array($fareMode, ['matrix', 'origin'], true)) $fareMode = 'matrix';

    $fareDate = (new DateTime($targetDate))->format('Y-m-d');
    $fares = [];  // trip_key => [ ['from_seq','to_seq','to_stop_id','to_busstop_id','price'], ... ]
    $fareErrors = [];

    // 運賃は「停留所の並び(パターン)」が同じ便なら同一。便ごとに総当たりすると
    // 1便あたり停留所数-1回(73停なら72回)の呼び出しになり非現実的なため、
    // パターン単位で1回だけ取得して同一パターンの便に使い回す。
    $patternOf = [];    // trip_key => signature
    $patternRep = [];   // signature => 代表trip_key
    foreach ($tripData as $tripKey => $td) {
        $sig = implode(',', array_map(static fn($s) => $s['stop_id'], $td['stops']));
        $patternOf[$tripKey] = $sig;
        if (!isset($patternRep[$sig])) $patternRep[$sig] = $tripKey;
    }

    $fareByPattern = [];
    foreach ($patternRep as $sig => $repTrip) {
        $maxSeq = 0;
        foreach ($tripData[$repTrip]['stops'] as $s) { if ($s['seq'] > $maxSeq) $maxSeq = $s['seq']; }
        $fromList = ($fareMode === 'matrix') ? range(1, max(1, $maxSeq - 1)) : [1];

        $rows = [];
        foreach ($fromList as $fromSeq) {
            $f = shimabus_post('fare', ['trip_id' => $repTrip, 'stop_sequence' => $fromSeq, 'target_date' => $fareDate]);
            usleep(SHIMABUS_SLEEP_US);
            if (isset($f['ok']) && !$f['ok']) {   // 取得失敗は記録して次へ(取込全体は止めない)
                $fareErrors[$repTrip . '#' . $fromSeq] = (string) ($f['errmsg'] ?? 'unknown');
                continue;
            }
            foreach (($f['fare'] ?? []) as $seqKey => $x) {
                if (!is_array($x)) continue;
                $rows[] = [
                    'from_seq'      => $fromSeq,
                    'to_seq'        => (int) ($x['stop_sequence'] ?? $seqKey),   // キー側もフォールバックに使う
                    'to_stop_id'    => (string) ($x['stop_id'] ?? ''),
                    'to_busstop_id' => (string) ($x['busstop_id'] ?? ''),
                    'price'         => (int) ($x['price'] ?? 0),
                ];
            }
        }
        $fareByPattern[$sig] = $rows;
    }
    foreach ($tripData as $tripKey => $td) {
        $fares[$tripKey] = $fareByPattern[$patternOf[$tripKey]] ?? [];
    }

    $summary = [
        'origin'        => $origin,
        'origin_name'   => $originName,
        'keito'         => $keito ?: null,
        'service'       => $service,
        'target_date'   => $targetDate,
        'trips'         => count($tripData),
        'trips_total'   => $tripsTotal,                              // signage上の全便数(分割時の目安)
        'trips_no_stops'=> $tripsNoStops,                            // 指定日に運行せず除外した便
        'trip_offset'   => $tripOffset,
        'busstops'      => count($busstopIds),
        'poles'         => count($poles),
        'pole_fallback' => $poleFallback, // i=停留所等の座標なし補完数
        'fare_mode'     => $fareMode,                                // matrix=全区間 / origin=始発のみ
        'fare_patterns' => count($patternRep),                       // 運賃を実際に取得したパターン数
        'fare_rows'     => array_sum(array_map('count', $fares)),
        'fare_errors'   => count($fareErrors),                       // 運賃が取れなかった(便#乗車停)の数
        'fare_error_sample' => array_slice($fareErrors, 0, 3, true), // 原因確認用(先頭3件)
    ];

    if ($mode !== 'commit') {
        json_ok(['mode' => 'preview', 'summary' => $summary, 'committed' => false]);
    }

    /* ---- 2) 書込（commit） ---- */
    // 取得フェーズ(外部API×便数＋ウェイト)で数分かかるため、最初に開いたDB接続は
    // wait_timeout で切断されていることがある(SQLSTATE HY000 4031)。
    // ここで必ず新しい接続を張り直してから書き込む。
    $db = shimabus_fresh_pdo();
    $db->beginTransaction();
    try {
        // 取り込みバッチ
        $db->prepare(
            'INSERT INTO src_import_batch (target_date, service_name, scope_note, stop_count, trip_count, fetched_at)
             VALUES (?,?,?,?,?,NOW())'
        )->execute([
            (new DateTime($targetDate))->format('Y-m-d'),
            $service ?: null,
            'origin:' . $origin . ($keito !== '' ? ' keito:' . $keito : ''),
            count($busstopIds),
            count($tripData),
        ]);
        $batchId = (int) $db->lastInsertId();

        // m_busstop UPSERT（標柱単位）＋ pole→busstop_id 解決
        $upBs = $db->prepare(
            'INSERT INTO m_busstop (name, direction, kana, src_stop_id, src_busstop_id, latitude, longitude)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                kana = VALUES(kana), src_busstop_id = VALUES(src_busstop_id),
                latitude = VALUES(latitude), longitude = VALUES(longitude),
                src_stop_id = VALUES(src_stop_id)'
        );
        $selBs = $db->prepare('SELECT busstop_id FROM m_busstop WHERE src_stop_id = ?');
        $poleToBusstop = [];  // pole stop_id => m_busstop.busstop_id
        foreach ($poles as $poleId => $p) {
            $upBs->execute([$p['name'], $p['dir'], $p['kana'] ?: null, $poleId, $p['busstop_id'], $p['lat'], $p['lon']]);
            $selBs->execute([$poleId]);
            $poleToBusstop[$poleId] = (int) $selBs->fetchColumn();
        }

        // src_route / src_trip / src_trip_stop / src_fare
        $selRoute = $db->prepare('SELECT src_route_id FROM src_route WHERE route_key = ?');
        $insRoute = $db->prepare('INSERT INTO src_route (route_key, route_name) VALUES (?,?)
                                  ON DUPLICATE KEY UPDATE route_name = VALUES(route_name)');
        $selTrip  = $db->prepare('SELECT src_trip_id FROM src_trip WHERE trip_key = ? AND target_date = ?');
        $insTrip  = $db->prepare(
            'INSERT INTO src_trip (trip_key, src_route_id, keito_no, service_name, headsign, origin_busstop_id, first_departure, target_date, import_batch_id)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                src_route_id = VALUES(src_route_id), keito_no = VALUES(keito_no), service_name = VALUES(service_name),
                headsign = VALUES(headsign), origin_busstop_id = VALUES(origin_busstop_id),
                first_departure = VALUES(first_departure), import_batch_id = VALUES(import_batch_id)'
        );
        $delStops = $db->prepare('DELETE FROM src_trip_stop WHERE src_trip_id = ?');
        $insStop  = $db->prepare('INSERT INTO src_trip_stop (src_trip_id, seq, busstop_id, arrival_time, departure_time) VALUES (?,?,?,?,?)');
        // matrix は全from_seqを、origin は from_seq=1 のみを置換
        $delFare  = ($fareMode === 'matrix')
            ? $db->prepare('DELETE FROM src_fare WHERE src_trip_id = ?')
            : $db->prepare('DELETE FROM src_fare WHERE src_trip_id = ? AND from_seq = 1');
        $insFare  = $db->prepare('INSERT INTO src_fare (src_trip_id, from_seq, to_seq, to_busstop_id, price) VALUES (?,?,?,?,?)');

        $tgt = (new DateTime($targetDate))->format('Y-m-d');
        $fareSkipped = 0;   // 標柱未解決などで登録できなかった運賃行
        foreach ($tripData as $tripKey => $td) {
            // route
            $insRoute->execute([$td['route_key'], $td['route_name'] ?: null]);
            $selRoute->execute([$td['route_key']]);
            $routeId = (int) $selRoute->fetchColumn();

            // origin(seq=1)の標柱→busstop_id
            $originBusstopId = null;
            $firstDep = null;
            foreach ($td['stops'] as $s) {
                if ($s['seq'] === 1) {
                    $originBusstopId = $poleToBusstop[$s['stop_id']] ?? null;
                    $firstDep = $s['dep'] ?? $s['arr'];
                    break;
                }
            }

            // trip（trip_idから系統番号を抽出: 例「…_系統3403」→3403）
            $keito = preg_match('/系統(\d+)/u', $tripKey, $mk) ? $mk[1] : null;
            $insTrip->execute([$tripKey, $routeId, $keito, $service ?: null, $td['headsign'] ?: null, $originBusstopId, $firstDep, $tgt, $batchId]);
            $selTrip->execute([$tripKey, $tgt]);
            $tripId = (int) $selTrip->fetchColumn();

            // stops（置換）
            $delStops->execute([$tripId]);
            foreach ($td['stops'] as $s) {
                $bsId = $poleToBusstop[$s['stop_id']] ?? null;
                if ($bsId === null) continue; // 標柱未解決はスキップ（通常起きない）
                $insStop->execute([$tripId, $s['seq'], $bsId, $s['arr'], $s['dep']]);
            }

            // fare（置換, from_seq=1）
            // 標柱→busstop_id が解決できない運賃行はスキップ(停留所ループと同様)。
            // 未解決のまま NULL/0 を入れると to_busstop_id の制約違反でDBエラーになる。
            $delFare->execute([$tripId]);
            foreach (($fares[$tripKey] ?? []) as $fr) {
                $bsId = $poleToBusstop[$fr['to_stop_id']] ?? null;
                if (empty($bsId)) {                                  // null / 0 はスキップ
                    if (!empty($fr['to_busstop_id'])) {              // 応答の busstop_id で代替できる場合のみ使う
                        $bsId = (int) $fr['to_busstop_id'];
                    } else {
                        $fareSkipped++; continue;
                    }
                }
                if ((int) $fr['to_seq'] <= 0) { $fareSkipped++; continue; }
                $insFare->execute([$tripId, (int) ($fr['from_seq'] ?? 1), (int) $fr['to_seq'], $bsId, (int) $fr['price']]);
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $summary['fare_skipped'] = $fareSkipped;   // 登録できなかった運賃行(標柱未解決など)
    json_ok(['mode' => 'commit', 'summary' => $summary, 'committed' => true, 'import_batch_id' => $batchId]);
}

/* ================================================================== */
/*  区間運賃（三角表）を分割して取り込む                                */
/*  action=shimabus.importFares                                        */
/*                                                                     */
/*  取込済みの src_trip / src_trip_stop を使うので、外部APIは fare.cgi  */
/*  だけを呼ぶ。停留所の並びが同じ便は運賃も同一なのでパターン単位で    */
/*  取得し、1回のリクエストで処理するパターン数を絞って 504(ゲート      */
/*  ウェイタイムアウト)を避ける。                                       */
/*                                                                     */
/*  params:                                                            */
/*    target_date(YYYYMMDD, 既定=当日)  対象スナップショット           */
/*    pattern_limit(既定 2)             1回で処理するパターン数         */
/*    pattern_offset(既定 0)            続きから再開する位置            */
/*    mode(preview|commit, 既定 commit) preview は取得のみ             */
/* ================================================================== */
function handle_shimabus_import_fares(): void
{
    require_admin();
    @set_time_limit(1800);
    @ini_set('max_execution_time', '1800');

    $targetDate = (string) param('target_date', date('Ymd'));
    if (!preg_match('/^\d{8}$/', $targetDate)) {
        json_err('bad_date', 'target_date は YYYYMMDD で指定してください。', 422);
    }
    $tgt      = (new DateTime($targetDate))->format('Y-m-d');
    $fareDate = $tgt;                                  // fare.cgi は YYYY-MM-DD
    $limit    = max(1, (int) param('pattern_limit', 2));
    $offset   = max(0, (int) param('pattern_offset', 0));
    $mode     = (string) param('mode', 'commit');

    $db = pdo();

    // 1) 対象日の便と停留所並びを取得し、パターンごとにまとめる
    $st = $db->prepare(
        'SELECT t.src_trip_id, t.trip_key, ts.seq, ts.busstop_id
           FROM src_trip t
           JOIN src_trip_stop ts ON ts.src_trip_id = t.src_trip_id
          WHERE t.target_date = ?
          ORDER BY t.src_trip_id, ts.seq'
    );
    $st->execute([$tgt]);

    $tripStops = [];   // src_trip_id => ['key'=>trip_key, 'stops'=>[seq=>busstop_id]]
    foreach ($st->fetchAll() as $r) {
        $id = (int) $r['src_trip_id'];
        if (!isset($tripStops[$id])) $tripStops[$id] = ['key' => $r['trip_key'], 'stops' => []];
        $tripStops[$id]['stops'][(int) $r['seq']] = (int) $r['busstop_id'];
    }
    if (!$tripStops) {
        json_err('no_trips', "対象日({$tgt})の便が取り込まれていません。先に shimabus.importRoute を実行してください。", 422);
    }

    // パターン = 停留所IDの並び
    $patterns = [];   // signature => ['rep_key','stops'=>[...], 'trip_ids'=>[...]]
    foreach ($tripStops as $id => $t) {
        $sig = implode(',', $t['stops']);
        if (!isset($patterns[$sig])) $patterns[$sig] = ['rep_key' => $t['key'], 'stops' => $t['stops'], 'trip_ids' => []];
        $patterns[$sig]['trip_ids'][] = $id;
    }
    $sigs      = array_keys($patterns);
    $totalPat  = count($sigs);
    $slice     = array_slice($sigs, $offset, $limit);
    $nextOffset = $offset + count($slice);

    // 2) パターンごとに運賃を取得
    $fetched = [];   // signature => [ ['from_seq','to_seq','price','to_busstop_id'], ... ]
    $errors  = [];
    $calls   = 0;
    foreach ($slice as $sig) {
        $p       = $patterns[$sig];
        $maxSeq  = max(array_keys($p['stops']));
        $rows    = [];
        for ($from = 1; $from < $maxSeq; $from++) {
            $f = shimabus_post('fare', ['trip_id' => $p['rep_key'], 'stop_sequence' => $from, 'target_date' => $fareDate]);
            $calls++;
            usleep(SHIMABUS_SLEEP_US);
            if (isset($f['ok']) && !$f['ok']) { $errors[$p['rep_key'] . '#' . $from] = (string) ($f['errmsg'] ?? 'unknown'); continue; }
            foreach (($f['fare'] ?? []) as $seqKey => $x) {
                if (!is_array($x)) continue;
                $toSeq = (int) ($x['stop_sequence'] ?? $seqKey);
                if ($toSeq <= 0) continue;
                $rows[] = [
                    'from_seq'      => $from,
                    'to_seq'        => $toSeq,
                    'price'         => (int) ($x['price'] ?? 0),
                    'to_busstop_id' => $p['stops'][$toSeq] ?? (int) ($x['busstop_id'] ?? 0),   // DB側の並びを優先
                ];
            }
        }
        $fetched[$sig] = $rows;
    }

    $fareRows = array_sum(array_map('count', $fetched));
    $tripsAffected = 0;
    foreach ($slice as $sig) $tripsAffected += count($patterns[$sig]['trip_ids']);

    $summary = [
        'target_date'    => $tgt,
        'patterns_total' => $totalPat,
        'pattern_offset' => $offset,
        'patterns_done'  => count($slice),
        'next_offset'    => $nextOffset,
        'finished'       => $nextOffset >= $totalPat,
        'trips_affected' => $tripsAffected,
        'fare_rows'      => $fareRows,
        'api_calls'      => $calls,
        'fare_errors'    => count($errors),
        'fare_error_sample' => array_slice($errors, 0, 3, true),
    ];

    if ($mode !== 'commit') {
        json_ok(['mode' => 'preview', 'summary' => $summary, 'committed' => false]);
    }

    // 3) 書込（取得に時間がかかり接続が切れているため張り直す）
    $w = shimabus_fresh_pdo();
    $w->beginTransaction();
    try {
        $del = $w->prepare('DELETE FROM src_fare WHERE src_trip_id = ?');
        $ins = $w->prepare('INSERT INTO src_fare (src_trip_id, from_seq, to_seq, to_busstop_id, price) VALUES (?,?,?,?,?)');
        $skipped = 0; $written = 0;
        foreach ($slice as $sig) {
            foreach ($patterns[$sig]['trip_ids'] as $tripId) {
                $del->execute([$tripId]);
                foreach ($fetched[$sig] as $fr) {
                    if (empty($fr['to_busstop_id'])) { $skipped++; continue; }
                    $ins->execute([$tripId, $fr['from_seq'], $fr['to_seq'], $fr['to_busstop_id'], $fr['price']]);
                    $written++;
                }
            }
        }
        $w->commit();
        $summary['rows_written'] = $written;   // 実際にDBへ入れた行数(便数ぶん展開後)
        $summary['fare_skipped'] = $skipped;
    } catch (Throwable $e) {
        $w->rollBack();
        throw $e;
    }

    json_ok(['mode' => 'commit', 'summary' => $summary, 'committed' => true]);
}

/* ================================================================== */
/*  停留所を持たない便(空データ)を削除する                              */
/*  action=shimabus.cleanupEmptyTrips                                  */
/*                                                                     */
/*  signagebase が当日ダイヤしか返さないため、別日を指定して取り込むと   */
/*  「指定日に運行しない便」が中身なしで作られることがある。その掃除用。  */
/*  params: target_date(YYYYMMDD 任意。省略時は全日付が対象)            */
/*          mode(preview|commit, 既定 preview)                          */
/* ================================================================== */
function handle_shimabus_cleanup_empty_trips(): void
{
    require_admin();
    $mode = (string) param('mode', 'preview');
    $date = (string) param('target_date', '');
    $db   = pdo();

    $where = 'NOT EXISTS (SELECT 1 FROM src_trip_stop ts WHERE ts.src_trip_id = t.src_trip_id)';
    $args  = [];
    if ($date !== '') {
        if (!preg_match('/^\d{8}$/', $date)) json_err('bad_date', 'target_date は YYYYMMDD で指定してください。', 422);
        $where .= ' AND t.target_date = ?';
        $args[] = (new DateTime($date))->format('Y-m-d');
    }

    $sel = $db->prepare("SELECT t.src_trip_id, t.trip_key, t.target_date FROM src_trip t WHERE $where ORDER BY t.src_trip_id");
    $sel->execute($args);
    $rows = $sel->fetchAll();

    $summary = [
        'target_date' => $date !== '' ? (new DateTime($date))->format('Y-m-d') : 'すべて',
        'empty_trips' => count($rows),
        'sample'      => array_slice(array_column($rows, 'trip_key'), 0, 5),
    ];
    if ($mode !== 'commit') {
        json_ok(['mode' => 'preview', 'summary' => $summary, 'deleted' => 0]);
    }

    $deleted = 0;
    $db->beginTransaction();
    try {
        $delFare = $db->prepare('DELETE FROM src_fare WHERE src_trip_id = ?');
        $delTrip = $db->prepare('DELETE FROM src_trip WHERE src_trip_id = ?');
        foreach ($rows as $r) {
            $delFare->execute([(int) $r['src_trip_id']]);
            $delTrip->execute([(int) $r['src_trip_id']]);
            $deleted++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    json_ok(['mode' => 'commit', 'summary' => $summary, 'deleted' => $deleted]);
}

/* ================================================================== */
/*  取込のカバー状況を調べ、追加で必要な起点を提案する                  */
/*  action=shimabus.coverage                                           */
/*                                                                     */
/*  ドラサポのコース(m_dia_course, 実車)が、取込済みの便(src_trip)と    */
/*  突合できるか・運賃(src_fare)を持つかを数え、足りない分の「起点」を  */
/*  多い順に返す。importRoute の origin にはここで返す src_busstop_id を*/
/*  指定する(しまバス側のID。ドラサポのbusstop_idとは別体系)。          */
/*                                                                     */
/*  params: day_type(1=平日/2=土日祝, 既定1)                            */
/*          target_date(YYYYMMDD 任意。指定時はその日の取込のみ対象)    */
/* ================================================================== */
function handle_shimabus_coverage(): void
{
    require_admin();
    $dayType = (int) param('day_type', 1);
    if ($dayType !== 1 && $dayType !== 2) json_err('bad_day_type', 'day_type は 1(平日) か 2(土日祝) を指定してください。', 422);
    $date = (string) param('target_date', '');
    $tgt  = ($date !== '' && preg_match('/^\d{8}$/', $date)) ? (new DateTime($date))->format('Y-m-d') : null;

    $db = pdo();

    // 対象コース(実車)と始発停・発時刻
    $sql = "SELECT c.dia_course_id, d.dia_no, c.seq, c.start_busstop_id,
                   TIME_FORMAT(c.departure_time,'%H:%i:%s') AS dep,
                   COALESCE(c.start_name, s.name) AS start_name,
                   b.src_busstop_id
              FROM m_dia d
              JOIN m_dia_course c ON c.dia_id = d.dia_id AND c.category = 3
              LEFT JOIN m_busstop s ON s.busstop_id = c.start_busstop_id
              LEFT JOIN m_busstop b ON b.busstop_id = c.start_busstop_id
             WHERE d.day_type = ? AND d.is_active = 1
             ORDER BY d.dia_no, c.seq";
    $st = $db->prepare($sql); $st->execute([$dayType]);
    $courses = $st->fetchAll();

    // 突合(fares.php と同じ考え方):
    //   ① 便の始発停+発時刻が一致  ② その停をその時刻に通る便(コースが便の途中から始まる場合)
    $dateCond = $tgt ? ' AND t.target_date=?' : '';
    $q = $db->prepare("SELECT t.src_trip_id, EXISTS(SELECT 1 FROM src_fare f WHERE f.src_trip_id=t.src_trip_id) AS has_fare
                         FROM src_trip t
                        WHERE t.origin_busstop_id=? AND t.first_departure=?{$dateCond}
                        ORDER BY t.target_date DESC LIMIT 1");
    $q2 = $db->prepare("SELECT t.src_trip_id, EXISTS(SELECT 1 FROM src_fare f WHERE f.src_trip_id=t.src_trip_id) AS has_fare
                          FROM src_trip_stop ts JOIN src_trip t ON t.src_trip_id = ts.src_trip_id
                         WHERE ts.busstop_id=? AND (ts.departure_time=? OR ts.arrival_time=?){$dateCond}
                         ORDER BY t.target_date DESC LIMIT 1");

    // 始発停がサイネージ無し(i=xxx等)のときに使う、同コース上の代替起点
    $altOrigin = $db->prepare(
        "SELECT b.src_busstop_id, b.name
           FROM m_dia_course_stop cs JOIN m_busstop b ON b.busstop_id = cs.busstop_id
          WHERE cs.dia_course_id = ? AND b.src_busstop_id REGEXP '^[0-9]+$'
          ORDER BY cs.seq LIMIT 1");

    $total=0; $noTrip=0; $noFare=0; $ok=0;
    $need=[];   // src_busstop_id => ['name','busstop_id','courses'=>n]
    foreach ($courses as $c) {
        $total++;
        $args = [ (int)$c['start_busstop_id'], $c['dep'] ];
        if ($tgt) $args[] = $tgt;
        $q->execute($args);
        $row = $q->fetch();
        if (!$row) {   // 便の途中から始まるコースを拾う
            $args2 = [ (int)$c['start_busstop_id'], $c['dep'], $c['dep'] ];
            if ($tgt) $args2[] = $tgt;
            $q2->execute($args2);
            $row = $q2->fetch();
        }
        $lack = false;
        if (!$row)                    { $noTrip++;  $lack = true; }
        elseif (!(int)$row['has_fare']) { $noFare++; $lack = true; }
        else                          { $ok++; }
        if ($lack) {
            // 起点に使えるのは数値のID(=サイネージがある停)だけ。始発停が "i=315" のように
            // サイネージを持たない停の場合は、同じコースの経由停から数値IDの停を代わりに使う。
            $src  = (string)($c['src_busstop_id'] ?? '');
            $name = $c['start_name'];
            $via  = false;
            if (!preg_match('/^\d+$/', $src)) {
                $alt = $altOrigin->execute([(int)$c['dia_course_id']]) ? $altOrigin->fetch() : null;
                if ($alt) { $src = (string)$alt['src_busstop_id']; $name = $alt['name']; $via = true; }
                else      { $src = ''; }
            }
            $key = $src !== '' ? $src : ('?' . $c['start_busstop_id']);
            if (!isset($need[$key])) $need[$key] = ['origin'=>$src, 'name'=>$name,
                                                    'busstop_id'=>(int)$c['start_busstop_id'],
                                                    'via'=>$via, 'courses'=>0, 'sample'=>[]];
            $need[$key]['courses']++;
            if (count($need[$key]['sample']) < 3) $need[$key]['sample'][] = 'ダイヤ'.ltrim($c['dia_no'],'0').'-'.$c['seq'];
        }
    }
    // 不足の多い順(=1回の取込で効果が大きい順)
    usort($need, static fn($a,$b) => $b['courses'] <=> $a['courses']);

    json_ok([
        'day_type'      => $dayType,
        'target_date'   => $tgt ?: 'すべて',
        'courses_total' => $total,
        'ok'            => $ok,       // 便も運賃も揃っている
        'no_trip'       => $noTrip,   // 便そのものが未取込
        'no_fare'       => $noFare,   // 便はあるが運賃が無い
        'suggest_origins' => array_values($need),   // origin= に指定する値(しまバス側ID)
    ]);
}
