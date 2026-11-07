<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\LineConnection;
use App\Models\NotificationLog;
use App\Models\Setting;
use App\Services\Line\Messenger;
use App\Services\Line\SendQuota;
use App\Services\NotificationDispatcher;
use App\Services\NotificationPlanner;
use App\Support\Jp;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** 設定（凡例・通知のタイミング・LINE連携の状態・通知の見本と履歴・データ・アカウント） */
class SettingController extends Controller
{
    public function edit(Messenger $messenger): View
    {
        $settings = Setting::values();
        $lastRun = Cache::get(NotificationDispatcher::LAST_RUN_KEY);
        $quota = new SendQuota($messenger);
        $official = $quota->official(); // 実際に送る設定のときだけ LINE に問い合わせる（10分間覚えておく）
        $live = $messenger->isLive();

        return view('settings.edit', [
            'settings' => $settings,
            'categories' => Category::ordered()->loadCount('tasks'),
            'notifications' => (new NotificationPlanner($settings))->upcoming(7),
            'line' => LineConnection::current(),
            'live' => $messenger->isLive(),
            'logs' => NotificationLog::query()->orderByDesc('scheduled_at')->orderByDesc('id')->limit(20)->get(),
            'lastRun' => $lastRun ? CarbonImmutable::parse($lastRun) : null,
            'sends' => [
                'app' => $quota->appCount(),
                'simulated' => $quota->simulatedCount(),
                'official' => $official['used'] ?? null,
                'limit' => $quota->limit(),           // null = 上限なし
                'stopAt' => $quota->stopAt(),         // null = 止めない
                'stopped' => $quota->remindersStopped(),
                'reached' => $quota->limitReached(),
                'unknown' => $live && $official === null, // 実際に送る設定なのに LINE の数を取れない
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'day_before_time' => ['required', 'date_format:H:i'],
            'morning_time' => ['required', 'date_format:H:i'],
            'default_before' => ['required', 'integer', Rule::in(array_keys(Jp::BEFORE_OPTIONS))],
        ]);
        foreach ($v as $k => $value) {
            Setting::put($k, $value);
        }

        return redirect()->route('settings.edit')->with('status', '通知のタイミングを保存しました');
    }

    /** カレンダーの表示（月表示に予定名を出すか） */
    public function updateDisplay(Request $request): RedirectResponse
    {
        Setting::put('month_show_titles', $request->boolean('month_show_titles') ? 1 : 0);

        return redirect()->to(route('settings.edit').'#display')->with('status', 'カレンダーの表示を保存しました');
    }
}
