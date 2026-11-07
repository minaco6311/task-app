<?php

/**
 * 本番用 nginx → Laravel を、CloudFront のふりをして外から叩いて確かめる（e2e/prodcheck/run.sh から使う）
 *   使い捨て環境の中（internal ネットワーク）で動く。宛先は web コンテナ（nginx）
 *   PC_DOMAIN / PC_SECRET / PC_EMAIL / PC_PASSWORD はテスト用のダミー（run.sh が作る）。値は画面に出さない
 */
$domain = getenv('PC_DOMAIN');
$secret = getenv('PC_SECRET');
$email = getenv('PC_EMAIL');
$password = getenv('PC_PASSWORD');
if (! $domain || ! $secret || ! $email || ! $password) {
    fwrite(STDERR, "PC_DOMAIN などが渡されていません\n");
    exit(1);
}

$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? '  OK  ' : '  NG  ').$name.($ok || $detail === '' ? '' : "（{$detail}）")."\n";
    $failed += $ok ? 0 : 1;
}

/** 生の HTTP リクエストを送る（Host やリクエスト行を自由に書くため）。応答なしで切断されたら status = 0 */
function raw(string $requestLine, array $headers, string $body = ''): array
{
    $fp = fsockopen('web', 80, $errno, $errstr, 5);
    if (! $fp) {
        return ['status' => -1, 'headers' => [], 'body' => "接続できない：{$errstr}"];
    }
    stream_set_timeout($fp, 15);
    $msg = $requestLine."\r\n";
    foreach ($headers as $k => $v) {
        foreach ((array) $v as $one) {
            $msg .= "{$k}: {$one}\r\n";
        }
    }
    if ($body !== '') {
        $msg .= 'Content-Length: '.strlen($body)."\r\n";
    }
    $msg .= "Connection: close\r\n\r\n".$body;
    // 大きな本文を送っている途中で nginx が 413 を返して切ることがあるので、書き込みの失敗は気にしない
    @fwrite($fp, $msg);
    $res = stream_get_contents($fp);
    fclose($fp);
    if ($res === '' || $res === false) {
        return ['status' => 0, 'headers' => [], 'body' => ''];
    }
    [$head, $resBody] = array_pad(explode("\r\n\r\n", $res, 2), 2, '');
    $lines = explode("\r\n", $head);
    preg_match('#^HTTP/\S+ (\d{3})#', array_shift($lines), $m);
    $hs = [];
    foreach ($lines as $line) {
        [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
        $hs[strtolower(trim($k))][] = trim($v);
    }

    return ['status' => (int) ($m[1] ?? -1), 'headers' => $hs, 'body' => $resBody];
}

/** CloudFront から来た通信（正しい Host と秘密ヘッダー） */
function cf(string $method, string $path, array $headers = [], string $body = ''): array
{
    global $domain, $secret;

    return raw("{$method} {$path} HTTP/1.0", ['Host' => $domain, 'X-Origin-Verify' => $secret] + $headers, $body);
}

/** Set-Cookie から「名前=値」を取り出す */
function cookies(array $res, array $jar = []): array
{
    foreach ($res['headers']['set-cookie'] ?? [] as $c) {
        [$pair] = explode(';', $c, 2);
        [$k, $v] = explode('=', $pair, 2);
        $jar[$k] = $v;
    }

    return $jar;
}

function cookieHeader(array $jar): string
{
    return implode('; ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($jar), $jar));
}

function csrf(array $res): string
{
    preg_match('/name="_token" value="([^"]+)"/', $res['body'], $m);

    return $m[1] ?? '';
}

function probe(array $res): array
{
    return json_decode($res['body'], true) ?: [];
}

echo "[prodcheck] アクセス制限\n";
check('正しい Host ＋秘密ヘッダー → /up が 200', cf('GET', '/up')['status'] === 200);
check('秘密ヘッダーなし → 403', raw('GET /up HTTP/1.0', ['Host' => $domain])['status'] === 403);
check('秘密ヘッダーが空 → 403', raw('GET /up HTTP/1.0', ['Host' => $domain, 'X-Origin-Verify' => ''])['status'] === 403);
check('秘密ヘッダーが違う → 403', raw('GET /up HTTP/1.0', ['Host' => $domain, 'X-Origin-Verify' => 'wrong-'.substr($secret, 6)])['status'] === 403);
check('秘密ヘッダーの大文字・小文字が違う → 403', raw('GET /up HTTP/1.0', ['Host' => $domain, 'X-Origin-Verify' => strtoupper($secret)])['status'] === 403);
check('秘密ヘッダーが2つ（偽物＋本物）→ 403', raw('GET /up HTTP/1.0', ['Host' => $domain, 'X-Origin-Verify' => ['fake', $secret]])['status'] === 403);
check('静的ファイルも秘密ヘッダーなし → 403', raw('GET /robots.txt HTTP/1.0', ['Host' => $domain])['status'] === 403);
check('静的ファイルは秘密ヘッダーありなら 200', cf('GET', '/robots.txt')['status'] === 200);
foreach (['evil.example', 'd111111abcdef8.cloudfront.net', '203.0.113.10', 'web', strtoupper($domain).'.evil.example'] as $host) {
    check("Host: {$host}（秘密ヘッダーあり）→ 応答せずに切断（444）", raw('GET /up HTTP/1.0', ['Host' => $host, 'X-Origin-Verify' => $secret])['status'] === 0);
}
check('Host なし → 切断（444）', raw('GET /up HTTP/1.0', ['X-Origin-Verify' => $secret])['status'] === 0);
check('リクエスト行に別のホスト（http://evil.example/）→ 切断（444）', raw('GET http://evil.example/up HTTP/1.0', ['Host' => $domain, 'X-Origin-Verify' => $secret])['status'] === 0);
check('Host の大文字・小文字の違いは同じドメインとして受ける', raw('GET /up HTTP/1.0', ['Host' => strtoupper($domain), 'X-Origin-Verify' => $secret])['status'] === 200);

echo "[prodcheck] PHP に渡る情報\n";
$spoof = [
    'X-Forwarded-For' => '192.0.2.1, 198.51.100.7',
    'X-Forwarded-Host' => 'evil.example',
    'X-Forwarded-Proto' => 'http',
    'X-Forwarded-Port' => '8080',
    'X-Forwarded-Prefix' => '/evil',
    'Forwarded' => 'for=192.0.2.2;proto=http;host=evil.example',
    'Proxy' => 'http://evil.example:3128',
    'Cookie' => 'probe=1',
    'User-Agent' => 'prodcheck',
];
$p = probe(cf('GET', '/__prodcheck.php?a=1', $spoof));
$s = $p['server'] ?? [];
check('HTTPS=on', ($s['HTTPS'] ?? '') === 'on');
check('REQUEST_SCHEME=https', ($s['REQUEST_SCHEME'] ?? '') === 'https');
check('SERVER_PORT=443', ($s['SERVER_PORT'] ?? '') === '443');
check('HTTP_HOST は本番のドメイン', ($s['HTTP_HOST'] ?? '') === $domain);
check('REMOTE_ADDR は X-Forwarded-For のいちばん右（CloudFront が書き足す閲覧者の IP）', ($s['REMOTE_ADDR'] ?? '') === '198.51.100.7', $s['REMOTE_ADDR'] ?? '');
check('REQUEST_METHOD・QUERY_STRING が届く', ($s['REQUEST_METHOD'] ?? '') === 'GET' && ($s['QUERY_STRING'] ?? '') === 'a=1');
check('Cookie・User-Agent が届く', ($s['HTTP_COOKIE'] ?? '') === 'probe=1' && ($s['HTTP_USER_AGENT'] ?? '') === 'prodcheck');
check('X-Origin-Verify・X-Forwarded-*・Forwarded・Proxy は PHP に届かない', ($p['forbidden'] ?? ['?']) === [], implode(',', $p['forbidden'] ?? ['応答なし']));
$p = probe(cf('GET', '/__prodcheck.php', ['X-Forwarded-For' => 'not-an-ip']));
check('X-Forwarded-For が IP の形でなければ、接続元の IP を使う', preg_match('/^[0-9.]+$/', $p['server']['REMOTE_ADDR'] ?? '') === 1, $p['server']['REMOTE_ADDR'] ?? '');
$p = probe(raw("GET http://{$domain}/__prodcheck.php HTTP/1.0", ['Host' => 'evil.example', 'X-Origin-Verify' => $secret]));
check('リクエスト行と Host が食い違っても、HTTP_HOST は本番のドメイン', ($p['server']['HTTP_HOST'] ?? '') === $domain);

echo "[prodcheck] アップロードの上限\n";
$multipart = function (int $bytes): array {
    $b = 'prodcheck'.bin2hex(random_bytes(8));
    $body = "--{$b}\r\nContent-Disposition: form-data; name=\"note\"\r\n\r\nx\r\n"
        ."--{$b}\r\nContent-Disposition: form-data; name=\"f\"; filename=\"backup.json\"\r\nContent-Type: application/json\r\n\r\n"
        .str_repeat('a', $bytes)."\r\n--{$b}--\r\n";

    return [['Content-Type' => "multipart/form-data; boundary={$b}"], $body];
};
[$h, $body] = $multipart(6 * 1024 * 1024);
$p = probe(cf('POST', '/__prodcheck.php', $h, $body));
check('6MB のファイル（バックアップの上限 5MB より大きい）を PHP が受け取れる', ($p['files']['f']['error'] ?? -1) === 0 && ($p['files']['f']['size'] ?? 0) === 6 * 1024 * 1024, json_encode($p['files'] ?? null));
check('Content-Type・Content-Length が届く', str_starts_with($p['server']['CONTENT_TYPE'] ?? '', 'multipart/form-data') && (int) ($p['server']['CONTENT_LENGTH'] ?? 0) === strlen($body));
check('PHP の上限は upload 8M / post 10M', ($p['ini'] ?? []) === ['upload_max_filesize' => '8M', 'post_max_size' => '10M'], json_encode($p['ini'] ?? null));
[$h, $body] = $multipart(25 * 1024 * 1024);
check('25MB は nginx が 413 で断る（client_max_body_size 20M）', cf('POST', '/__prodcheck.php', $h, $body)['status'] === 413);

echo "[prodcheck] Laravel（HTTPS の URL・Cookie・ログイン・CSRF）\n";
$page = cf('GET', '/login', ['X-Forwarded-Proto' => 'http', 'X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Port' => '8080']);
check('ログイン画面が 200', $page['status'] === 200);
check('フォームの送り先が https://（本番のドメイン）/login', str_contains($page['body'], "action=\"https://{$domain}/login\""));
check('偽の X-Forwarded-Host が URL に使われない', ! str_contains($page['body'], 'evil.example'));
$sets = $page['headers']['set-cookie'] ?? [];
check('Cookie が2つ（セッション・XSRF-TOKEN）', count($sets) === 2, (string) count($sets));
check('すべての Cookie に Secure', $sets !== [] && count(array_filter($sets, fn ($c) => preg_match('/;\s*secure(;|$)/i', $c))) === count($sets));
check('セッションの Cookie に HttpOnly と SameSite=Lax', count(array_filter($sets, fn ($c) => ! str_starts_with($c, 'XSRF-TOKEN=') && stripos($c, 'httponly') !== false && stripos($c, 'samesite=lax') !== false)) === 1);
$jar = cookies($page);
$token = csrf($page);
check('CSRF トークンがフォームにある', $token !== '');

$form = ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => cookieHeader($jar)];
$res = cf('POST', '/login', $form + ['X-Forwarded-For' => '198.51.100.20'], http_build_query(['email' => $email, 'password' => $password]));
check('トークンなしの POST → 419', $res['status'] === 419, (string) $res['status']);

$res = cf('POST', '/login', $form + ['X-Forwarded-For' => '198.51.100.20'], http_build_query(['_token' => $token, 'email' => $email, 'password' => $password]));
$loc = $res['headers']['location'][0] ?? '';
check('ログイン → 302 で https のカレンダーへ', $res['status'] === 302 && preg_match('#^https://'.preg_quote($domain, '#').'(/|$)#', $loc) === 1, "{$res['status']} {$loc}");
$jar = cookies($res, $jar);
$cal = cf('GET', '/', ['Cookie' => cookieHeader($jar)]);
check('ログイン後のカレンダーが 200', $cal['status'] === 200 && str_contains($cal['body'], 'aria-label="凡例"'), (string) $cal['status']);
$token = csrf($cal) ?: (preg_match('/name="csrf-token" content="([^"]+)"/', $cal['body'], $m) ? $m[1] : '');
// ログイン後の Cookie で送る（配列の + は左が優先なので、新しい Cookie を左に置く）
$res = cf('POST', '/logout', ['Cookie' => cookieHeader($jar)] + $form, http_build_query(['_token' => $token]));
check('ログアウト（POST・CSRF あり）→ 302 で https のログイン画面へ', $res['status'] === 302 && ($res['headers']['location'][0] ?? '') === "https://{$domain}/login", $res['status'].' '.($res['headers']['location'][0] ?? ''));

$res = cf('GET', '/no-such-page-prodcheck');
check('存在しないページは 404。エラーの詳細（APP_DEBUG）は出ない', $res['status'] === 404 && ! preg_match('/vendor\/laravel|Stack trace|Whoops/i', $res['body']));

echo "[prodcheck] ログイン回数の制限（メールアドレス＋閲覧者の IP で 1分に5回）\n";
$page = cf('GET', '/login');
$jar = cookies($page);
$token = csrf($page);
$form = ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => cookieHeader($jar)];
$attempt = fn (string $xff) => cf('POST', '/login', $form + ['X-Forwarded-For' => $xff], http_build_query(['_token' => $token, 'email' => 'ratelimit@example.test', 'password' => 'wrong-password']))['status'];
$codes = [];
for ($i = 1; $i <= 6; $i++) {
    // 左側（利用者が書ける部分）を毎回変えても、いちばん右（CloudFront が書く部分）が同じなら同じ人として数える
    $codes[] = $attempt("10.0.0.{$i}, 198.51.100.30");
}
check('5回まで受け付け、6回目は 429（X-Forwarded-For の左側を変えてもすり抜けない）', array_slice($codes, 0, 5) === [302, 302, 302, 302, 302] && $codes[5] === 429, implode(',', $codes));
check('別の閲覧者（いちばん右の IP が違う）は制限されない', $attempt('10.0.0.1, 198.51.100.31') === 302);

echo $failed === 0 ? "[prodcheck] HTTP の確認：すべて OK\n" : "[prodcheck] HTTP の確認：{$failed} 件 NG\n";
exit($failed === 0 ? 0 : 1);
