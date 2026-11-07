<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1人用 → 複数ユーザー：ユーザーごとのデータに持ち主（user_id）を付ける
 *
 * 対象：categories / tasks / task_series / notification_logs / line_connections（＋ settings → user_settings へコピー）
 * 子のデータ（subtasks・task_reminders → 予定、task_series_skips → 繰り返し）は親を通して持ち主が決まるので user_id を持たない
 *
 * 安全のための決まり
 * ・既存のデータの持ち主は「ユーザーがちょうど1人」のときだけ、その人に決める（user_id = 1 とは決めつけない）
 *   ユーザーが0人・2人以上なのに持ち主のないデータがあるときは、何も変えずに止まる（例外）
 * ・データは消さない（列・索引を足して、持ち主を書き込むだけ。settings テーブルも残す）
 * ・途中で止まっても、もう一度実行すれば続きから進む（終わった手順は飛ばす）
 * ・line_connections の user_id（LINE のユーザーID）は line_user_id に名前を変え、user_id をアプリのユーザーにする
 * ・down（元に戻す）は、ユーザーが2人以上いるときは止まる（1人用に戻すと、ほかの人のデータが混ざって見えるため）
 */
return new class extends Migration
{
    /** 持ち主を付けるテーブルと、作る索引 */
    private const OWNED = [
        'categories' => [['user_id', 'sort_order']],
        'tasks' => [['user_id', 'date']],
        'task_series' => [['user_id']],
        'notification_logs' => [['user_id', 'scheduled_at']],
        'line_connections' => [],
    ];

    public function up(): void
    {
        // 0) 持ち主を決める（ここではまだ何も変えない。決められなければ例外で止まる）
        $owner = $this->ownerForExistingData();

        // 1) LINE のユーザーIDの列の名前を変える（user_id をアプリのユーザーに使うため）
        if (! Schema::hasColumn('line_connections', 'line_user_id')) {
            Schema::table('line_connections', fn (Blueprint $t) => $t->renameColumn('user_id', 'line_user_id'));
        }

        foreach (self::OWNED as $table => $indexes) {
            // 2) user_id を足す（まずは空でもよい形で）
            if (! Schema::hasColumn($table, 'user_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('user_id')->nullable()->after('id'));
            }

            // 3) 既存のデータに持ち主を書き込む
            if ($owner !== null) {
                DB::table($table)->whereNull('user_id')->update(['user_id' => $owner]);
            }
            if (DB::table($table)->whereNull('user_id')->exists()) {
                throw new RuntimeException("{$table} に持ち主のないデータが残っています。何も消していません。docs/multiuser-migration.md の「止まったとき」を見てください");
            }

            // 4) 持ち主を必須にして、ユーザーとつなぐ（ユーザーを消そうとしたら止まる）
            if ($this->isNullable($table, 'user_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('user_id')->nullable(false)->change());
            }
            foreach ($indexes as $cols) {
                if (! Schema::hasIndex($table, $cols)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($cols));
                }
            }
            if (! $this->hasForeignKey($table, 'user_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->foreign('user_id')->references('id')->on('users'));
            }
        }

        // 5) 通知の二重送信防止のキーを「ユーザーごとに一意」にする（morning:2026-11-05 などは人ごとに別の通知）
        if (! Schema::hasIndex('notification_logs', ['user_id', 'dedupe_key'], 'unique')) {
            Schema::table('notification_logs', fn (Blueprint $t) => $t->unique(['user_id', 'dedupe_key']));
        }
        if (Schema::hasIndex('notification_logs', ['dedupe_key'], 'unique')) {
            Schema::table('notification_logs', fn (Blueprint $t) => $t->dropUnique(['dedupe_key']));
        }

        // 6) LINE連携はユーザーごとに1つ
        if (! Schema::hasIndex('line_connections', ['user_id'], 'unique')) {
            Schema::table('line_connections', fn (Blueprint $t) => $t->unique(['user_id']));
        }

        // 7) 1人用の設定を、持ち主の設定としてコピーする（settings テーブルは消さずに残す。すでにある設定は上書きしない）
        if ($owner !== null && Schema::hasTable('settings')) {
            foreach (DB::table('settings')->orderBy('key')->get() as $s) {
                DB::table('user_settings')->insertOrIgnore([
                    'user_id' => $owner, 'key' => $s->key, 'value' => $s->value,
                    'created_at' => $s->created_at, 'updated_at' => $s->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        // 1人用に戻すと、全員のデータが1つにまとまって見えてしまう → 2人以上のときは戻さない
        $users = DB::table('users')->count();
        $owners = collect(array_keys(self::OWNED))
            ->filter(fn ($t) => Schema::hasColumn($t, 'user_id') && ($t !== 'line_connections' || Schema::hasColumn($t, 'line_user_id')))
            ->flatMap(fn ($t) => DB::table($t)->whereNotNull('user_id')->distinct()->pluck('user_id'))
            ->unique();
        if ($users > 1 || $owners->count() > 1) {
            throw new RuntimeException("ユーザーが{$users}人いるため、1人用に戻せません（ほかの人のデータが混ざって見えてしまうため）。docs/multiuser-migration.md の「元に戻すとき」を見てください");
        }

        // 設定を settings に戻す（いまの値で上書き）
        if (Schema::hasTable('settings') && Schema::hasTable('user_settings')) {
            foreach (DB::table('user_settings')->orderBy('key')->get() as $s) {
                DB::table('settings')->updateOrInsert(['key' => $s->key], ['value' => $s->value, 'created_at' => $s->created_at, 'updated_at' => $s->updated_at]);
            }
        }

        // 持ち主の列があるテーブル（line_connections は名前を変えたあとだけ。変える前の user_id は LINE のユーザーID）
        $owned = array_filter(self::OWNED, fn ($i, $t) => Schema::hasColumn($t, 'user_id') && ($t !== 'line_connections' || Schema::hasColumn($t, 'line_user_id')), ARRAY_FILTER_USE_BOTH);

        // 先に外部キーを外す（MySQL は、外部キーが使っている索引を消せないため）
        foreach (array_keys($owned) as $table) {
            if ($this->hasForeignKey($table, 'user_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropForeign(['user_id']));
            }
        }
        if (Schema::hasIndex('line_connections', ['user_id'], 'unique')) {
            Schema::table('line_connections', fn (Blueprint $t) => $t->dropUnique(['user_id']));
        }
        if (! Schema::hasIndex('notification_logs', ['dedupe_key'], 'unique')) {
            Schema::table('notification_logs', fn (Blueprint $t) => $t->unique(['dedupe_key']));
        }
        if (Schema::hasIndex('notification_logs', ['user_id', 'dedupe_key'], 'unique')) {
            Schema::table('notification_logs', fn (Blueprint $t) => $t->dropUnique(['user_id', 'dedupe_key']));
        }

        foreach ($owned as $table => $indexes) {
            foreach ($indexes as $cols) {
                if (Schema::hasIndex($table, $cols)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($cols));
                }
            }
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('user_id'));
        }

        if (Schema::hasColumn('line_connections', 'line_user_id')) {
            Schema::table('line_connections', fn (Blueprint $t) => $t->renameColumn('line_user_id', 'user_id'));
        }
    }

    /**
     * 既存のデータの持ち主（持ち主のないデータがなければ null）
     * ユーザーがちょうど1人のときだけ決める。0人・2人以上なら例外（何も変えずに止まる）
     */
    private function ownerForExistingData(): ?int
    {
        $rows = 0;
        foreach (array_keys(self::OWNED) as $table) {
            $hasOwner = Schema::hasColumn($table, 'user_id') && ($table !== 'line_connections' || Schema::hasColumn($table, 'line_user_id'));
            $rows += $hasOwner ? DB::table($table)->whereNull('user_id')->count() : DB::table($table)->count();
        }
        if (Schema::hasTable('settings')) {
            $rows += DB::table('settings')->count();
        }
        if ($rows === 0) {
            return null; // 新しく作った DB（持ち主を決めるデータがない）
        }

        $count = DB::table('users')->count();
        if ($count !== 1) {
            throw new RuntimeException(
                "持ち主の決まっていないデータが {$rows} 件ありますが、ユーザーが {$count} 人のため、だれのデータか自動では決められません。"
                .'何も変更していません。docs/multiuser-migration.md の「止まったとき」を見てください'
            );
        }

        return (int) DB::table('users')->value('id');
    }

    private function isNullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $c) {
            if ($c['name'] === $column) {
                return (bool) $c['nullable'];
            }
        }

        return false;
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $fk) {
            if ($fk['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }
};
