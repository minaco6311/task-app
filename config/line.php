<?php

/*
 * LINE通知の設定
 * チャネルアクセストークンとユーザーIDは .env ではなく、アプリの設定画面から登録する（暗号化してDBに保存）
 */
return [

    // 送り方：log = LINEには送らず、ログと通知履歴に「シミュレーション」として残す（開発用・標準）
    //         line = LINE Messaging API で実際に送る（本番用）
    'driver' => env('LINE_DRIVER', 'log') === 'line' ? 'line' : 'log',

    // 時刻を過ぎた通知を、何分後まで送るか（サーバーが少し止まっていたとき用）。これより遅れたものは見送る
    'grace_minutes' => max(1, (int) env('LINE_GRACE_MINUTES', 30)),

    // 見送りを記録するために、どこまでさかのぼって確認するか（時間）
    'lookback_hours' => 6,

    // 1つの通知を送る回数の上限（最初の1回を含む）。2回目以降は 1分 → 2分 → 4分 あけて送り直す
    'max_attempts' => 4,

    // 送信中のまま止まった通知（処理が途中で落ちたなど）を、何分後に送り直すか
    'stale_pending_minutes' => 5,

    // 通知履歴を残す日数
    'keep_logs_days' => 90,

    // ----- 再通知 -----
    // 完了まで再通知：開始時刻から interval 分おき、1日 per_day 回・合計 total 回まで。quiet_from 時〜翌 quiet_until 時は送らない
    'nag' => [
        'interval' => 60,
        'per_day' => 3,
        'total' => 5,
        'quiet_from' => 22,
        'quiet_until' => 7,
    ],
    // スヌーズの「翌日に再通知」の時刻（日本時間）
    'snooze_tomorrow_at' => '08:00',
    // 1つの予定に予約できるスヌーズの数
    'snooze_max' => 5,

    // ----- 月の送信数（LINE公式アカウントのメッセージ数） -----
    // 月の上限。LINE から上限を取れたらそちらを使い、取れないときにこの値を使う。0 = 上限なし
    // 料金プランを変えたら .env の LINE_MONTHLY_LIMIT を変える（無料プランは200通）
    'monthly_limit' => (int) env('LINE_MONTHLY_LIMIT', 200),
    // 上限の手前の余裕。今月の送信数が「上限 − 余裕」（無料プランなら180通）に達したら、
    // 自動の再通知（完了まで再通知）を止める。通常の通知は続ける
    'reminder_margin' => max(0, (int) env('LINE_REMINDER_MARGIN', 20)),

    'api_base' => 'https://api.line.me',
    'timeout' => 10,

];
