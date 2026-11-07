# いろどり手帳

カレンダー形式のタスク管理アプリ。手帳のスクショの文字 → 予定にして登録、Googleカレンダーへの登録、LINE通知（前日・当日朝・開始前）。

- 表示：月・週・日・一覧（今日から7日間）
- 予定の左の丸を押すと完了／未完了を切り替えられる。月表示の下に「今日やること」と「期限切れ（未完了）」
- 月表示に予定名を出すかは「設定 → カレンダーの表示」で切り替え（初期は色の線だけ）
- 予定ごとに優先度（高・中・低）とチェック項目（サブタスク、20個まで）。月表示に今日の達成状況と進捗バー
- カレンダー上部のクイック登録：「明日10時 歯医者」のように1行で書くと、予定のフォームに入る（保存は確認してから）
- 繰り返し予定（毎日・毎週（曜日を複数）・平日・毎月。90日先まで作り、毎日補充）。変更・削除は「この予定だけ」「この予定以後すべて」
- 再通知：予定の画面から「10分後・1時間後・翌朝8時」のスヌーズ、予定ごとの「完了まで再通知」（1時間おき・1日3回・合計5回・22時〜7時は送らない）。今月のLINE送信数が180通に達したら完了まで再通知を止める

> このリポジトリは、教材として **コードとファイル構造を見てもらうための公開用のコピー** です。
> 本番の URL・作業メモ・手順書（docs/）は入れていません。パスワードやトークンなどの秘密の値も入っていません。

## 技術構成

| 項目 | 方針 |
| --- | --- |
| フレームワーク | Laravel 13（PHP 8.4） |
| アプリの構成 | MVC |
| 画面 | Blade ＋ Tailwind CSS v4（Vite） |
| 開発環境 | Docker Compose（nginx / PHP-FPM / MySQL / スケジューラー / Node） |
| データベース | MySQL 8.4 |
| DBの確認・管理 | Sequel Ace |
| LINE通知 | LINE Messaging API ＋ Laravel Scheduler |
| 本番の構成 | AWS EC2 ＋ CloudFront（HTTPS）を想定（`docker-compose.prod.yml`・`docker/nginx/prod.conf.template`） |

PC に PHP や Composer を入れる必要はありません。すべて Docker の中で動きます。

## はじめて動かすとき

```sh
cp .env.example .env                                   # 設定ファイル
docker compose up -d --build                           # サーバー・MySQL・スケジューラーを起動
docker compose exec app composer install               # PHP の部品
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed     # テーブル作成＋サンプルの予定
docker compose exec app php artisan user:create        # 最初のユーザー（自分）を作る（下の「ログイン」）
docker compose run --rm node npm install               # 画面の部品
docker compose run --rm node npm run build             # CSS / JS をビルド
```

ブラウザで http://localhost:8080 を開き、作ったメールアドレスとパスワードでログインする。

## ふだんの開発

```sh
docker compose up -d                          # 起動
docker compose --profile dev up -d node       # 画面を直すとき：CSS/JS の変更がすぐ反映される（Vite）
docker compose exec app php artisan test      # テスト
docker compose down                           # 停止（データは残る）
```

`npm run dev`（Vite）を止めたあと画面が崩れる場合は、`public/hot` を消すか `npm run build` を実行する。

## ログイン（招待制・複数ユーザー）

自由な新規登録はありません。最初の1人は `php artisan user:create` で作り、2人目からは管理者が招待リンクを発行します。予定・カテゴリ・設定・通知・LINE連携は人ごとに分かれていて、ほかの人のデータは見えません。

```sh
docker compose exec app php artisan user:create                          # 最初の1人（ユーザーが0人のときだけ）
docker compose exec app php artisan invite:create --email=相手のアドレス   # 招待リンク（1回だけ・7日間）
docker compose exec app php artisan invite:list                          # 招待の一覧
docker compose exec app php artisan invite:revoke ID                     # 未使用の招待を無効にする
docker compose exec app php artisan user:list                            # ユーザーの一覧
docker compose exec app php artisan user:password --user=アドレス         # パスワードを変える（管理者）
```

- パスワードは12文字以上。ハッシュ化して保存します。ログインの試行は1分に5回まで
- ログインした人は「設定 → パスワードを変える」で自分のパスワードを変えられます

## LINE通知

- 前日 23:00・当日 8:00・開始の60分前に、LINE Messaging API で通知します（スケジューラーが毎分確認）
- 使う人が「設定 → LINE連携」で、自分の LINE公式アカウントのチャネルアクセストークンとユーザーIDを登録します（暗号化して保存）
- `.env` の `LINE_DRIVER=log`（開発用の標準）なら、LINE には送らず「シミュレーション」として通知履歴に残します。`line` で実際に送ります

## テスト

```sh
docker compose exec app php artisan test     # PHP のテスト（SQLite・メモリ上）
sh tests/mysql/run.sh                        # 同じテストを使い捨ての MySQL 8.4 で
sh e2e/run.sh                                # ブラウザテスト（Playwright。PC 幅とスマホ幅）
```

テストは開発用・本番の MySQL では動かないようにしてあります（`tests/TestCase.php`）。

## Sequel Ace で中身を見る

| 項目 | 値 |
| --- | --- |
| Host | 127.0.0.1 |
| Username | `.env` の `DB_USERNAME` |
| Password | `.env` の `DB_PASSWORD` |
| Database | `.env` の `DB_DATABASE` |
| Port | 3306 |

主なテーブル：`tasks`（予定。優先度 `priority` 1=高・2=中・3=低）、`subtasks`（チェック項目）、`task_series`（繰り返しのルール）、`task_series_skips`（繰り返しで消した回）、`task_reminders`（スヌーズの予約）、`categories`（凡例の色とカテゴリ）、`settings`（通知の時刻など）、`users`（ログイン）、`line_connections`（LINE連携。暗号化）、`notification_logs`（通知履歴）

## フォルダ

- `app/Models` … Task / Subtask / TaskSeries / TaskSeriesSkip / TaskReminder / Category / Setting / User / LineConnection / NotificationLog
- `app/Http/Controllers` … カレンダー、予定、手帳から取り込み、設定、カテゴリ、データ、LINE連携、ログイン
- `app/Services` … 文字の読み取り（TaskTextParser）、Googleカレンダー、通知の計算（NotificationPlanner）、通知の送信（NotificationDispatcher）、LINEへの送信（Line/）、タイムラインの配置、バックアップ
- `app/Console/Commands` … 通知の送信、ユーザーの作成・パスワード変更、デモの書き出し
- `resources/views` … Blade の画面
- `docker/` … nginx と PHP の設定（開発用と本番用）
- `deploy/` … 本番 MySQL のバックアップ・復元のスクリプトと、毎日動かす systemd の設定
- `prototype/` … 最初に作った、ブラウザだけで動く試作版（見本として残している）
- `tests/` … PHP のテスト（`tests/mysql/` は使い捨ての MySQL で動かすための設定）
- `e2e/` … ブラウザテスト（Playwright）
