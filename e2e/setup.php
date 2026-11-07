<?php

/**
 * ブラウザテスト用の DB を作り直して、ログインユーザーと予定を入れる（docker-compose.e2e.yml から呼ぶ）
 *
 * 安全のため、最初に e2e/check.php で確認し、1つでも合わなければ何もせずに止まる
 * （APP_ENV=e2e・SQLite・e2e/.runtime/ の中・テスト専用の .env・LINE は log など。開発用の MySQL には触れない）
 *
 * ログイン用のパスワードは毎回ランダムに作り、e2e/.runtime/{シナリオ}.json（Git 対象外）にだけ保存する
 * 複数ユーザーの確認用に、もう1人（other）と、招待リンクのトークン（画面の幅ごと・同時登録用）も作る（day だけ）
 */

use App\Models\Category;
use App\Models\Invitation;
use App\Models\NotificationLog;
use App\Models\Task;
use App\Models\User;
use App\Support\Owner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

// まず安全確認（合わなければ、ここで終了コード 1 で止まる。DB にはまだ触れていない）
require __DIR__.'/check.php';

$runtime = $root.'/e2e/.runtime';
$db = (string) getenv('DB_DATABASE');

// 使い捨ての DB を作り直す（e2e/.runtime/ の中のテスト専用ファイル。開発用の MySQL とは別物）
@unlink($db);
touch($db);

$kernel = $app->make(Kernel::class);
$kernel->call('migrate', ['--force' => true]);

$password = Str::password(24, symbols: false);
$email = "e2e-{$scenario}@example.test";
$user = User::query()->create(['name' => 'E2E', 'email' => $email, 'password' => $password]);
$login = ['email' => $email, 'password' => $password];

if ($scenario === 'day') {
    // もう1人のユーザー（自分の予定だけが見えるか・ほかの人の予定を開けないかを確かめる）
    $otherPassword = Str::password(24, symbols: false);
    $other = User::query()->create(['name' => 'E2E もう1人', 'email' => 'e2e-other@example.test', 'password' => $otherPassword]);
    $login['other'] = ['email' => 'e2e-other@example.test', 'password' => $otherPassword];
    $login['other_task_id'] = Owner::run($other, function () {
        Category::createDefaults();

        return Task::create(Task::normalizeTimes([
            'title' => 'もう1人の秘密の予定', 'date' => '2026-11-05', 'all_day' => false, 'start_time' => '15:00',
            'category_id' => Category::query()->where('key', 'private')->value('id'),
        ]))->id;
    });
    // 招待リンクのトークン（PC 幅・スマホ幅・同時登録で1つずつ。1回しか使えないため）
    foreach (['pc', 'phone', 'race'] as $key) {
        $token = Invitation::newToken();
        (new Invitation)->forceFill([
            'email' => "e2e-invited-{$key}@example.test",
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addDays(Invitation::VALID_DAYS),
        ])->save();
        $login['invites'][$key] = ['email' => "e2e-invited-{$key}@example.test", 'token' => $token];
    }
    // 期限切れの招待
    $expired = Invitation::newToken();
    (new Invitation)->forceFill(['email' => 'e2e-expired@example.test', 'token_hash' => Invitation::hashToken($expired), 'expires_at' => now()->subMinute()])->save();
    $login['expired_token'] = $expired;
}
file_put_contents("{$runtime}/{$scenario}.json", json_encode($login));

// ここから下は、ログインするユーザー（e2e-{シナリオ}）のデータ
Owner::run($user, function () use ($scenario) {
    Category::createDefaults();

    $task = fn (string $title, string $date, ?string $start) => Task::create(Task::normalizeTimes([
        'title' => $title, 'date' => $date, 'all_day' => $start === null, 'start_time' => $start,
        'category_id' => Category::query()->where('key', 'errand')->value('id'),
        'notify_day_before' => true, 'notify_morning' => true, 'notify_before_minutes' => 60,
    ]));

    if ($scenario === 'limit') {
        // 今月すでに200通送った状態（上限 200通）
        $task('歯医者（上限）', '2026-11-05', '14:00');
        foreach (range(1, 200) as $i) {
            NotificationLog::query()->create([
                'dedupe_key' => "e2e-sent:{$i}", 'type' => 'before', 'scheduled_at' => '2026-11-02 10:00',
                'status' => 'sent', 'sent_at' => '2026-11-02 10:00', 'message' => 'e2e',
            ]);
        }
    }

});

fwrite(STDOUT, "[e2e/setup] {$scenario}: 準備できました（".now()->format('Y-m-d H:i')."）\n");
