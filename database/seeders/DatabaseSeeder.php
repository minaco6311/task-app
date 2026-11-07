<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use App\Support\Jp;
use App\Support\Owner;
use Illuminate\Database\Seeder;

/**
 * 初期データ：凡例（6カテゴリ）と、使い方がわかるサンプルの予定
 * php artisan db:seed（ユーザーが1人のとき、その人の分に入れる）
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // データはユーザーごと。ログイン中（demo:export）ならその人、そうでなければユーザーが1人のときだけその人に入れる
        if (! Owner::check()) {
            $users = User::query()->count();
            if ($users !== 1) {
                $this->command?->warn("サンプルを入れる人を決められません（ユーザーが {$users} 人）。先に php artisan user:create で1人作ってください");

                return;
            }
            Owner::run(User::query()->firstOrFail(), fn () => $this->run());

            return;
        }

        if (! Category::query()->exists()) {
            Category::createDefaults();
        }
        $cat = Category::query()->pluck('id', 'key');
        $today = Jp::today();

        $samples = [
            [-2, '英語の勉強', 'study', '20:00', '21:00', ['done' => true]],
            [0, 'チーム定例', 'work', '10:00', '11:00'],
            [0, 'ジム', 'health', '19:00', '20:00', ['notify_day_before' => false, 'notify_before_minutes' => 30]],
            [1, '歯医者', 'health', '14:00', '15:00'],
            [1, '資料の提出', 'work', null, null],
            [2, '燃えないゴミ', 'errand', null, null, ['notify_morning' => false]],
            [3, '友だちとランチ', 'private', '12:00', '13:30'],
            [5, '子どもの参観日', 'family', '10:30', '12:00'],
            [5, '区役所で手続き', 'errand', '14:00', '14:30', ['notify_before_minutes' => 15]],
            [8, '企画会議', 'work', '15:00', '16:30'],
        ];

        foreach ($samples as $s) {
            [$off, $title, $key, $start, $end] = $s;
            Task::create([
                'title' => $title,
                'date' => $today->addDays($off),
                'all_day' => $start === null,
                'start_time' => $start,
                'end_time' => $end,
                'category_id' => $cat[$key] ?? null,
                ...($s[5] ?? []),
            ]);
        }
    }
}
