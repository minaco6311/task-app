<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\NotificationLog;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskSeries;
use App\Services\Backup;
use App\Services\GoogleCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** データの書き出し・戻し・削除 */
class DataController extends Controller
{
    /** .ics で書き出す（ids を指定するとその予定だけ） */
    public function ics(Request $request): Response
    {
        $q = Task::query()->with('category')->orderBy('date')->inDayOrder();
        if ($request->filled('ids')) {
            $q->whereIn('id', array_filter(array_map('intval', explode(',', (string) $request->query('ids')))));
        }

        return response(GoogleCalendar::ics($q->get()), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="irodori-techo-'.now()->format('Ymd').'.ics"',
        ]);
    }

    public function json(): Response
    {
        return response()->json(Backup::export(), 200, [
            'Content-Disposition' => 'attachment; filename="irodori-techo-backup-'.now()->format('Ymd').'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function restore(Request $request): RedirectResponse
    {
        $request->validate(
            ['backup' => ['required', 'file', 'max:5120']],
            ['backup.required' => 'バックアップのファイルを選んでください'],
        );

        try {
            $data = json_decode($request->file('backup')->get(), true, 64, JSON_THROW_ON_ERROR);
            $n = Backup::restore(is_array($data) ? $data : []);
        } catch (Throwable) {
            return back()->with('status', 'このファイルは読み込めませんでした');
        }

        return redirect()->route('calendar')->with('status', "バックアップから {$n}件を戻しました");
    }

    /**
     * 利用データ（予定・カテゴリ・通知の時刻・通知履歴）を消す
     * ログイン情報（users）とLINE連携（line_connections）は残す
     */
    public function reset(): RedirectResponse
    {
        DB::transaction(function () {
            NotificationLog::query()->delete();
            Task::query()->delete();            // チェック項目・スヌーズの予約も一緒に消える
            TaskSeries::query()->delete();      // 繰り返しの設定（削除した回の印も一緒に消える）
            Category::query()->delete();
            Setting::query()->delete();
            Category::createDefaults();
            Setting::markNotificationsResumed();
        });

        return redirect()->route('calendar')->with('status', '予定などのデータを削除しました（ログイン情報とLINE連携は残っています）');
    }
}
