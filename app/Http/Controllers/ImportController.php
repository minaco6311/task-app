<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Setting;
use App\Models\Task;
use App\Services\TaskTextParser;
use App\Support\Jp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 手帳から取り込み
 * ① スクショの文字をコピーして貼り付け → ② 候補を直す → ③ 登録（Googleカレンダーにも入れられる）
 * 画像はブラウザで見るだけで、サーバーには送らない
 */
class ImportController extends Controller
{
    public function create(Request $request): View
    {
        return view('import.create', ['base' => Jp::parseDate($request->query('date'))]);
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $v = $request->validate(
            ['text' => ['required', 'string', 'max:5000'], 'base' => ['nullable', 'date_format:Y-m-d']],
            ['text.required' => '手帳の文字を貼り付けてください', 'text.max' => '文字が多すぎます（5000文字まで）'],
        );
        $base = Jp::parseDate($v['base'] ?? null);
        $categories = Category::ordered();
        $cands = (new TaskTextParser($categories))->parse($v['text'], $base);

        if (! $cands) {
            return back()->withInput()->withErrors(['text' => '予定が見つかりませんでした。1行に1つの予定になるように貼り付けてみてください。']);
        }

        return view('import.review', compact('cands', 'categories', 'base'));
    }

    public function store(Request $request): RedirectResponse
    {
        $settings = Setting::values();
        $catIds = Category::query()->pluck('id')->all();
        $ids = [];

        // 入力が崩れている候補は飛ばす（全体をエラーにしない）
        foreach ((array) $request->input('items', []) as $item) {
            $title = mb_substr(trim((string) ($item['title'] ?? '')), 0, 80);
            $date = (string) ($item['date'] ?? '');
            if (empty($item['on']) || $title === '' || Jp::parseDate($date)->format('Y-m-d') !== $date) {
                continue;
            }
            $start = Jp::toMin($item['start'] ?? null) === null ? null : Jp::fromMin(Jp::toMin($item['start']));
            $end = Jp::toMin($item['end'] ?? null) === null ? null : Jp::fromMin(Jp::toMin($item['end']));
            $catId = in_array((int) ($item['category_id'] ?? 0), $catIds, true) ? (int) $item['category_id'] : ($catIds[0] ?? null);

            $ids[] = Task::create(Task::normalizeTimes([
                'title' => $title,
                'date' => $date,
                'all_day' => $start === null,
                'start_time' => $start,
                'end_time' => $end,
                'category_id' => $catId,
                'notify_day_before' => true,
                'notify_morning' => true,
                'notify_before_minutes' => $settings['default_before'],
            ]))->id;
        }

        if (! $ids) {
            return redirect()->route('import.create')->with('status', '登録する予定が選ばれていませんでした');
        }

        return redirect()->route('import.done', ['ids' => implode(',', $ids)]);
    }

    public function done(Request $request): View
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));
        $tasks = Task::query()->with('category')->whereIn('id', $ids)->orderBy('date')->inDayOrder()->get();

        return view('import.done', compact('tasks'));
    }
}
