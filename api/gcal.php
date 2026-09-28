<?php
/**
 * Googleカレンダー連携（サービスアカウント方式）
 *
 *  ・外部ライブラリ（google/apiclient や Composer）は使わない。
 *    ロリポップの共有サーバーでも動くよう、JWTの署名は openssl_sign で自作している。
 *  ・サービスアカウントの鍵JSONは config.php で場所を指定し、Web公開領域の外に置く。
 *  ・カレンダー側で「サービスアカウントのメールアドレス」に閲覧権限を共有しておくこと。
 *
 *  流れ: 鍵JSONで署名したJWT → アクセストークン → Calendar API で終日イベント取得
 */

/** サービスアカウントのアクセストークンを取得（有効期限内は静的にキャッシュ） */
function gcal_access_token(): string
{
    static $cached = null;   // [token, expires_at]
    if ($cached !== null && $cached[1] > time() + 60) {
        return $cached[0];
    }

    $cfg = $GLOBALS['CONFIG']['gcal'] ?? [];
    $keyFile = $cfg['key_file'] ?? '';
    if ($keyFile === '' || !is_file($keyFile)) {
        throw new RuntimeException('Googleカレンダー連携の鍵ファイルが見つかりません。config.php の gcal.key_file を確認してください。');
    }
    $key = json_decode((string) file_get_contents($keyFile), true);
    if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
        throw new RuntimeException('鍵ファイルの形式が不正です（client_email / private_key が必要）。');
    }

    $now = time();
    $claim = [
        'iss'   => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/calendar.readonly',   // 読み取りのみ
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];
    $jwt = gcal_sign_jwt($claim, (string) $key['private_key']);

    $res = gcal_http_post('https://oauth2.googleapis.com/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]);
    $json = json_decode($res, true);
    if (!is_array($json) || empty($json['access_token'])) {
        throw new RuntimeException('アクセストークンを取得できませんでした: ' . mb_substr($res, 0, 200));
    }
    $cached = [$json['access_token'], $now + (int) ($json['expires_in'] ?? 3600)];
    return $cached[0];
}

/** RS256 でJWTを作成 */
function gcal_sign_jwt(array $claim, string $privateKey): string
{
    $b64 = static function (string $s): string {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');   // base64url
    };
    $segments = [
        $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES)),
        $b64(json_encode($claim, JSON_UNESCAPED_SLASHES)),
    ];
    $input = implode('.', $segments);

    $pkey = openssl_pkey_get_private($privateKey);
    if ($pkey === false) {
        throw new RuntimeException('秘密鍵を読み込めませんでした。鍵ファイルの private_key を確認してください。');
    }
    $sig = '';
    $ok = openssl_sign($input, $sig, $pkey, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        throw new RuntimeException('JWTの署名に失敗しました。');
    }
    return $input . '.' . $b64($sig);
}

/** 指定カレンダーの終日イベントを取得（[ ['date'=>Y-m-d,'summary'=>..,'colorId'=>..,'id'=>..], ... ]） */
function gcal_fetch_events(string $calendarId, string $fromDate, string $toDate): array
{
    $token = gcal_access_token();
    $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events'
         . '?' . http_build_query([
             'timeMin'      => $fromDate . 'T00:00:00+09:00',
             'timeMax'      => $toDate   . 'T23:59:59+09:00',
             'singleEvents' => 'true',      // 繰り返しを個々の予定に展開
             'orderBy'      => 'startTime',
             'maxResults'   => 2500,
             'timeZone'     => 'Asia/Tokyo',
         ]);

    $body = gcal_http_get($url, ['Authorization: Bearer ' . $token]);
    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['items'])) {
        throw new RuntimeException('カレンダーを取得できませんでした: ' . mb_substr($body, 0, 200));
    }

    $out = [];
    foreach ($json['items'] as $ev) {
        // 終日イベントのみ（start.date がある）。時刻付き(start.dateTime)の私用予定は対象外。
        if (empty($ev['start']['date'])) { continue; }
        if (($ev['status'] ?? '') === 'cancelled') { continue; }
        $out[] = [
            'date'    => substr((string) $ev['start']['date'], 0, 10),
            'summary' => trim((string) ($ev['summary'] ?? '')),
            'colorId' => (string) ($ev['colorId'] ?? ''),
            'id'      => (string) ($ev['id'] ?? ''),
        ];
    }
    return $out;
}

/**
 * カレンダーのタイトルを乗務記録の初期値に変換する。
 *   "貸切"        → 貸切
 *   "休日"/"公休" → 休日
 *   "37" 等の数字 → 路線 + ダイヤ番号(4桁ゼロ埋め)
 * 判定できない場合は null（＝シフト以外の予定とみなす）。
 */
function gcal_parse_shift(string $summary): ?array
{
    $s = trim($summary);
    // 全角数字・全角スペースを半角へ寄せてから判定
    $s = str_replace('　', ' ', $s);
    $s = strtr($s, ['０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4','５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9']);
    $s = trim($s);
    if ($s === '') { return null; }

    // UTF-8は自己同期的なので、部分一致の判定は strpos で十分（mbstring非依存にしておく）
    if (strpos($s, '貸切') !== false) {
        return ['kind' => '貸切', 'dia_no' => null];
    }
    if (strpos($s, '休日') !== false || strpos($s, '公休') !== false) {
        return ['kind' => '休日', 'dia_no' => null];
    }
    // 数字のみ（前後の記号は許容）= ダイヤ番号
    if (preg_match('/^\D*(\d{1,4})\D*$/u', $s, $m)) {
        return ['kind' => '路線', 'dia_no' => str_pad($m[1], 4, '0', STR_PAD_LEFT)];
    }
    return null;
}

/** GET（cURLが無い環境でもfile_get_contentsで動くようにフォールバック） */
function gcal_http_get(string $url, array $headers = []): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = curl_exec($ch);
        if ($body === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException('通信に失敗しました: ' . $e); }
        curl_close($ch);
        return (string) $body;
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 20, 'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) { throw new RuntimeException('通信に失敗しました（file_get_contents）。'); }
    return (string) $body;
}

/** POST（application/x-www-form-urlencoded） */
function gcal_http_post(string $url, array $form): string
{
    $payload = http_build_query($form);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = curl_exec($ch);
        if ($body === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException('通信に失敗しました: ' . $e); }
        curl_close($ch);
        return (string) $body;
    }
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $payload, 'timeout' => 20, 'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) { throw new RuntimeException('通信に失敗しました（file_get_contents）。'); }
    return (string) $body;
}
