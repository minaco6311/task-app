<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskRequest;
use App\Models\Category;
use App\Models\Setting;
use App\Models\Task;
use App\Services\GoogleCalendar;
use App\Services\Line\Messenger;
use App\Services\Line\SendQuota;
use App\Services\Recurrence;
use App\Services\TaskLists;
use App\Services\TaskTextParser;
use App\Support\Jp;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** 予定の追加・編集・削除 */
class TaskController extends Controller
{
    public function create(Request $request): View
    {
        $settings = Setting::values();
        $date = Jp::parseDate($request->query('date'));
        $categories = Category::ordered();

        // クイック登録（カレンダー上部の1行）：読み取った内容をフォームに入れるだけで、保存はしない
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 200));
        if ($q !== '') {
            $p = (new TaskTextParser($categories))->parseQuick($q, CarbonImmutable::now());
            $task = new Task([
                'title' => $p['title'],
                'date' => $p['date'], // 読み取れなかったら空のまま（利用者が選ぶ）
                'all_day' => false,
                'start_time' => $p['start'],
                'end_time' => $p['end'],
                'category_id' => $p['category_id'] ?? $categories->first()?->id,
                'notify_day_before' => true,
                'notify_morning' => true,
                'notify_before_minutes' => $settings['default_before'],
            ]);
            $quick = ['text' => $q, 'warnings' => $p['warnings']];

            return view('tasks.form', compact('task', 'categories', 'settings', 'quick'));
        }

        // 開始時刻：タイムラインで押した時刻 → 今日なら次の正時 → それ以外は10:00
        $start = Jp::toMin($request->query('start')) !== null
            ? Jp::fromMin(Jp::toMin($request->query('start')))
            : ($date->isToday() ? Jp::fromMin(min(CarbonImmutable::now()->hour + 1, 23) * 60) : '10:00');

        $task = new Task([
            'date' => $date,
            'all_day' => false,
            'start_time' => $start,
            'end_time' => Jp::fromMin(Jp::toMin($start) + 60),
            'category_id' => $categories->first()?->id,
            'notify_day_before' => true,
            'notify_morning' => true,
            'notify_before_minutes' => $settings['default_before'],
        ]);

        return view('tasks.form', compact('task', 'categories', 'settings'));
    }

    public function store(TaskRequest $request): RedirectResponse
    {
        $task = DB::transaction(function () use ($request) {
            $task = Task::create($request->taskData());
            $this->syncSubtasks($task, $request->subtaskData());
            if ($rule = $request->ruleData()) {
                Recurrence::start($task, $rule); // この予定を1回目にして、90日先まで作る
            }

            return $task;
        });

        return $this->afterSave($request, $task, $task->series_id ? '繰り返しの予定を追加しました' : '予定を追加しました');
    }

    public function edit(Task $task, Messenger $messenger): View
    {
        $times = ReminderController::times();

        return view('tasks.form', [
            'task' => $task->load(['subtasks', 'series', 'reminders']),
            'categories' => Category::ordered(),
            'settings' => Setting::values(),
            'snoozeTimes' => $times,
            // 月の上限を超えるおそれがあれば、スヌーズのボタンを押せなくして理由を出す
            'snoozeBlocked' => session('snooze_blocked') ?? (new SendQuota($messenger))->snoozeBlocked($times['10']),
        ]);
    }

    /**
     * 保存
     * ・繰り返しの回：「この予定だけ」（ほかの回に影響しない）／「この予定以後すべて」（繰り返しの変更もここで反映）
     * ・繰り返しでない予定：繰り返しを選んだら、この予定を1回目にして繰り返しにする
     * ・日付や開始時刻を変えたら、この予定のスヌーズの予約は取り消す（古い日時の再通知を送らない）
     */
    public function update(TaskRequest $request, Task $task): RedirectResponse|View
    {
        // 「以後すべて」で、個別に変更した回も上書きするときは、件数と内容を見せて確認してから保存する
        $overwrite = $task->series_id && $request->following() && $request->boolean('overwrite_modified');
        if ($overwrite && ! $request->boolean('confirmed') && ($mods = Recurrence::modifiedAfter($task))->isNotEmpty()) {
            return view('tasks.overwrite-confirm', [
                'task' => $task,
                'mods' => $mods,
                'data' => $request->taskData(),
                'rule' => $request->ruleData(),
                'hidden' => self::hiddenInputs($request->except(['_token', '_method', 'confirmed', 'overwrite_modified'])),
            ]);
        }

        $before = [$task->date->format('Y-m-d'), $task->start];
        $message = DB::transaction(function () use ($request, $task, $overwrite) {
            $data = $request->taskData();
            if ($task->series_id && $request->following()) {
                $task->update($data);
                $this->syncSubtasks($task, $request->subtaskData());
                $r = Recurrence::updateFollowing($task, $request->ruleData(), $overwrite);
                $notes = array_filter([
                    $r['kept'] ? "個別に変更した{$r['kept']}件はそのまま残しました" : null,
                    $r['detached'] ? "個別に変更した{$r['detached']}件は、繰り返しから外して残しました" : null,
                    $r['overwritten'] ? "個別に変更した{$r['overwritten']}件も上書きしました" : null,
                ]);

                return 'この予定以後すべてを保存しました'.($notes ? '（'.implode('。', $notes).'）' : '');
            }
            $task->update($task->series_id ? [...$data, 'series_modified' => true] : $data);
            $this->syncSubtasks($task, $request->subtaskData());
            if ($task->series_id) {
                return 'この予定だけ保存しました';
            }
            if ($rule = $request->ruleData()) {
                Recurrence::start($task, $rule);

                return '繰り返しの予定にしました';
            }

            return '保存しました';
        });
        if ($before !== [$task->date->format('Y-m-d'), $task->start]) {
            $task->reminders()->where('remind_at', '>', now())->delete();
        }

        return $this->afterSave($request, $task, $message);
    }

    /**
     * サブタスクをフォームの内容にそろえる（並び順は画面の順。消した行は削除）
     * サブタスクの完了は、予定（親）の完了には影響しない
     *
     * @param  list<array{id: ?int, title: string, done: bool}>  $items
     */
    private function syncSubtasks(Task $task, array $items): void
    {
        $existing = $task->subtasks()->get()->keyBy('id');
        $keep = [];
        foreach (array_values($items) as $i => $item) {
            $data = ['title' => $item['title'], 'done' => $item['done'], 'sort_order' => $i];
            if ($item['id'] && $existing->has($item['id'])) {
                $existing[$item['id']]->update($data);
                $keep[] = $item['id'];
            } else {
                $keep[] = $task->subtasks()->create($data)->id; // ほかの予定のIDが送られてきても新しく作る
            }
        }
        $task->subtasks()->whereNotIn('id', $keep)->delete();
    }

    /**
     * 削除（繰り返しの回は「この予定だけ」／「この予定以後すべて」）
     * 過去の回と通知履歴は残る（通知履歴の予定 ID は空になる）
     */
    public function destroy(Request $request, Task $task): RedirectResponse
    {
        $date = $task->date;
        $message = DB::transaction(function () use ($request, $task) {
            if ($task->series_id && $request->input('scope') === 'following') {
                Recurrence::deleteFollowing($task);

                return 'この予定以後すべてを削除しました';
            }
            if ($task->series_id) {
                Recurrence::deleteOnly($task); // 自動で作り直さない印を残す

                return 'この予定だけ削除しました';
            }
            $task->delete();

            return '削除しました';
        });

        return redirect()->to(self::calendarUrl($date))->with('status', $message);
    }

    /**
     * 完了／未完了を切り替える（一覧の丸を押したとき）
     * 「完了にする」「未完了に戻す」を値で受け取るので、2回押されても結果は同じ
     * 完了した予定は LINE通知の計算から外れるので、これから送る通知・送り直し待ちの通知は送られない
     */
    public function done(Request $request, Task $task): RedirectResponse|JsonResponse
    {
        $done = $request->validate(['done' => ['required', 'boolean']])['done'];
        $task->update(['done' => (bool) $done]);

        if ($request->expectsJson()) {
            // 今日の達成状況も返す（画面の進捗バーを書き換えるため）
            return response()->json(['id' => $task->id, 'done' => $task->done, 'progress' => (new TaskLists(Jp::today()))->progress()]);
        }

        return back()->with('status', $task->done ? '完了にしました' : '未完了に戻しました');
    }

    /** 期限切れを「まとめて完了」する前の確認画面（対象の件数を見せる） */
    public function confirmOverdue(Request $request): View
    {
        $all = $request->query('range') === 'all';
        $lists = new TaskLists(Jp::today());

        return view('tasks.overdue-confirm', [
            'all' => $all,
            'count' => $lists->overdueQuery($all)->count(),
            'samples' => $lists->overdueQuery($all)->with('category')->orderByDesc('date')->inDayOrder()->limit(10)->get(),
            'from' => $lists->overdueFrom(),
            'back' => route('calendar', ['view' => 'month', 'date' => $request->query('date', Jp::today()->format('Y-m-d'))] + ($all ? ['overdue' => 'all'] : [])).'#todo',
        ]);
    }

    /** 期限切れをまとめて完了にする（確認画面で見せた件数と変わっていたら、もう一度確認してもらう） */
    public function completeOverdue(Request $request): RedirectResponse
    {
        $v = $request->validate(['range' => ['required', 'in:14,all'], 'count' => ['required', 'integer', 'min:0']]);
        $all = $v['range'] === 'all';
        $q = (new TaskLists(Jp::today()))->overdueQuery($all);

        if ($q->count() !== (int) $v['count']) {
            return redirect()->route('overdue.confirm', ['range' => $v['range']])
                ->with('status', '対象の件数が変わりました。もう一度確認してください');
        }
        $n = $q->update(['done' => true, 'updated_at' => now()]);

        return redirect()->to(route('calendar', ['view' => 'month']).'#todo')->with('status', "期限切れの {$n}件を完了にしました");
    }

    /** Googleカレンダーの登録画面へ移動（登録済みの印をつける） */
    public function google(Task $task): RedirectResponse
    {
        $task->update(['gcal_added' => true]);

        return redirect()->away(GoogleCalendar::url($task));
    }

    private function afterSave(Request $request, Task $task, string $message): RedirectResponse
    {
        $res = redirect()->to(self::calendarUrl($task->date))->with('status', $message);

        // 「保存してGoogleカレンダーに登録」のときは、カレンダー画面に登録ボタンを出す
        return $request->input('action') === 'gcal' ? $res->with('gcal_task', $task->id) : $res;
    }

    /**
     * フォームの値を、もう一度送るための hidden の一覧にする（確認画面で使う）
     * 例：['subtasks' => [['title' => 'A']]] → [['subtasks[0][title]', 'A']]
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function hiddenInputs(array $input, string $prefix = ''): array
    {
        $out = [];
        foreach ($input as $k => $v) {
            $name = $prefix === '' ? (string) $k : "{$prefix}[{$k}]";
            if (is_array($v)) {
                array_push($out, ...self::hiddenInputs($v, $name));
            } elseif ($v !== null) {
                $out[] = [$name, (string) $v];
            }
        }

        return $out;
    }

    public static function calendarUrl(CarbonImmutable $date): string
    {
        return route('calendar', ['view' => session('calendar.view', 'month'), 'date' => $date->format('Y-m-d')]);
    }
}
