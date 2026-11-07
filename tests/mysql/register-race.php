<?php

/**
 * 同時登録のテスト用（tests/Feature/InvitationConcurrencyTest.php が、同時に何個も起動する）
 *   php tests/mysql/register-race.php 招待ID メールアドレス 合図のファイル
 * 合図のファイルができた瞬間に、全員がいっせいに同じ招待で登録する。結果（created / email_taken / unavailable）を1行で出す
 * テスト用 MySQL（irodori_test @ mysqltest-db）以外では何もしない
 */

use App\Models\Invitation;
use App\Models\User;
use App\Services\Registration;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$c = config('database.connections.'.config('database.default'));
if (config('database.default') !== 'mysql' || ($c['database'] ?? '') !== 'irodori_test' || ($c['host'] ?? '') !== 'mysqltest-db') {
    fwrite(STDERR, "テスト用 MySQL ではないため中止\n");
    exit(2);
}

[, $id, $email, $barrier] = $argv;
$invitation = Invitation::query()->findOrFail((int) $id);
while (! file_exists($barrier)) {
    usleep(500);
}
$res = app(Registration::class)->register($invitation, 'race', $email, 'race-password-123456');
echo $res instanceof User ? 'created' : $res;
