<?php

namespace App\Http\Controllers;

use App\Models\Subtask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * サブタスクの完了／未完了（一覧のチェック項目を押したとき）
 * ・すべて完了しても、予定（親）は自動で完了にしない。「このタスクも完了にしますか？」と聞くだけ
 * ・サブタスクの完了だけでは LINE通知は止まらない（通知は予定の完了だけを見る）
 */
class SubtaskController extends Controller
{
    public function done(Request $request, Subtask $subtask): RedirectResponse|JsonResponse
    {
        $done = (bool) $request->validate(['done' => ['required', 'boolean']])['done'];
        $subtask->update(['done' => $done]);

        $task = $subtask->task;
        $list = $task->subtasks()->get();
        $doneCount = $list->where('done', true)->count();
        // 親がまだ未完了で、いま最後の1つを完了にしたときだけ聞く
        $ask = $done && ! $task->done && $doneCount === $list->count();

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $subtask->id,
                'done' => $subtask->done,
                'task_id' => $task->id,
                'done_count' => $doneCount,
                'total' => $list->count(),
                'ask_complete' => $ask,
            ]);
        }

        $res = back()->with('status', $done ? '「'.$subtask->title.'」を完了にしました' : '「'.$subtask->title.'」を未完了に戻しました');

        return $ask ? $res->with('ask_complete', $task->id) : $res;
    }
}
