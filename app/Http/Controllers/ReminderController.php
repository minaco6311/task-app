<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskReminder;
use App\Services\Line\Messenger;
use App\Services\Line\SendQuota;
use App\Support\Jp;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * スヌーズ（あとで再通知）：アプリの予定の画面から「10分後」「1時間後」「翌日」を予約する
 * ・翌日は、翌朝 8:00（日本時間）
 * ・送る時刻が夜間（22時〜7時）になるときは、確認してから予約する（night_ok=1）
 * ・月の上限を超えるおそれがあるときは予約しない（理由を画面に出す）
 * ・予定を完了・削除したら送らない（通知の計算で除く）。日付や開始時刻を変えたら予約は取り消す
 * ・将来 LINE のボタンから予約できるようにするときも、ここ（store）を使う想定
 */
class ReminderController extends Controller
{
    public const OPTIONS = ['10' => '10分後', '60' => '1時間後', 'tomorrow' => '翌日'];

    /** それぞれの選択肢で送る時刻 @return array<string, CarbonImmutable> */
    public static function times(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->startOfMinute();

        return [
            '10' => $now->addMinutes(10),
            '60' => $now->addHour(),
            'tomorrow' => Jp::at($now->addDay(), config('line.snooze_tomorrow_at')),
        ];
    }

    /** 夜間（22時〜翌7時）か */
    public static function isNight(CarbonImmutable $at): bool
    {
        return $at->hour >= config('line.nag.quiet_from') || $at->hour < config('line.nag.quiet_until');
    }

    public function store(Request $request, Task $task, Messenger $messenger): RedirectResponse
    {
        $when = $request->validate(['when' => ['required', 'in:'.implode(',', array_keys(self::OPTIONS))]])['when'];
        if ($task->done) {
            return back()->with('status', '完了した予定には再通知を予約できません');
        }
        if ($task->reminders()->where('remind_at', '>', now())->count() >= config('line.snooze_max')) {
            return back()->with('status', '再通知の予約は1つの予定に'.config('line.snooze_max').'件までです');
        }

        $at = self::times()[$when];
        if ($why = (new SendQuota($messenger))->snoozeBlocked($at)) {
            return back()->with('status', '再通知を予約できませんでした')->with('snooze_blocked', $why);
        }
        if (self::isNight($at) && ! $request->boolean('night_ok')) {
            // 夜間の再通知：まだ予約せず、画面で確認してもらう
            return back()->with('snooze_night', ['when' => $when, 'at' => Jp::dateTimeLabel($at)]);
        }
        TaskReminder::query()->firstOrCreate(['task_id' => $task->id, 'remind_at' => $at]);

        return back()->with('status', Jp::dateTimeLabel($at).' に再通知します'.(self::isNight($at) ? '（夜間）' : ''));
    }

    public function destroy(TaskReminder $reminder): RedirectResponse
    {
        $reminder->delete();

        return back()->with('status', '再通知の予約を取り消しました');
    }
}
