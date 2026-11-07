<?php

// 確認用：PHP に届いたサーバー変数を返す（e2e/prodcheck/run.sh が、使い捨て環境の public/__prodcheck.php にだけ置く）
// 本物のリポジトリの public/ には置かない。秘密ヘッダーは値を出さず、「届いたかどうか」だけを返す
header('Content-Type: application/json');

$keys = ['HTTPS', 'REQUEST_SCHEME', 'SERVER_PORT', 'HTTP_HOST', 'SERVER_NAME', 'REMOTE_ADDR', 'REQUEST_METHOD',
    'QUERY_STRING', 'CONTENT_TYPE', 'CONTENT_LENGTH', 'HTTP_COOKIE', 'HTTP_USER_AGENT'];
$out = ['server' => array_intersect_key($_SERVER, array_flip($keys))];

// 届いてはいけないもの（名前に含む文字で探す）
$out['forbidden'] = array_values(array_filter(array_keys($_SERVER), fn ($k) => preg_match('/ORIGIN_VERIFY|FORWARDED|^HTTP_PROXY$/', $k)));

$out['files'] = array_map(fn ($f) => ['error' => $f['error'], 'size' => $f['size']], $_FILES);
$out['post_keys'] = array_keys($_POST);
$out['ini'] = ['upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size')];

echo json_encode($out);
